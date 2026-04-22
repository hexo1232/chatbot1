<?php
// ============================================================
//  API_UPLOAD.PHP — Recebe ficheiro, extrai texto, cria fragmentos
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

session_start();

if (empty($_SESSION['admin_autenticado'])) {
    respostaJson(false, null, 'Não autorizado.');
}

$pdo          = obterConexao();
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';

// ------------------------------------------------------------
// Acções JSON (reprocessar / eliminar)
// ------------------------------------------------------------
if (str_contains($content_type, 'application/json')) {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $acao  = $corpo['acao'] ?? '';
    $id    = trim($corpo['id'] ?? '');

    if ($id === '') respostaJson(false, null, 'ID inválido.');

    if ($acao === 'eliminar') {
        // Elimina ficheiro físico primeiro
        $stmt = $pdo->prepare("SELECT caminho_ficheiro FROM documentos WHERE id_documento = :id AND id_configuracao_bot = :bot");
        $stmt->execute([':id' => $id, ':bot' => BOT_ID]);
        $doc = $stmt->fetch();
        if ($doc && file_exists($doc['caminho_ficheiro'])) {
            unlink($doc['caminho_ficheiro']);
        }
        $stmt = $pdo->prepare("DELETE FROM documentos WHERE id_documento = :id AND id_configuracao_bot = :bot");
        $stmt->execute([':id' => $id, ':bot' => BOT_ID]);
        respostaJson($stmt->rowCount() > 0, null, $stmt->rowCount() === 0 ? 'Documento não encontrado.' : '');
    }

    if ($acao === 'reprocessar') {
        $stmt = $pdo->prepare("SELECT * FROM documentos WHERE id_documento = :id AND id_configuracao_bot = :bot");
        $stmt->execute([':id' => $id, ':bot' => BOT_ID]);
        $doc = $stmt->fetch();
        if (!$doc) respostaJson(false, null, 'Documento não encontrado.');

        // Apaga fragmentos antigos
        $pdo->prepare("DELETE FROM fragmentos_documento WHERE id_documento = :id")->execute([':id' => $id]);

        // Extrai e fragmenta novamente
        $texto = extrairTexto($doc['caminho_ficheiro'], $doc['tipo_mime']);
        if ($texto === '') {
            $pdo->prepare("UPDATE documentos SET estado='erro', mensagem_erro='Não foi possível extrair texto.' WHERE id_documento=:id")->execute([':id'=>$id]);
            respostaJson(false, null, 'Não foi possível extrair texto do documento.');
        }

        criarFragmentos($pdo, $id, $texto);
        $pdo->prepare("UPDATE documentos SET estado='pronto', processado_em=NOW(), mensagem_erro=NULL WHERE id_documento=:id")->execute([':id'=>$id]);
        respostaJson(true, null, '');
    }

    respostaJson(false, null, 'Acção desconhecida.');
}

// ------------------------------------------------------------
// Upload de novo ficheiro (multipart/form-data)
// ------------------------------------------------------------
if (!isset($_FILES['ficheiro']) || $_FILES['ficheiro']['error'] !== UPLOAD_ERR_OK) {
    $erros = [
        UPLOAD_ERR_INI_SIZE   => 'Ficheiro excede o limite do servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'Ficheiro excede o limite do formulário.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum ficheiro enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária em falta.',
        UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever ficheiro.',
    ];
    $codigo = $_FILES['ficheiro']['error'] ?? UPLOAD_ERR_NO_FILE;
    respostaJson(false, null, $erros[$codigo] ?? 'Erro desconhecido no upload.');
}

$ficheiro      = $_FILES['ficheiro'];
$nome_original = basename($ficheiro['name']);
$tipo_mime     = mime_content_type($ficheiro['tmp_name']);
$tamanho       = $ficheiro['size'];
$categoria     = trim($_POST['categoria'] ?? '') ?: null;
$descricao     = trim($_POST['descricao']  ?? '') ?: null;

// Validação de tipo
$tipos_aceites = ['application/pdf', 'text/plain'];
$extensao      = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
if (!in_array($tipo_mime, $tipos_aceites) && !in_array($extensao, ['pdf', 'txt'])) {
    respostaJson(false, null, 'Tipo de ficheiro não permitido. Usa PDF ou TXT.');
}

// Validação de tamanho
if ($tamanho > TAMANHO_MAXIMO_BYTES) {
    respostaJson(false, null, 'Ficheiro demasiado grande. Máximo ' . TAMANHO_MAXIMO_MB . ' MB.');
}

// Nome único para evitar colisões
$nome_guardado   = uniqid('doc_', true) . '.' . $extensao;
$caminho_final   = PASTA_UPLOADS . $nome_guardado;

if (!move_uploaded_file($ficheiro['tmp_name'], $caminho_final)) {
    respostaJson(false, null, 'Erro ao guardar o ficheiro no servidor.');
}

// Insere registo na BD com estado 'a_processar'
$stmt = $pdo->prepare("
    INSERT INTO documentos
        (id_configuracao_bot, nome_original, nome_guardado, caminho_ficheiro,
         tipo_mime, tamanho_bytes, categoria, descricao, estado)
    VALUES
        (:bot, :nome_orig, :nome_guard, :caminho, :mime, :tamanho, :categoria, :descricao, 'a_processar')
    RETURNING id_documento
");
$stmt->execute([
    ':bot'       => BOT_ID,
    ':nome_orig' => $nome_original,
    ':nome_guard'=> $nome_guardado,
    ':caminho'   => $caminho_final,
    ':mime'      => $tipo_mime,
    ':tamanho'   => $tamanho,
    ':categoria' => $categoria,
    ':descricao' => $descricao,
]);
$id_documento = $stmt->fetchColumn();

// Extrai texto
$texto = extrairTexto($caminho_final, $tipo_mime);

if ($texto === '') {
    $pdo->prepare("UPDATE documentos SET estado='erro', mensagem_erro='Não foi possível extrair texto do ficheiro.' WHERE id_documento=:id")
        ->execute([':id' => $id_documento]);
    respostaJson(false, null, 'Documento guardado mas não foi possível extrair texto. Tenta reprocessar.');
}

// Cria fragmentos
$total_frags = criarFragmentos($pdo, $id_documento, $texto);

// Actualiza estado para 'pronto'
$pdo->prepare("UPDATE documentos SET estado='pronto', processado_em=NOW() WHERE id_documento=:id")
    ->execute([':id' => $id_documento]);

respostaJson(true, [
    'id'              => $id_documento,
    'nome'            => $nome_original,
    'total_fragmentos'=> $total_frags,
], '');


// ============================================================
// FUNÇÕES AUXILIARES
// ============================================================

/**
 * Extrai texto de um ficheiro PDF ou TXT.
 * Estratégia em cascata:
 *   1. TXT → leitura directa
 *   2. PDF → pdftotext (se disponível no sistema)
 *   3. PDF → extracção bruta de strings (fallback sem dependências)
 */
function extrairTexto(string $caminho, string $mime): string {
    // TXT — simples
    if (str_contains($mime, 'text') || str_ends_with($caminho, '.txt')) {
        $texto = file_get_contents($caminho);
        return $texto !== false ? limparTexto($texto) : '';
    }

    // PDF — tenta pdftotext (disponível em Linux/Mac; no Windows instala Xpdf)
    if (function_exists('shell_exec') && !str_contains(ini_get('disable_functions'), 'shell_exec')) {
        $caminho_escapado = escapeshellarg($caminho);
        $resultado = shell_exec("pdftotext {$caminho_escapado} - 2>/dev/null");
        if ($resultado && strlen(trim($resultado)) > 20) {
            return limparTexto($resultado);
        }
    }

    // PDF — fallback: extracção de strings de texto do binário
    return extrairTextoPdfFallback($caminho);
}

/**
 * Extracção bruta de texto de PDF sem bibliotecas externas.
 * Não é perfeita mas funciona para PDFs com texto incorporado.
 */
function extrairTextoPdfFallback(string $caminho): string {
    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) return '';

    $texto = '';

    // Extrai blocos de texto entre BT e ET (Begin Text / End Text do PDF)
    preg_match_all('/BT\s*(.*?)\s*ET/s', $conteudo, $blocos);
    foreach ($blocos[1] as $bloco) {
        // Extrai strings entre parênteses e entre < >
        preg_match_all('/\(([^)]*)\)/', $bloco, $strings_paren);
        foreach ($strings_paren[1] as $s) {
            $texto .= $s . ' ';
        }
        preg_match_all('/<([0-9A-Fa-f]+)>/', $bloco, $strings_hex);
        foreach ($strings_hex[1] as $hex) {
            if (strlen($hex) % 2 === 0) {
                $decoded = '';
                for ($i = 0; $i < strlen($hex); $i += 2) {
                    $char = chr(hexdec(substr($hex, $i, 2)));
                    if (ctype_print($char)) $decoded .= $char;
                }
                $texto .= $decoded . ' ';
            }
        }
    }

    return limparTexto($texto);
}

/**
 * Limpa e normaliza o texto extraído.
 */
function limparTexto(string $texto): string {
    // Remove caracteres de controlo excepto espaço, tab, newline
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $texto);
    // Normaliza espaços múltiplos
    $texto = preg_replace('/[ \t]+/', ' ', $texto);
    // Normaliza linhas em branco múltiplas
    $texto = preg_replace('/\n{3,}/', "\n\n", $texto);
    return trim($texto);
}

/**
 * Divide o texto em fragmentos com sobreposição e insere na BD.
 * Retorna o número de fragmentos criados.
 */
function criarFragmentos(PDO $pdo, string $id_documento, string $texto): int {
    $tamanho     = CHUNK_TAMANHO;
    $sobreposicao = CHUNK_SOBREPOSICAO;
    $comprimento  = mb_strlen($texto);
    $fragmentos   = [];
    $posicao      = 0;
    $indice       = 0;

    while ($posicao < $comprimento) {
        $fragmento = mb_substr($texto, $posicao, $tamanho);
        if (trim($fragmento) !== '') {
            $fragmentos[] = $fragmento;
        }
        $posicao += ($tamanho - $sobreposicao);
        $indice++;
    }

    $stmt = $pdo->prepare("
        INSERT INTO fragmentos_documento (id_documento, indice_fragmento, conteudo, total_tokens)
        VALUES (:doc, :indice, :conteudo, :tokens)
        ON CONFLICT (id_documento, indice_fragmento) DO UPDATE SET conteudo = EXCLUDED.conteudo
    ");

    foreach ($fragmentos as $i => $frag) {
        $stmt->execute([
            ':doc'     => $id_documento,
            ':indice'  => $i,
            ':conteudo'=> $frag,
            ':tokens'  => (int)(mb_strlen($frag) / 4), // estimativa: ~4 chars por token
        ]);
    }

    return count($fragmentos);
}