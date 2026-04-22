<?php
// ============================================================
//  INDEX.PHP — Interface pública do chatbot
// ============================================================

require_once 'configuracao.php';
require_once 'conexao.php';

// Busca dados do perfil e bot para exibir no cabeçalho
$pdo  = obterConexao();
$stmt = $pdo->prepare("
    SELECT b.nome AS nome_bot, b.descricao,
           p.nome_completo, p.profissao, p.url_foto
    FROM configuracao_bot b
    LEFT JOIN perfil_criador p ON p.id_configuracao_bot = b.id_configuracao_bot
    WHERE b.id_configuracao_bot = :bot
    LIMIT 1
");
$stmt->execute([':bot' => BOT_ID]);
$info = $stmt->fetch();

$nome_bot      = $info['nome_bot']      ?? 'ChatBot';
$descricao_bot = $info['descricao']     ?? 'Assistente inteligente';
$nome_criador  = $info['nome_completo'] ?? '';
$profissao     = $info['profissao']     ?? '';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nome_bot) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body>

<!-- ========================================================
     BARRA LATERAL
======================================================== -->
<aside class="barra-lateral">
    <div class="logo-area">
        <div class="logo-icone">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                <circle cx="14" cy="14" r="13" stroke="var(--cor-acento)" stroke-width="1.5"/>
                <path d="M8 14c0-3.3 2.7-6 6-6s6 2.7 6 6-2.7 6-6 6" stroke="var(--cor-acento)" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="14" cy="14" r="2.5" fill="var(--cor-acento)"/>
            </svg>
        </div>
        <span class="logo-nome"><?= htmlspecialchars($nome_bot) ?></span>
    </div>

    <div class="bot-info-lateral">
        <p class="bot-descricao"><?= htmlspecialchars($descricao_bot) ?></p>
    </div>

    <?php if ($nome_criador): ?>
    <div class="criador-lateral">
        <div class="criador-etiqueta">Criado por</div>
        <div class="criador-nome"><?= htmlspecialchars($nome_criador) ?></div>
        <?php if ($profissao): ?>
        <div class="criador-profissao"><?= htmlspecialchars($profissao) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <nav class="nav-lateral">
        <a href="index.php" class="nav-item ativo">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 3h12M2 8h8M2 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Chat
        </a>
        <a href="admin.php" class="nav-item">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="2" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
            Painel Admin
        </a>
    </nav>

    <div class="rodape-lateral">
        <span>Criado por Matias Alberto Matavel</span>
    </div>
</aside>

<!-- ========================================================
     ÁREA PRINCIPAL DO CHAT
======================================================== -->
<main class="area-chat">

    <!-- Cabeçalho do chat -->
    <header class="cabecalho-chat">
        <div class="cabecalho-info">
            <div class="status-indicador"></div>
            <div>
                <h1 class="cabecalho-titulo"><?= htmlspecialchars($nome_bot) ?></h1>
                <p class="cabecalho-subtitulo">Online · Responde em segundos</p>
            </div>
        </div>
        <button class="btn-limpar" id="btn-limpar" title="Limpar conversa">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 10M13 3L3 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </button>
    </header>

    <!-- Janela de mensagens -->
    <div class="janela-mensagens" id="janela-mensagens">

        <!-- Mensagem de boas-vindas -->
        <div class="mensagem mensagem-bot" id="msg-boas-vindas">
            <div class="avatar-bot">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="8" stroke="var(--cor-acento)" stroke-width="1.2"/><circle cx="9" cy="9" r="2" fill="var(--cor-acento)"/></svg>
            </div>
            <div class="balao">
                <p>Olá! Sou o <strong><?= htmlspecialchars($nome_bot) ?></strong>. Como posso ajudar?</p>
                <div class="sugestoes">
                    <button class="sugestao" onclick="usarSugestao(this)">Quem te criou?</button>
                    <button class="sugestao" onclick="usarSugestao(this)">O que sabes fazer?</button>
                    <button class="sugestao" onclick="usarSugestao(this)">Que documentos tens disponíveis?</button>
                </div>
            </div>
        </div>

    </div>

    <!-- Indicador de digitação -->
    <div class="indicador-digitacao" id="indicador-digitacao" style="display:none">
        <div class="avatar-bot">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="8" stroke="var(--cor-acento)" stroke-width="1.2"/><circle cx="9" cy="9" r="2" fill="var(--cor-acento)"/></svg>
        </div>
        <div class="balao balao-digitacao">
            <span></span><span></span><span></span>
        </div>
    </div>

    <!-- Formulário de entrada -->
    <div class="area-entrada">
        <div class="caixa-entrada">
            <textarea
                id="campo-mensagem"
                class="campo-mensagem"
                placeholder="Escreve a tua mensagem..."
                rows="1"
                maxlength="2000"
            ></textarea>
            <button class="btn-enviar" id="btn-enviar" disabled>
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                    <path d="M15 9L3 3l3 6-3 6 12-6z" fill="currentColor"/>
                </svg>
            </button>
        </div>
        <p class="aviso-rodape">As respostas baseiam-se no conhecimento configurado.</p>
    </div>

</main>

<script src="js/chat.js"></script>
</body>
</html>