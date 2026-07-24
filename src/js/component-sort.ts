(function (Drupal, once) {

  // A single listener at script scope: the sort dialog can be opened multiple
  // times, and registering per-open would stack duplicate handlers.
  let pendingRequestId = '';

  window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin) {
      return;
    }
    const data = e.data;
    if (!pendingRequestId || !data || data.type !== 'screenshotComponents' || data.requestId !== pendingRequestId) {
      return;
    }
    pendingRequestId = '';
    if (!data.images || typeof data.images !== 'object' || Array.isArray(data.images)) {
      console.warn('screenshotComponents: expected data.images to be an object', data.images);
      return;
    }
    const images = data.images as Record<string, unknown>;
    Object.entries(images).forEach(([componentUuid, imageDataUrl]) => {
      const thumbnail = document.querySelector('[data-component-sort-uuid="' + componentUuid + '"] .thumbnail') as HTMLElement;
      if (!thumbnail) {
        console.warn('Thumbnail element not found for component UUID:', componentUuid);
        return;
      }
      const img = document.createElement('img');
      img.src = imageDataUrl as string;
      img.style.width = '200px';
      img.style.height = 'auto';
      img.classList.add('border', 'border-base-300', 'shadow-sm', 'rounded');
      thumbnail.innerHTML = '';
      thumbnail.appendChild(img);
    });
  });

  Drupal.behaviors.neoAlchemistInstanceComponentSort = {
    attach: function () {
      once('neo.alchemist.sort', '.neo-component-sort-form').forEach(_el => {
        buildThumbnails();
      });
    }
  };

  const buildThumbnails = () => {
    if (Drupal.neoAlchemist && Drupal.neoAlchemist.iframes && Drupal.neoAlchemist.iframes.desktop) {
      const iframe = Drupal.neoAlchemist.iframes.desktop;
      if (!iframe.contentWindow) {
        return;
      }
      pendingRequestId = window.crypto && 'randomUUID' in window.crypto
        ? window.crypto.randomUUID()
        : Date.now() + '-' + Math.random();
      iframe.contentWindow.postMessage({
        type: 'screenshotComponents',
        requestId: pendingRequestId,
      }, window.location.origin);
    }
  };

})(Drupal, once);
