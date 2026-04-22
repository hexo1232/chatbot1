<?php
// ============================================================
//  ADMIN.PHP — Painel de administração
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

session_start();

// ------------------------------------------------------------
// Processamento do login
// ------------------------------------------------------------
$erro_login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['senha'])) {
    if ($_POST['senha'] === ADMIN_SENHA) {
        $_SESSION['admin_autenticado'] = true;
        $_SESSION['admin_desde']       = date('H:i');
    } else {
        $erro_login = 'Senha incorrecta. Tenta novamente.';
    }
}

// Logout
if (isset($_GET['sair'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ------------------------------------------------------------
// Se não autenticado — mostra formulário de login
// ------------------------------------------------------------
if (empty($_SESSION['admin_autenticado'])):
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Entrar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&display=swap" rel="stylesheet">
     <link rel="stylesheet" href="css/estilo.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .caixa-login {
            width: 100%; max-width: 380px;
            background: var(--cor-fundo-2);
            border: 1px solid var(--cor-borda);
            border-radius: var(--raio);
            padding: 40px 36px;
        }
        .login-logo {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 32px;
        }
        .login-logo-icone {
            width: 40px; height: 40px;
            background: var(--cor-acento-suave);
            border: 1px solid var(--cor-borda-forte);
            border-radius: var(--raio-sm);
            display: flex; align-items: center; justify-content: center;
        }
        .login-titulo { font-size: 20px; font-weight: 600; letter-spacing: -0.02em; }
        .login-subtitulo { font-size: 13px; color: var(--cor-texto-2); margin-bottom: 28px; }
        .campo-grupo { margin-bottom: 16px; }
        .campo-grupo label { display: block; font-size: 12px; font-weight: 500; color: var(--cor-texto-2); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.06em; }
        .campo-grupo input {
            width: 100%; padding: 10px 14px;
            background: var(--cor-fundo-3);
            border: 1px solid var(--cor-borda-forte);
            border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 14px;
            color: var(--cor-texto); outline: none;
            transition: border-color var(--transicao);
        }
        .campo-grupo input:focus { border-color: var(--cor-acento); box-shadow: 0 0 0 3px var(--cor-acento-suave); }
        .btn-login {
            width: 100%; padding: 11px;
            background: var(--cor-acento); color: #fff;
            border: none; border-radius: var(--raio-sm);
            font-family: var(--fonte-ui); font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background var(--transicao);
            margin-top: 8px;
        }
        .btn-login:hover { background: var(--cor-acento-hover); }
        .erro-login {
            background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3);
            border-radius: var(--raio-sm); padding: 10px 14px;
            font-size: 13px; color: var(--cor-erro); margin-bottom: 16px;
        }
        .link-voltar { display: block; text-align: center; margin-top: 20px; font-size: 13px; color: var(--cor-texto-3); text-decoration: none; }
        .link-voltar:hover { color: var(--cor-acento); }
    </style>
</head>
<body>
    <div class="caixa-login">
        <div class="login-logo">
            <div class="login-logo-icone">
                <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
                    <rect x="3" y="11" width="16" height="9" rx="2" stroke="var(--cor-acento)" stroke-width="1.5"/>
                    <path d="M7 11V7a4 4 0 0 1 8 0v4" stroke="var(--cor-acento)" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="11" cy="15.5" r="1.5" fill="var(--cor-acento)"/>
                </svg>
            </div>
            <span class="login-titulo">Área Admin</span>
        </div>

        <p class="login-subtitulo">Introduz a senha para aceder ao painel de gestão.</p>

        <?php if ($erro_login): ?>
        <div class="erro-login"><?= htmlspecialchars($erro_login) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="campo-grupo">
                <label for="senha">Senha</label>
                <input type="password" id="senha" name="senha" placeholder="••••••••" autofocus required>
            </div>
            <button type="submit" class="btn-login">Entrar</button>
        </form>

        <a href="index.php" class="link-voltar">← Voltar ao chat</a>
    </div>
</body>
</html>
<?php
// Para aqui se não autenticado
exit;
endif;

// ------------------------------------------------------------
// A partir daqui — utilizador autenticado
// Carrega estatísticas
// ------------------------------------------------------------
$pdo  = obterConexao();
$stmt = $pdo->prepare("SELECT * FROM v_estatisticas_bot WHERE id_configuracao_bot = :bot");
$stmt->execute([':bot' => BOT_ID]);
$stats = $stmt->fetch() ?: [
    'nome_bot'             => 'Bot',
    'entradas_conhecimento' => 0,
    'total_documentos'     => 0,
    'total_conversas'      => 0,
    'total_mensagens'      => 0,
];

// Últimas 5 conversas
$stmt = $pdo->prepare("
    SELECT c.id_sessao, c.iniciada_em, c.ultima_mensagem_em,
           COUNT(m.id_mensagem) AS total_msgs
    FROM conversas c
    LEFT JOIN mensagens m ON m.id_conversa = c.id_conversa
    WHERE c.id_configuracao_bot = :bot
    GROUP BY c.id_conversa
    ORDER BY c.ultima_mensagem_em DESC
    LIMIT 5
");
$stmt->execute([':bot' => BOT_ID]);
$ultimas_conversas = $stmt->fetchAll();

// Últimos 5 documentos
$stmt = $pdo->prepare("
    SELECT nome_original, estado, carregado_em, categoria
    FROM documentos
    WHERE id_configuracao_bot = :bot
    ORDER BY carregado_em DESC
    LIMIT 5
");
$stmt->execute([':bot' => BOT_ID]);
$ultimos_docs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Painel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css">
    <style>
        body { overflow: auto; }
        .area-chat { overflow: auto; }
        .conteudo-admin { padding: 32px; max-width: 960px; }
        .titulo-pagina { font-size: 22px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 6px; }
        .subtitulo-pagina { font-size: 13px; color: var(--cor-texto-2); margin-bottom: 32px; }

        /* Cards de estatísticas */
        .grelha-stats {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 16px; margin-bottom: 32px;
        }
        .card-stat {
            background: var(--cor-fundo-2);
            border: 1px solid var(--cor-borda);
            border-radius: var(--raio);
            padding: 20px;
        }
        .stat-icone {
            width: 36px; height: 36px;
            border-radius: var(--raio-sm);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 14px;
        }
        .stat-valor { font-size: 28px; font-weight: 600; letter-spacing: -0.03em; color: var(--cor-texto); }
        .stat-label { font-size: 12px; color: var(--cor-texto-3); margin-top: 2px; }

        /* Grelha de tabelas */
        .grelha-tabelas { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .card-tabela {
            background: var(--cor-fundo-2); border: 1px solid var(--cor-borda);
            border-radius: var(--raio); overflow: hidden;
        }
        .card-tabela-cabecalho {
            padding: 16px 20px; border-bottom: 1px solid var(--cor-borda);
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-tabela-titulo { font-size: 14px; font-weight: 600; }
        .tabela-admin { width: 100%; border-collapse: collapse; }
        .tabela-admin td { padding: 12px 20px; font-size: 13px; border-bottom: 1px solid var(--cor-borda); color: var(--cor-texto-2); }
        .tabela-admin tr:last-child td { border-bottom: none; }
        .tabela-admin td:first-child { color: var(--cor-texto); font-weight: 500; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* Badges de estado */
        .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 500; }
        .badge-pronto    { background: rgba(74,222,128,0.15); color: #4ade80; }
        .badge-pendente  { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-erro      { background: rgba(248,113,113,0.15); color: #f87171; }
        .badge-processar { background: rgba(96,165,250,0.15); color: #60a5fa; }

        /* Acções rápidas */
        .acoes-rapidas { display: flex; gap: 12px; margin-bottom: 32px; flex-wrap: wrap; }
        .btn-acao {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 18px;
            background: var(--cor-fundo-2); border: 1px solid var(--cor-borda);
            border-radius: var(--raio-sm); color: var(--cor-texto-2);
            text-decoration: none; font-size: 13px; font-weight: 500;
            transition: all var(--transicao);
        }
        .btn-acao:hover { border-color: var(--cor-acento); color: var(--cor-acento); background: var(--cor-acento-suave); }
        .btn-acao-destaque { background: var(--cor-acento); border-color: var(--cor-acento); color: #fff; }
        .btn-acao-destaque:hover { background: var(--cor-acento-hover); color: #fff; }

        @media (max-width: 900px) {
            .grelha-stats { grid-template-columns: repeat(2, 1fr); }
            .grelha-tabelas { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<aside class="barra-lateral">
    <div class="logo-area">
        <div class="logo-icone">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                <circle cx="14" cy="14" r="13" stroke="var(--cor-acento)" stroke-width="1.5"/>
                <path d="M8 14c0-3.3 2.7-6 6-6s6 2.7 6 6-2.7 6-6 6" stroke="var(--cor-acento)" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="14" cy="14" r="2.5" fill="var(--cor-acento)"/>
            </svg>
        </div>
        <span class="logo-nome">Admin</span>
    </div>

    <nav class="nav-lateral">
        <a href="index.php"     class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 3h12M2 8h8M2 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Chat público
        </a>
        <a href="admin.php"     class="nav-item ativo">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="2" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
            Dashboard
        </a>
        <a href="treinar.php"   class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Treinar bot
        </a>
        <a href="documentos.php" class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 2h6l4 4v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.5"/><path d="M9 2v4h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Documentos
        </a>
        <a href="perfil.php"    class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M2 14c0-3.3 2.7-4 6-4s6 .7 6 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Perfil do criador
        </a>
    </nav>

    <div class="rodape-lateral">
        <div style="margin-bottom:8px;font-size:12px;color:var(--cor-texto-2)">
            Sessão iniciada às <?= $_SESSION['admin_desde'] ?? '--:--' ?>
        </div>
        <a href="admin.php?sair=1" style="font-size:12px;color:var(--cor-erro);text-decoration:none;">Terminar sessão</a>
    </div>
</aside>

<main class="area-chat">
<div class="conteudo-admin">

    <h1 class="titulo-pagina">Dashboard</h1>
    <p class="subtitulo-pagina">Visão geral do bot <strong><?= htmlspecialchars($stats['nome_bot']) ?></strong></p>

    <!-- Estatísticas -->
    <div class="grelha-stats">
        <div class="card-stat">
            <div class="stat-icone" style="background:rgba(108,143,255,0.15)">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M2 4h14M2 9h10M2 14h12" stroke="var(--cor-acento)" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div class="stat-valor"><?= $stats['entradas_conhecimento'] ?></div>
            <div class="stat-label">Entradas de conhecimento</div>
        </div>
        <div class="card-stat">
            <div class="stat-icone" style="background:rgba(74,222,128,0.15)">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M4 2h8l4 4v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" stroke="#4ade80" stroke-width="1.5"/></svg>
            </div>
            <div class="stat-valor"><?= $stats['total_documentos'] ?></div>
            <div class="stat-label">Documentos prontos</div>
        </div>
        <div class="card-stat">
            <div class="stat-icone" style="background:rgba(251,191,36,0.15)">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M2 14V6l7-4 7 4v8" stroke="#fbbf24" stroke-width="1.5" stroke-linecap="round"/><rect x="6" y="10" width="6" height="4" rx="1" stroke="#fbbf24" stroke-width="1.5"/></svg>
            </div>
            <div class="stat-valor"><?= $stats['total_conversas'] ?></div>
            <div class="stat-label">Conversas</div>
        </div>
        <div class="card-stat">
            <div class="stat-icone" style="background:rgba(167,139,250,0.15)">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M3 3h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6l-4 3V4a1 1 0 0 1 1-1z" stroke="#a78bfa" stroke-width="1.5"/></svg>
            </div>
            <div class="stat-valor"><?= $stats['total_mensagens'] ?></div>
            <div class="stat-label">Mensagens trocadas</div>
        </div>
    </div>

    <!-- Acções rápidas -->
    <div class="acoes-rapidas">
        <a href="treinar.php"    class="btn-acao btn-acao-destaque">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1v12M1 7h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Adicionar conhecimento
        </a>
        <a href="documentos.php" class="btn-acao">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1v8M4 6l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M1 11h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Carregar documento
        </a>
        <a href="perfil.php"     class="btn-acao">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="4" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M1 13c0-3 2.7-4 6-4s6 1 6 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Editar perfil
        </a>
        <a href="index.php"      class="btn-acao">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 3h10M2 7h7M2 11h9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Ver chat
        </a>
    </div>

    <!-- Tabelas recentes -->
    <div class="grelha-tabelas">

        <!-- Últimas conversas -->
        <div class="card-tabela">
            <div class="card-tabela-cabecalho">
                <span class="card-tabela-titulo">Últimas conversas</span>
            </div>
            <table class="tabela-admin">
                <?php if (empty($ultimas_conversas)): ?>
                <tr><td colspan="2" style="text-align:center;color:var(--cor-texto-3);padding:24px">Nenhuma conversa ainda.</td></tr>
                <?php else: ?>
                <?php foreach ($ultimas_conversas as $c): ?>
                <tr>
                    <td title="<?= htmlspecialchars($c['id_sessao']) ?>">
                        <?= htmlspecialchars(substr($c['id_sessao'], 0, 20)) ?>…
                    </td>
                    <td style="text-align:right">
                        <?= $c['total_msgs'] ?> msg
                        <br><span style="font-size:11px;color:var(--cor-texto-3)"><?= date('d/m H:i', strtotime($c['ultima_mensagem_em'])) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>

        <!-- Últimos documentos -->
        <div class="card-tabela">
            <div class="card-tabela-cabecalho">
                <span class="card-tabela-titulo">Últimos documentos</span>
            </div>
            <table class="tabela-admin">
                <?php if (empty($ultimos_docs)): ?>
                <tr><td colspan="2" style="text-align:center;color:var(--cor-texto-3);padding:24px">Nenhum documento ainda.</td></tr>
                <?php else: ?>
                <?php foreach ($ultimos_docs as $d):
                    $classe_badge = match($d['estado']) {
                        'pronto'       => 'badge-pronto',
                        'pendente'     => 'badge-pendente',
                        'erro'         => 'badge-erro',
                        'a_processar'  => 'badge-processar',
                        default        => 'badge-pendente',
                    };
                ?>
                <tr>
                    <td title="<?= htmlspecialchars($d['nome_original']) ?>">
                        <?= htmlspecialchars(substr($d['nome_original'], 0, 22)) ?>
                    </td>
                    <td style="text-align:right">
                        <span class="badge <?= $classe_badge ?>"><?= htmlspecialchars($d['estado']) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>

    </div>
</div>
</main>

</body>
</html>