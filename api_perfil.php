<?php
// ============================================================
//  API_PERFIL.PHP — Guarda (INSERT ou UPDATE) o perfil do criador
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

session_start();

if (empty($_SESSION['admin_autenticado'])) {
    respostaJson(false, null, 'Não autorizado.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respostaJson(false, null, 'Método não permitido.');
}

$corpo = json_decode(file_get_contents('php://input'), true);

// Validação
$nome = trim($corpo['nome_completo'] ?? '');
if ($nome === '') {
    respostaJson(false, null, 'O nome completo é obrigatório.');
}

$pdo = obterConexao();

// Prepara os dados
$data_nasc    = !empty($corpo['data_nascimento']) ? $corpo['data_nascimento'] : null;
$profissao    = !empty($corpo['profissao'])    ? trim($corpo['profissao'])    : null;
$nacionalidade= !empty($corpo['nacionalidade'])? trim($corpo['nacionalidade']): null;
$telefone     = !empty($corpo['telefone'])     ? trim($corpo['telefone'])     : null;
$email        = !empty($corpo['email'])        ? trim($corpo['email'])        : null;
$morada       = !empty($corpo['morada'])       ? trim($corpo['morada'])       : null;
$bio          = !empty($corpo['bio'])          ? trim($corpo['bio'])          : null;
$url_foto     = !empty($corpo['url_foto'])     ? trim($corpo['url_foto'])     : null;

// Redes sociais — filtra nulos e serializa em JSON
$redes_raw = $corpo['redes_sociais'] ?? [];
$redes     = array_filter($redes_raw, fn($v) => !empty($v));
$redes_json = empty($redes) ? null : json_encode($redes, JSON_UNESCAPED_UNICODE);

// Upsert — INSERT se não existe, UPDATE se existe
$stmt = $pdo->prepare("
    INSERT INTO perfil_criador
        (id_configuracao_bot, nome_completo, data_nascimento, telefone,
         morada, email, profissao, nacionalidade, bio, url_foto, redes_sociais)
    VALUES
        (:bot, :nome, :nasc, :tel, :morada, :email, :prof, :nac, :bio, :foto, :redes)
    ON CONFLICT (id_configuracao_bot) DO UPDATE SET
        nome_completo   = EXCLUDED.nome_completo,
        data_nascimento = EXCLUDED.data_nascimento,
        telefone        = EXCLUDED.telefone,
        morada          = EXCLUDED.morada,
        email           = EXCLUDED.email,
        profissao       = EXCLUDED.profissao,
        nacionalidade   = EXCLUDED.nacionalidade,
        bio             = EXCLUDED.bio,
        url_foto        = EXCLUDED.url_foto,
        redes_sociais   = EXCLUDED.redes_sociais,
        atualizado_em   = NOW()
    RETURNING id_perfil_criador
");

$stmt->execute([
    ':bot'    => BOT_ID,
    ':nome'   => $nome,
    ':nasc'   => $data_nasc,
    ':tel'    => $telefone,
    ':morada' => $morada,
    ':email'  => $email,
    ':prof'   => $profissao,
    ':nac'    => $nacionalidade,
    ':bio'    => $bio,
    ':foto'   => $url_foto,
    ':redes'  => $redes_json,
]);

$id = $stmt->fetchColumn();
respostaJson(true, ['id' => $id], '');