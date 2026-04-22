// ============================================================
//  CHAT.JS — Lógica do chat público
// ============================================================

// Gera ou recupera o ID de sessão único do utilizador
function obterIdSessao() {
    let id = sessionStorage.getItem('chatbot_sessao');
    if (!id) {
        id = 'sess_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
        sessionStorage.setItem('chatbot_sessao', id);
    }
    return id;
}

const ID_SESSAO = obterIdSessao();

// Referências DOM
const janela       = document.getElementById('janela-mensagens');
const campo        = document.getElementById('campo-mensagem');
const btnEnviar    = document.getElementById('btn-enviar');
const btnLimpar    = document.getElementById('btn-limpar');
const indicador    = document.getElementById('indicador-digitacao');

// ============================================================
// Auto-resize do textarea
// ============================================================
campo.addEventListener('input', () => {
    campo.style.height = 'auto';
    campo.style.height = Math.min(campo.scrollHeight, 120) + 'px';
    btnEnviar.disabled = campo.value.trim() === '';
});

// Enviar com Enter (Shift+Enter = nova linha)
campo.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (!btnEnviar.disabled) enviarMensagem();
    }
});

btnEnviar.addEventListener('click', enviarMensagem);
btnLimpar.addEventListener('click', limparChat);

// ============================================================
// Usar sugestão de início
// ============================================================
function usarSugestao(btn) {
    campo.value = btn.textContent;
    campo.style.height = 'auto';
    btnEnviar.disabled = false;
    campo.focus();
}

// ============================================================
// Formata hora actual
// ============================================================
function horaAgora() {
    return new Date().toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' });
}

// ============================================================
// Adiciona mensagem na janela
// ============================================================
function adicionarMensagem(texto, tipo = 'bot', mostrarHora = true) {
    // Remove mensagem de boas-vindas na primeira interacção do utilizador
    const boasVindas = document.getElementById('msg-boas-vindas');
    if (boasVindas && tipo === 'user') boasVindas.remove();

    const div = document.createElement('div');
    div.className = `mensagem mensagem-${tipo}`;

    const hora = mostrarHora
        ? `<div class="hora-mensagem">${horaAgora()}</div>`
        : '';

    if (tipo === 'bot') {
        div.innerHTML = `
            <div class="avatar-bot">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                    <circle cx="9" cy="9" r="8" stroke="var(--cor-acento)" stroke-width="1.2"/>
                    <circle cx="9" cy="9" r="2" fill="var(--cor-acento)"/>
                </svg>
            </div>
            <div>
                <div class="balao"><p>${formatarTexto(texto)}</p></div>
                ${hora}
            </div>`;
    } else if (tipo === 'user') {
        div.innerHTML = `
            <div>
                <div class="balao"><p>${escaparHtml(texto)}</p></div>
                ${hora}
            </div>`;
    } else if (tipo === 'erro') {
        div.className = 'mensagem mensagem-bot mensagem-erro';
        div.innerHTML = `
            <div class="avatar-bot">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                    <circle cx="9" cy="9" r="8" stroke="#f87171" stroke-width="1.2"/>
                    <path d="M9 6v4M9 12v.5" stroke="#f87171" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <div>
                <div class="balao"><p>${escaparHtml(texto)}</p></div>
            </div>`;
    }

    janela.appendChild(div);
    rolarParaBaixo();
    return div;
}

// ============================================================
// Formata texto do bot (markdown básico)
// ============================================================
function formatarTexto(texto) {
    return escaparHtml(texto)
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g,     '<em>$1</em>')
        .replace(/`(.*?)`/g,       '<code>$1</code>')
        .replace(/\n/g,            '<br>');
}

function escaparHtml(texto) {
    return texto
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ============================================================
// Mostra/oculta indicador de digitação
// ============================================================
function mostrarDigitacao() {
    indicador.style.display = 'flex';
    rolarParaBaixo();
}

function ocultarDigitacao() {
    indicador.style.display = 'none';
}

// ============================================================
// Rola para o fundo da janela
// ============================================================
function rolarParaBaixo() {
    setTimeout(() => {
        janela.scrollTop = janela.scrollHeight;
    }, 50);
}

// ============================================================
// ENVIAR MENSAGEM — função principal
// ============================================================
async function enviarMensagem() {
    const texto = campo.value.trim();
    if (!texto) return;

    // Limpa o campo e desactiva o botão
    campo.value = '';
    campo.style.height = 'auto';
    btnEnviar.disabled = true;
    campo.disabled = true;

    // Mostra a mensagem do utilizador
    adicionarMensagem(texto, 'user');

    // Mostra indicador de digitação
    mostrarDigitacao();

    try {
        const resposta = await fetch('api_chat.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                mensagem:  texto,
                id_sessao: ID_SESSAO,
            }),
        });

        const dados = await resposta.json();
        ocultarDigitacao();

        if (dados.sucesso) {
            adicionarMensagem(dados.dados.resposta, 'bot');
        } else {
            adicionarMensagem(dados.erro || 'Ocorreu um erro. Tenta novamente.', 'erro');
        }

    } catch (erro) {
        ocultarDigitacao();
        adicionarMensagem('Não foi possível contactar o servidor. Verifica a tua ligação.', 'erro');
        console.error('Erro ao enviar mensagem:', erro);
    } finally {
        campo.disabled = false;
        campo.focus();
    }
}

// ============================================================
// LIMPAR CHAT
// ============================================================
function limparChat() {
    if (!confirm('Limpar toda a conversa?')) return;

    // Remove todas as mensagens excepto a de boas-vindas
    const mensagens = janela.querySelectorAll('.mensagem:not(#msg-boas-vindas)');
    mensagens.forEach(m => m.remove());

    // Recria a mensagem de boas-vindas se foi removida
    if (!document.getElementById('msg-boas-vindas')) {
        location.reload();
    }

    // Nova sessão
    sessionStorage.removeItem('chatbot_sessao');
    location.reload();
}