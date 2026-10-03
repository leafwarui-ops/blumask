(() => {
  const images = document.querySelectorAll('img.post-image, img.comment-image');
  if (!images.length) return;

  const dialog = document.createElement('dialog');
  dialog.className = 'image-lightbox';
  dialog.setAttribute('aria-label', 'Visualização ampliada da imagem');

  const closeButton = document.createElement('button');
  closeButton.className = 'image-lightbox-close';
  closeButton.type = 'button';
  closeButton.setAttribute('aria-label', 'Fechar imagem ampliada');
  closeButton.textContent = '×';

  const image = document.createElement('img');
  image.className = 'image-lightbox-image';
  image.alt = '';

  const zoomControls = document.createElement('div');
  zoomControls.className = 'image-lightbox-zoom-controls';
  zoomControls.setAttribute('aria-label', 'Controles de zoom');

  const zoomOutButton = document.createElement('button');
  zoomOutButton.type = 'button';
  zoomOutButton.setAttribute('aria-label', 'Diminuir zoom');
  zoomOutButton.textContent = '−';

  const zoomLevel = document.createElement('span');
  zoomLevel.className = 'image-lightbox-zoom-level';
  zoomLevel.setAttribute('aria-live', 'polite');

  const zoomInButton = document.createElement('button');
  zoomInButton.type = 'button';
  zoomInButton.setAttribute('aria-label', 'Aumentar zoom');
  zoomInButton.textContent = '+';

  const resetZoomButton = document.createElement('button');
  resetZoomButton.type = 'button';
  resetZoomButton.setAttribute('aria-label', 'Redefinir zoom');
  resetZoomButton.textContent = 'Redefinir';

  zoomControls.append(zoomOutButton, zoomLevel, zoomInButton, resetZoomButton);
  dialog.append(closeButton, image, zoomControls);
  document.body.appendChild(dialog);

  let zoom = 1;
  let panX = 0;
  let panY = 0;
  let dragStart = null;
  let dragged = false;
  let previousBodyStyles = null;
  let opener = null;
  let scrollPosition = { x: 0, y: 0 };

  const setZoom = (nextZoom) => {
    zoom = Math.min(3, Math.max(1, nextZoom));
    if (zoom === 1) {
      panX = 0;
      panY = 0;
    }
    image.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
    zoomLevel.textContent = `${Math.round(zoom * 100)}%`;
    zoomOutButton.disabled = zoom <= 1;
    zoomInButton.disabled = zoom >= 3;
    image.style.cursor = zoom > 1 ? (dragStart ? 'grabbing' : 'grab') : 'zoom-in';
  };

  const openImage = (source) => {
    opener = source;
    scrollPosition = { x: window.scrollX, y: window.scrollY };
    image.src = source.currentSrc || source.src;
    image.alt = source.alt || 'Imagem ampliada';
    setZoom(1);
    previousBodyStyles = ['position', 'top', 'left', 'width', 'overflow'].map((property) => ({
      property,
      value: document.body.style.getPropertyValue(property),
      priority: document.body.style.getPropertyPriority(property)
    }));
    document.body.style.position = 'fixed';
    document.body.style.top = `-${scrollPosition.y}px`;
    document.body.style.left = `-${scrollPosition.x}px`;
    document.body.style.width = '100%';
    document.body.style.overflow = 'hidden';
    dialog.showModal();
    closeButton.focus();
  };

  const closeDialog = () => {
    dialog.close();
    image.removeAttribute('src');
    setZoom(1);
  };

  images.forEach((source) => {
    source.tabIndex = 0;
    source.setAttribute('role', 'button');
    source.setAttribute('aria-label', `Ampliar imagem: ${source.alt || 'imagem anexada'}`);
  });

  document.addEventListener('click', (event) => {
    const source = event.target.closest('img.post-image, img.comment-image');
    if (!source || source.closest('.image-lightbox')) return;
    event.preventDefault();
    event.stopPropagation();
    openImage(source);
  }, true);

  document.addEventListener('keydown', (event) => {
    const source = event.target.closest?.('img.post-image, img.comment-image');
    if (!source || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    event.stopPropagation();
    openImage(source);
  }, true);

  closeButton.addEventListener('click', closeDialog);
  zoomInButton.addEventListener('click', () => setZoom(zoom + 0.5));
  zoomOutButton.addEventListener('click', () => setZoom(zoom - 0.5));
  resetZoomButton.addEventListener('click', () => setZoom(1));
  image.addEventListener('click', () => {
    if (dragged) {
      dragged = false;
      return;
    }
    setZoom(zoom === 1 ? 2 : 1);
  });
  image.addEventListener('wheel', (event) => {
    event.preventDefault();
    setZoom(zoom + (event.deltaY < 0 ? 0.25 : -0.25));
  }, { passive: false });
  image.addEventListener('pointerdown', (event) => {
    if (zoom <= 1) return;
    dragStart = { x: event.clientX - panX, y: event.clientY - panY };
    image.setPointerCapture(event.pointerId);
    setZoom(zoom);
    event.preventDefault();
  });
  image.addEventListener('pointermove', (event) => {
    if (!dragStart) return;
    if (Math.abs(event.movementX) + Math.abs(event.movementY) > 0) dragged = true;
    panX = event.clientX - dragStart.x;
    panY = event.clientY - dragStart.y;
    setZoom(zoom);
  });
  const stopDragging = () => {
    dragStart = null;
    setZoom(zoom);
  };
  image.addEventListener('pointerup', stopDragging);
  image.addEventListener('pointercancel', stopDragging);
  image.addEventListener('dragstart', (event) => event.preventDefault());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closeDialog();
  });
  dialog.addEventListener('close', () => {
    image.removeAttribute('src');
    window.requestAnimationFrame(() => {
      if (previousBodyStyles) {
        previousBodyStyles.forEach(({ property, value, priority }) => {
          if (value) {
            document.body.style.setProperty(property, value, priority);
          } else {
            document.body.style.removeProperty(property);
          }
        });
      }
      window.scrollTo(scrollPosition.x, scrollPosition.y);
      if (opener?.isConnected) opener.focus({ preventScroll: true });
    });
  });

  setZoom(1);
})();
