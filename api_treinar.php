<?php
// ============================================================
//  API_TREINAR.PHP — Gere entradas na base_conhecimento
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

session_start();

// Protecção
if (empty($_SESSION['admin_autenticado'])) {
    respostaJson(false, null, 'Não autorizado.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respostaJson(false, null, 'Método não permitido.');
}

$corpo = json_decode(file_get_contents('php://input'), true);
$acao  = $corpo['acao'] ?? '';
$pdo   = obterConexao();

switch ($acao) {

    // ----------------------------------------------------------
    // INSERIR nova entrada
    // ----------------------------------------------------------
    case 'inserir':
        $titulo     = trim($corpo['titulo']    ?? '');
        $conteudo   = trim($corpo['conteudo']  ?? '');
        $categoria  = trim($corpo['categoria'] ?? '') ?: null;
        $prioridade = max(1, min(10, (int)($corpo['prioridade'] ?? 5)));
        $etiquetas  = $corpo['etiquetas'] ?? [];

        if ($titulo === '' || $conteudo === '') {
            respostaJson(false, null, 'Título e conteúdo são obrigatórios.');
        }

        // Converte array de etiquetas para formato PostgreSQL
        $etiquetas_pg = empty($etiquetas)
            ? null
            : '{' . implode(',', array_map(fn($e) => '"' . str_replace('"', '', $e) . '"', $etiquetas)) . '}';

        $stmt = $pdo->prepare("
            INSERT INTO base_conhecimento
                (id_configuracao_bot, titulo, conteudo, categoria, etiquetas, prioridade)
            VALUES
                (:bot, :titulo, :conteudo, :categoria, :etiquetas::text[], :prioridade)
            RETURNING id_base_conhecimento
        ");
        $stmt->execute([
            ':bot'        => BOT_ID,
            ':titulo'     => $titulo,
            ':conteudo'   => $conteudo,
            ':categoria'  => $categoria,
            ':etiquetas'  => $etiquetas_pg,
            ':prioridade' => $prioridade,
        ]);

        $id = $stmt->fetchColumn();
        respostaJson(true, ['id' => $id], '');

    // ----------------------------------------------------------
    // ELIMINAR entrada
    // ----------------------------------------------------------
    case 'eliminar':
        $id = trim($corpo['id'] ?? '');
        if ($id === '') respostaJson(false, null, 'ID inválido.');

        $stmt = $pdo->prepare("
            DELETE FROM base_conhecimento
            WHERE id_base_conhecimento = :id
              AND id_configuracao_bot  = :bot
        ");
        $stmt->execute([':id' => $id, ':bot' => BOT_ID]);

        if ($stmt->rowCount() === 0) {
            respostaJson(false, null, 'Entrada não encontrada ou sem permissão.');
        }
        respostaJson(true, null, '');

    // ----------------------------------------------------------
    // ALTERNAR activo/inactivo
    // ----------------------------------------------------------
    case 'alternar_ativo':
        $id    = trim($corpo['id']    ?? '');
        $ativo = (bool)($corpo['ativo'] ?? false);

        if ($id === '') respostaJson(false, null, 'ID inválido.');

        $stmt = $pdo->prepare("
            UPDATE base_conhecimento
               SET ativo = :ativo
             WHERE id_base_conhecimento = :id
               AND id_configuracao_bot  = :bot
        ");
        $stmt->execute([':ativo' => $ativo ? 'true' : 'false', ':id' => $id, ':bot' => BOT_ID]);

        if ($stmt->rowCount() === 0) {
            respostaJson(false, null, 'Entrada não encontrada.');
        }
        respostaJson(true, ['ativo' => $ativo], '');

    // ----------------------------------------------------------
    // LISTAR (para uso futuro / AJAX)
    // ----------------------------------------------------------
    case 'listar':
        $stmt = $pdo->prepare("
            SELECT id_base_conhecimento, titulo, categoria, prioridade, ativo, criado_em
            FROM base_conhecimento
            WHERE id_configuracao_bot = :bot
            ORDER BY prioridade ASC, criado_em DESC
        ");
        $stmt->execute([':bot' => BOT_ID]);
        respostaJson(true, $stmt->fetchAll(), '');

    default:
        respostaJson(false, null, 'Acção desconhecida: ' . htmlspecialchars($acao));
}