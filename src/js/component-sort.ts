(function (Drupal, once) {

  // A single listener at script scope: the sort dialog can be opened multiple
  // times, and registering per-open would stack duplicate handlers.
  let pendingRequestId = '';
  let retryTimeout: ReturnType<typeof setTimeout> | null = null;

  const stopRetrying = (): void => {
    if (retryTimeout !== null) {
      clearTimeout(retryTimeout);
      retryTimeout = null;
    }
  };

  window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin) {
      return;
    }
    const data = e.data;
    if (!pendingRequestId || !data || data.type !== 'screenshotComponents' || data.requestId !== pendingRequestId) {
      return;
    }
    pendingRequestId = '';
    stopRetrying();
    if (!data.images || typeof data.images !== 'object' || Array.isArray(data.images)) {
      console.warn('screenshotComponents: expected data.images to be an object', data.images);
      return;
    }
    const images = data.images as Record<string, unknown>;
    Object.entries(images).forEach(([componentUuid, imageDataUrl]) => {
      // Swap the source on the placeholder the form rendered rather than
      // replacing it. The row already reserves its box and shows the component
      // type's thumbnail, so upgrading it to this instance's real appearance
      // costs no reflow and never leaves a gap if a capture is missing.
      const img = document.querySelector(
        '[data-component-sort-uuid="' + componentUuid + '"] .thumbnail img'
      ) as HTMLImageElement | null;
      if (!img) {
        console.warn('Thumbnail element not found for component UUID:', componentUuid);
        return;
      }
      img.src = imageDataUrl as string;
    });
  });

  Drupal.behaviors.neoAlchemistInstanceComponentSort = {
    attach: function () {
      once('neo.alchemist.sort', '.neo-component-sort-form').forEach(el => {
        requestThumbnails(el as HTMLElement, 0);
      });
    }
  };

  /**
   * Ask the desktop preview to rasterize the components this dialog lists.
   *
   * Retried, because the dialog can be opened before the preview frame has
   * registered its listener and a dropped postMessage is silent — a single
   * fire-and-forget attempt left the rows on their fallback image forever.
   * Bounded, because the standalone /sort routes have no preview frame at all
   * and are supposed to keep the fallback.
   */
  const requestThumbnails = (form: HTMLElement, attempt: number): void => {
    stopRetrying();
    const iframe = Drupal.neoAlchemist?.iframes?.desktop;
    // No editor around this dialog, and none is coming: the preview frames are
    // registered on page load, long before anything can open this. Retrying
    // would only burn timers, so leave the rows on their fallback.
    if (!iframe) {
      return;
    }
    if (iframe.contentWindow) {
      const uuids = Array.from(form.querySelectorAll('[data-component-sort-uuid]'))
        .map(row => (row as HTMLElement).dataset.componentSortUuid)
        .filter((uuid): uuid is string => !!uuid);
      // Both dimensions, in device pixels: the preview needs the box's aspect
      // to work out how many pixels object-cover will actually demand of it.
      const box = form.querySelector('.thumbnail')?.getBoundingClientRect();
      const dpr = window.devicePixelRatio || 1;
      pendingRequestId = window.crypto && 'randomUUID' in window.crypto
        ? window.crypto.randomUUID()
        : Date.now() + '-' + Math.random();
      iframe.contentWindow.postMessage({
        type: 'screenshotComponents',
        requestId: pendingRequestId,
        uuids: uuids,
        width: Math.round((box?.width || 200) * dpr),
        height: Math.round((box?.height || 120) * dpr),
      }, window.location.origin);
    }
    // A message posted before the frame registered its listener is dropped
    // silently, so a single attempt could leave the rows on their fallback
    // forever. Backs off, and stops as soon as a batch arrives.
    if (attempt < 4) {
      retryTimeout = setTimeout(() => {
        retryTimeout = null;
        if (pendingRequestId || !iframe.contentWindow) {
          requestThumbnails(form, attempt + 1);
        }
      }, 500 * (attempt + 1));
    }
  };

})(Drupal, once);
