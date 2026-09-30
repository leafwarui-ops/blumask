(() => {
  const scriptUrl = document.currentScript?.src;
  if (!scriptUrl) return;

  const endpoint = new URL('../php/admin_message_api.php', scriptUrl);
  const style = document.createElement('style');
  style.textContent = `
    .admin-inbox-overlay {
      position: fixed;
      inset: 0;
      z-index: 10000;
      display: grid;
      place-items: center;
      padding: 20px;
      background: rgba(16, 29, 48, 0.62);
    }
    .admin-inbox-dialog {
      position: relative;
      width: min(100%, 460px);
      padding: 28px;
      border-radius: 16px;
      background: #fff;
      color: #1d2a39;
      box-shadow: 0 24px 64px rgba(8, 20, 38, 0.28);
    }
    .admin-inbox-dialog h2 {
      margin: 0 40px 18px 0;
      color: #294c83;
      font-size: 1.1rem;
      line-height: 1.35;
    }
    .admin-inbox-text {
      margin: 0;
      line-height: 1.55;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }
    .admin-inbox-close {
      position: absolute;
      top: 12px;
      right: 12px;
      display: grid;
      width: 32px;
      height: 32px;
      place-items: center;
      border: 0;
      border-radius: 50%;
      background: transparent;
      color: #64748b;
      font-family: Arial, sans-serif;
      font-size: 1.35rem;
      font-weight: 400;
      line-height: 1;
      cursor: pointer;
      transition: background-color .15s ease, color .15s ease;
    }
    .admin-inbox-close:hover {
      background: #f1f5f9;
      color: #1d2a39;
    }
    .admin-inbox-close:focus-visible {
      outline: 2px solid #567fd9;
      outline-offset: 2px;
    }
    .admin-inbox-close[hidden] { display: none; }
    .admin-inbox-close:disabled { opacity: .55; cursor: wait; }
    .admin-inbox-status {
      min-height: 1.2em;
      margin: 14px 0 0;
      color: #b42318;
      font-size: .875rem;
    }
    @media (max-width: 520px) {
      .admin-inbox-dialog { padding: 24px 20px; }
    }
  `;
  document.head.appendChild(style);

  let activeMessageId = 0;
  let requestInProgress = false;

  async function fetchNextMessage() {
    if (document.hidden || activeMessageId || requestInProgress) return;
    requestInProgress = true;

    try {
      const response = await fetch(`${endpoint}?acao=proxima`, { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      if (data.sucesso && data.mensagem) showMessage(data.mensagem, data.csrf_token);
    } catch (error) {
      console.error('Não foi possível verificar mensagens administrativas.');
    } finally {
      requestInProgress = false;
    }
  }

  function showMessage(message, csrfToken) {
    activeMessageId = Number(message.id);

    const overlay = document.createElement('div');
    overlay.className = 'admin-inbox-overlay';
    overlay.setAttribute('role', 'presentation');

    const dialog = document.createElement('section');
    dialog.className = 'admin-inbox-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'adminInboxTitle');
    dialog.setAttribute('aria-describedby', 'adminInboxText');

    const title = document.createElement('h2');
    title.id = 'adminInboxTitle';
    title.textContent = 'Mensagem do Admin:';

    const text = document.createElement('p');
    text.className = 'admin-inbox-text';
    text.id = 'adminInboxText';
    text.textContent = message.texto;

    const closeButton = document.createElement('button');
    closeButton.className = 'admin-inbox-close';
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', 'Fechar mensagem');
    closeButton.title = 'Fechar mensagem';
    closeButton.textContent = '×';
    closeButton.hidden = true;

    const status = document.createElement('p');
    status.className = 'admin-inbox-status';
    status.setAttribute('aria-live', 'polite');

    dialog.append(title, text, closeButton, status);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    const preventEarlyDismiss = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
      }
    };
    document.addEventListener('keydown', preventEarlyDismiss, true);

    const closeTimer = window.setTimeout(() => {
      closeButton.hidden = false;
      closeButton.focus();
    }, 10000);

    closeButton.addEventListener('click', async () => {
      closeButton.disabled = true;
      status.textContent = '';

      try {
        const body = new URLSearchParams({
          acao: 'fechar',
          id_mensagem: String(activeMessageId),
          csrf_token: csrfToken
        });
        const response = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body
        });
        const data = await response.json();
        if (!response.ok || !data.sucesso) throw new Error('Não foi possível fechar a mensagem.');

        window.clearTimeout(closeTimer);
        document.removeEventListener('keydown', preventEarlyDismiss, true);
        overlay.remove();
        activeMessageId = 0;
        fetchNextMessage();
      } catch (error) {
        closeButton.disabled = false;
        status.textContent = 'Não foi possível fechar agora. Tente novamente.';
      }
    });
  }

  fetchNextMessage();
  window.setInterval(fetchNextMessage, 10000);
  document.addEventListener('visibilitychange', fetchNextMessage);
})();
