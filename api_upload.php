<?php
// ============================================================
//  API_UPLOAD.PHP — Recebe ficheiro, extrai texto, cria fragmentos
//  CORRIGIDO: detecção MIME, sessão, headers e erros de ligação
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

// CRÍTICO: session_start() ANTES de qualquer output ou header
// e ANTES da função respostaJson() ser chamada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificação de autenticação
if (empty($_SESSION['admin_autenticado'])) {
    definirCabecalhosJson(); // garante header correcto antes do exit
    echo json_encode(['sucesso' => false, 'dados' => null, 'erro' => 'Não autorizado. Sessão expirada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo          = obterConexao();

// Lê o Content-Type com fallback seguro
$content_type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

// ------------------------------------------------------------
// Acções JSON (reprocessar / eliminar)
// ------------------------------------------------------------
if (str_contains($content_type, 'application/json')) {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $acao  = $corpo['acao'] ?? '';
    $id    = trim($corpo['id'] ?? '');

    if ($id === '') respostaJson(false, null, 'ID inválido.');

    if ($acao === 'eliminar') {
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

        $pdo->prepare("DELETE FROM fragmentos_documento WHERE id_documento = :id")->execute([':id' => $id]);

        $texto = extrairTexto($doc['caminho_ficheiro'], $doc['tipo_mime']);
        if ($texto === '') {
            $pdo->prepare("UPDATE documentos SET estado='erro', mensagem_erro='Não foi possível extrair texto.' WHERE id_documento=:id")
                ->execute([':id' => $id]);
            respostaJson(false, null, 'Não foi possível extrair texto do documento.');
        }

        $total = criarFragmentos($pdo, $id, $texto);
        $pdo->prepare("UPDATE documentos SET estado='pronto', processado_em=NOW(), mensagem_erro=NULL WHERE id_documento=:id")
            ->execute([':id' => $id]);
        respostaJson(true, ['total_fragmentos' => $total], '');
    }

    respostaJson(false, null, 'Acção desconhecida.');
}

// ------------------------------------------------------------
// Upload de novo ficheiro (multipart/form-data)
// ------------------------------------------------------------

// Verifica se existe ficheiro e captura o código de erro de forma segura
$upload_erro = $_FILES['ficheiro']['error'] ?? UPLOAD_ERR_NO_FILE;

if (!isset($_FILES['ficheiro']) || $upload_erro !== UPLOAD_ERR_OK) {
    $erros = [
        UPLOAD_ERR_INI_SIZE   => 'Ficheiro excede o limite do php.ini (upload_max_filesize). Verifica as definições do servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'Ficheiro excede o limite do formulário HTML.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tenta novamente.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum ficheiro foi enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária em falta no servidor.',
        UPLOAD_ERR_CANT_WRITE => 'Sem permissão para escrever o ficheiro no servidor.',
        UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP.',
    ];
    $msg = $erros[$upload_erro] ?? "Erro de upload desconhecido (código: {$upload_erro}).";
    respostaJson(false, null, $msg);
}

$ficheiro      = $_FILES['ficheiro'];
$nome_original = basename($ficheiro['name']);
$tamanho       = $ficheiro['size'];
$categoria     = trim($_POST['categoria'] ?? '') ?: null;
$descricao     = trim($_POST['descricao']  ?? '') ?: null;

// ---------------------------------------------------------------
// CORRECÇÃO: Detecção de MIME robusta (mime_content_type falha
// em XAMPP/Windows para PDFs, devolvendo application/octet-stream)
// ---------------------------------------------------------------
$tipo_mime = detectarMime($ficheiro['tmp_name'], $nome_original);

// Validação de tipo
$extensao      = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
$tipos_aceites = ['application/pdf', 'text/plain'];
if (!in_array($tipo_mime, $tipos_aceites) && !in_array($extensao, ['pdf', 'txt'])) {
    respostaJson(false, null, "Tipo de ficheiro não permitido ({$tipo_mime}). Usa PDF ou TXT.");
}

// Validação de tamanho
if ($tamanho > TAMANHO_MAXIMO_BYTES) {
    respostaJson(false, null, 'Ficheiro demasiado grande. Máximo ' . TAMANHO_MAXIMO_MB . ' MB.');
}

// Verifica/cria pasta de uploads
if (!is_dir(PASTA_UPLOADS)) {
    if (!mkdir(PASTA_UPLOADS, 0755, true)) {
        respostaJson(false, null, 'Não foi possível criar a pasta de uploads. Verifica permissões.');
    }
}

// Nome único para evitar colisões
$nome_guardado = uniqid('doc_', true) . '.' . $extensao;
$caminho_final = PASTA_UPLOADS . $nome_guardado;

if (!move_uploaded_file($ficheiro['tmp_name'], $caminho_final)) {
    respostaJson(false, null, 'Erro ao mover o ficheiro. Verifica permissões da pasta uploads/.');
}

// Insere registo na BD
try {
    $stmt = $pdo->prepare("
        INSERT INTO documentos
            (id_configuracao_bot, nome_original, nome_guardado, caminho_ficheiro,
             tipo_mime, tamanho_bytes, categoria, descricao, estado)
        VALUES
            (:bot, :nome_orig, :nome_guard, :caminho, :mime, :tamanho, :categoria, :descricao, 'a_processar')
        RETURNING id_documento
    ");
    $stmt->execute([
        ':bot'        => BOT_ID,
        ':nome_orig'  => $nome_original,
        ':nome_guard' => $nome_guardado,
        ':caminho'    => $caminho_final,
        ':mime'       => $tipo_mime,
        ':tamanho'    => $tamanho,
        ':categoria'  => $categoria,
        ':descricao'  => $descricao,
    ]);
    $id_documento = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Se a BD falhar, remove o ficheiro que já foi guardado
    if (file_exists($caminho_final)) unlink($caminho_final);
    respostaJson(false, null, 'Erro ao registar na base de dados: ' . $e->getMessage());
}

// Extrai texto
$texto = extrairTexto($caminho_final, $tipo_mime);

if ($texto === '') {
    $pdo->prepare("UPDATE documentos SET estado='erro', mensagem_erro='Não foi possível extrair texto. O PDF pode ser baseado em imagem (scanned).' WHERE id_documento=:id")
        ->execute([':id' => $id_documento]);
    respostaJson(false, null, 'Documento guardado mas não foi possível extrair texto. O PDF pode ser baseado em imagens. Tenta com um PDF que contenha texto seleccionável.');
}

// Cria fragmentos
$total_frags = criarFragmentos($pdo, $id_documento, $texto);

// Actualiza estado para 'pronto'
$pdo->prepare("UPDATE documentos SET estado='pronto', processado_em=NOW(), total_paginas=NULL WHERE id_documento=:id")
    ->execute([':id' => $id_documento]);

respostaJson(true, [
    'id'               => $id_documento,
    'nome'             => $nome_original,
    'total_fragmentos' => $total_frags,
], '');


// ============================================================
// FUNÇÕES AUXILIARES
// ============================================================

/**
 * Detecta o tipo MIME de forma robusta.
 * mime_content_type() falha frequentemente no XAMPP/Windows
 * para PDFs, devolvendo application/octet-stream.
 * Esta função usa a assinatura binária (magic bytes) como fallback.
 */
function detectarMime(string $caminho_tmp, string $nome_original): string {
    // 1. Tenta o finfo (mais fiável)
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $caminho_tmp);
        finfo_close($finfo);
        if ($mime && $mime !== 'application/octet-stream') {
            return $mime;
        }
    }

    // 2. Verifica magic bytes do PDF: começa com "%PDF"
    $handle = fopen($caminho_tmp, 'rb');
    if ($handle) {
        $header = fread($handle, 4);
        fclose($handle);
        if ($header === '%PDF') {
            return 'application/pdf';
        }
    }

    // 3. Fallback pela extensão do nome original
    $ext = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
    return match($ext) {
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        default => mime_content_type($caminho_tmp) ?: 'application/octet-stream',
    };
}

/**
 * Extrai texto de PDF ou TXT com estratégia em cascata.
 */
function extrairTexto(string $caminho, string $mime): string {
    // TXT — leitura directa
    if (str_contains($mime, 'text') || str_ends_with($caminho, '.txt')) {
        $texto = file_get_contents($caminho);
        return $texto !== false ? limparTexto($texto) : '';
    }

    // PDF — tenta pdftotext
    $disable_funcs = ini_get('disable_functions') ?: '';
    if (function_exists('shell_exec') && !str_contains($disable_funcs, 'shell_exec')) {
        $caminho_escapado = escapeshellarg($caminho);
        $resultado = @shell_exec("pdftotext {$caminho_escapado} - 2>/dev/null");
        if ($resultado && strlen(trim($resultado)) > 20) {
            return limparTexto($resultado);
        }
    }

    // PDF — fallback binário
    return extrairTextoPdfFallback($caminho);
}

/**
 * Extracção bruta de texto de PDF sem bibliotecas externas.
 */
function extrairTextoPdfFallback(string $caminho): string {
    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) return '';

    $texto = '';

    // Blocos BT...ET (Begin Text / End Text)
    preg_match_all('/BT\s*(.*?)\s*ET/s', $conteudo, $blocos);
    foreach ($blocos[1] as $bloco) {
        // Strings entre parênteses: (texto)
        preg_match_all('/\(([^)]*)\)/', $bloco, $strings_paren);
        foreach ($strings_paren[1] as $s) {
            // Desescapa sequências PDF comuns
            $s = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $s);
            $texto .= $s . ' ';
        }
        // Strings hexadecimais: <4865...>
        preg_match_all('/<([0-9A-Fa-f\s]+)>/', $bloco, $strings_hex);
        foreach ($strings_hex[1] as $hex) {
            $hex = preg_replace('/\s/', '', $hex); // remove espaços dentro do hex
            if (strlen($hex) % 2 === 0) {
                $decoded = '';
                for ($i = 0; $i < strlen($hex); $i += 2) {
                    $char = chr(hexdec(substr($hex, $i, 2)));
                    if (ctype_print($char) || $char === ' ') $decoded .= $char;
                }
                if (trim($decoded) !== '') $texto .= $decoded . ' ';
            }
        }
    }

    return limparTexto($texto);
}

/**
 * Limpa e normaliza o texto extraído.
 */
function limparTexto(string $texto): string {
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $texto);
    $texto = preg_replace('/[ \t]+/', ' ', $texto);
    $texto = preg_replace('/\n{3,}/', "\n\n", $texto);
    return trim($texto);
}

/**
 * Divide o texto em fragmentos com sobreposição e insere na BD.
 */
function criarFragmentos(PDO $pdo, string $id_documento, string $texto): int {
    $tamanho      = CHUNK_TAMANHO;
    $sobreposicao = CHUNK_SOBREPOSICAO;
    $comprimento  = mb_strlen($texto);
    $fragmentos   = [];
    $posicao      = 0;

    while ($posicao < $comprimento) {
        $fragmento = mb_substr($texto, $posicao, $tamanho);
        if (trim($fragmento) !== '') {
            $fragmentos[] = $fragmento;
        }
        $posicao += ($tamanho - $sobreposicao);
        // Protecção contra loop infinito se sobreposicao >= tamanho
        if ($tamanho <= $sobreposicao) break;
    }

    if (empty($fragmentos)) return 0;

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
            ':tokens'  => (int)(mb_strlen($frag) / 4),
        ]);
    }

    return count($fragmentos);
}