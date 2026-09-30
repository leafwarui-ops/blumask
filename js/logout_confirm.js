(() => {
  const style = document.createElement('style');
  style.textContent = `
    .logout-confirm-overlay {
      position: fixed;
      inset: 0;
      z-index: 12000;
      display: grid;
      place-items: center;
      padding: 20px;
      background: rgba(15, 29, 48, .56);
      backdrop-filter: blur(3px);
      animation: logoutOverlayIn .16s ease-out;
    }
    .logout-confirm-dialog {
      width: min(100%, 390px);
      padding: 26px;
      border: 1px solid rgba(35, 65, 105, .1);
      border-radius: 16px;
      background: #fff;
      color: #1d2a39;
      box-shadow: 0 22px 60px rgba(8, 20, 38, .28);
      animation: logoutDialogIn .2s ease-out;
    }
    .logout-confirm-dialog h2 {
      margin: 0 0 22px;
      font-size: 1.2rem;
      line-height: 1.35;
      text-align: center;
    }
    .logout-confirm-actions {
      display: flex;
      justify-content: center;
      gap: 10px;
      margin-top: 24px;
    }
    .logout-confirm-actions button {
      min-height: 40px;
      padding: 9px 16px;
      border: 1px solid transparent;
      border-radius: 9px;
      font: inherit;
      font-size: .9rem;
      font-weight: 700;
      cursor: pointer;
      transition: background-color .15s ease, transform .15s ease;
    }
    .logout-confirm-actions button:hover { transform: translateY(-1px); }
    .logout-confirm-cancel {
      border-color: #dbe3ee !important;
      background: #fff;
      color: #42536a;
    }
    .logout-confirm-cancel:hover { background: #f5f8fc; }
    .logout-confirm-accept {
      background: #d94343;
      color: #fff;
    }
    .logout-confirm-accept:hover { background: #bd3434; }
    .logout-confirm-actions button:focus-visible {
      outline: 3px solid rgba(86, 127, 217, .35);
      outline-offset: 2px;
    }
    @keyframes logoutOverlayIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes logoutDialogIn { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    @media (prefers-reduced-motion: reduce) {
      .logout-confirm-overlay, .logout-confirm-dialog { animation: none; }
      .logout-confirm-actions button { transition: none; }
    }
  `;
  document.head.appendChild(style);

  function openLogoutDialog(link) {
    const overlay = document.createElement('div');
    overlay.className = 'logout-confirm-overlay';
    overlay.setAttribute('role', 'presentation');

    const dialog = document.createElement('section');
    dialog.className = 'logout-confirm-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'logoutConfirmTitle');

    const title = document.createElement('h2');
    title.id = 'logoutConfirmTitle';
    title.textContent = 'Tem certeza que quer sair?';

    const actions = document.createElement('div');
    actions.className = 'logout-confirm-actions';

    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'logout-confirm-cancel';
    cancelButton.textContent = 'Cancelar';

    const acceptButton = document.createElement('button');
    acceptButton.type = 'button';
    acceptButton.className = 'logout-confirm-accept';
    acceptButton.textContent = 'Sair';

    actions.append(cancelButton, acceptButton);
    dialog.append(title, actions);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    const close = () => {
      document.removeEventListener('keydown', handleKeydown, true);
      overlay.remove();
      link.focus();
    };

    const handleKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
      }
      if (event.key === 'Tab') {
        const firstButton = cancelButton;
        const lastButton = acceptButton;
        if (event.shiftKey && document.activeElement === firstButton) {
          event.preventDefault();
          lastButton.focus();
        } else if (!event.shiftKey && document.activeElement === lastButton) {
          event.preventDefault();
          firstButton.focus();
        }
      }
    };

    cancelButton.addEventListener('click', close);
    acceptButton.addEventListener('click', () => window.location.assign(link.href));
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) close();
    });
    document.addEventListener('keydown', handleKeydown, true);
    cancelButton.focus();
  }

  document.querySelectorAll('a[href*="logout=1"]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      openLogoutDialog(link);
    });
  });
})();
