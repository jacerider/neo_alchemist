(function () {

  const id = new URLSearchParams(window.location.search).get('id');
  const size = new URLSearchParams(window.location.search).get('size');
  const origin = window.location.origin;

  // Short components are stretched up to this landscape aspect (width:height) so
  // they read as a clean banner in the library card with little hover-scroll.
  // Taller components keep their natural height, so the card's hover-pan reveals
  // exactly as much as the component actually has — no more, no less.
  const LANDSCAPE_FLOOR = 16 / 9;
  const OUTPUT_WIDTH = 800;
  const DEFAULT_WIDTH = 1024;
  const MIN_PREVIEW_WIDTH = 360;
  const MAX_PREVIEW_WIDTH = 1440;

  // Capture-mode state.
  let active = false;
  let requestId = '';
  let previewWidth = DEFAULT_WIDTH;
  // Vertical alignment of a stretched component's content: top | center | bottom.
  let valign = 'center';
  // The component's own rendered height at the current width, ignoring the
  // capture-mode min-height floor. Cached so control changes don't thrash layout.
  let naturalHeight = 0;
  let wrapper: HTMLElement | null = null;
  let componentEl: HTMLElement | null = null;
  let savedWrapperStyle: string | null = null;
  let savedComponentStyle: string | null = null;

  const post = (message: Record<string, unknown>, reqId: string): void => {
    window.parent.postMessage(Object.assign({
      id: id,
      size: size,
      requestId: reqId,
    }, message), origin);
  };

  const componentWidth = (): number => (componentEl ? componentEl.offsetWidth : previewWidth);

  const floorHeight = (): number => componentWidth() / LANDSCAPE_FLOOR;

  // True when the landscape floor is taller than the component's own content,
  // i.e. the component is being stretched to fill the frame.
  const isStretched = (): boolean => naturalHeight < floorHeight();

  // Measure how tall the component is on its own — including its own min-height
  // (e.g. a hero's min-h-208) — with our stretch override removed.
  const measureNaturalHeight = (): void => {
    if (!componentEl) {
      naturalHeight = 0;
      return;
    }
    componentEl.style.removeProperty('min-height');
    naturalHeight = componentEl.offsetHeight;
  };

  // Stretch only when the landscape floor is taller than the component's own
  // content. Applied as an inline min-height so it adds to — never replaces —
  // the component's intrinsic height; removed otherwise so tall components keep
  // their natural layout untouched.
  const applyFloor = (): void => {
    if (!componentEl) {
      return;
    }
    if (isStretched()) {
      componentEl.style.minHeight = Math.round(floorHeight()) + 'px';
    }
    else {
      componentEl.style.removeProperty('min-height');
    }
  };

  // Vertical alignment only has meaning while the component is stretched — there
  // is no empty space to distribute otherwise — so the flex class is applied
  // only then, leaving natural-height components on their own block layout.
  const applyValign = (): void => {
    if (!wrapper) {
      return;
    }
    const stretched = isStretched();
    wrapper.classList.toggle('is-valign-center', stretched && valign === 'center');
    wrapper.classList.toggle('is-valign-bottom', stretched && valign === 'bottom');
  };

  const setValign = (value: string): void => {
    valign = value === 'top' || value === 'bottom' ? value : 'center';
    applyValign();
  };

  const setPreviewWidth = (px: number): void => {
    previewWidth = Math.max(MIN_PREVIEW_WIDTH, Math.min(MAX_PREVIEW_WIDTH, Math.round(px)));
    if (wrapper) {
      wrapper.style.setProperty('--capture-width', previewWidth + 'px');
    }
    // The component reflows at the new width; re-measure, re-floor, re-align.
    measureNaturalHeight();
    applyFloor();
    applyValign();
  };

  const onKeydown = (e: KeyboardEvent): void => {
    if (e.key === 'Escape') {
      cancelCapture();
    }
    else if (e.key === 'Enter') {
      doCapture();
    }
  };

  const enterCaptureMode = (reqId: string): void => {
    wrapper = document.querySelector('.neo-alchemist-preview');
    componentEl = wrapper ? wrapper.querySelector('[data-component-id]') : null;
    if (!wrapper || !componentEl) {
      post({ type: 'thumbnailCaptureError', message: 'Preview component not found.' }, reqId);
      return;
    }
    if (typeof snapdom === 'undefined') {
      post({ type: 'thumbnailCaptureError', message: 'The snapdom capture library failed to load.' }, reqId);
      return;
    }
    active = true;
    requestId = reqId;
    previewWidth = DEFAULT_WIDTH;
    valign = 'center';
    savedWrapperStyle = wrapper.getAttribute('style');
    savedComponentStyle = componentEl.getAttribute('style');
    wrapper.classList.add('neo-alchemist-capture-mode');
    wrapper.style.setProperty('--capture-width', previewWidth + 'px');
    measureNaturalHeight();
    applyFloor();
    applyValign();
    document.addEventListener('keydown', onKeydown);
    // Tell the parent to raise its toolbar and how to configure the width slider.
    post({
      type: 'thumbnailCaptureReady',
      width: previewWidth,
      minWidth: MIN_PREVIEW_WIDTH,
      maxWidth: MAX_PREVIEW_WIDTH,
      valign: valign,
    }, reqId);
  };

  const exitCaptureMode = (): void => {
    if (componentEl) {
      if (savedComponentStyle === null) {
        componentEl.removeAttribute('style');
      }
      else {
        componentEl.setAttribute('style', savedComponentStyle);
      }
    }
    if (wrapper) {
      wrapper.classList.remove('neo-alchemist-capture-mode', 'is-valign-center', 'is-valign-bottom');
      if (savedWrapperStyle === null) {
        wrapper.removeAttribute('style');
      }
      else {
        wrapper.setAttribute('style', savedWrapperStyle);
      }
    }
    wrapper = null;
    componentEl = null;
    savedWrapperStyle = null;
    savedComponentStyle = null;
    document.removeEventListener('keydown', onKeydown);
    active = false;
    requestId = '';
  };

  const cancelCapture = (): void => {
    if (!active) {
      return;
    }
    const reqId = requestId;
    exitCaptureMode();
    post({ type: 'thumbnailCaptureCancel' }, reqId);
  };

  const doCapture = async (): Promise<void> => {
    if (!active) {
      return;
    }
    const reqId = requestId;
    const target = componentEl;
    if (!target) {
      exitCaptureMode();
      post({ type: 'thumbnailCaptureError', message: 'Preview component not found.' }, reqId);
      return;
    }
    try {
      // Rasterize the whole component (stretched to the floor when short) and
      // scale so the stored image is OUTPUT_WIDTH wide.
      const scale = Math.min(2, OUTPUT_WIDTH / Math.max(1, target.offsetWidth));
      const canvas = await snapdom.toCanvas(target, {
        scale: scale,
        dpr: 1,
        embedFonts: true,
        compress: true,
        fast: true,
        backgroundColor: '#ffffff',
      });
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
      if (!blob) {
        throw new Error('The capture produced no image data.');
      }
      exitCaptureMode();
      post({ type: 'thumbnailCaptureResult', blob: blob, width: canvas.width, height: canvas.height }, reqId);
    }
    catch (error) {
      exitCaptureMode();
      post({ type: 'thumbnailCaptureError', message: String(error) }, reqId);
    }
  };

  const captureComponents = async (reqId: string): Promise<void> => {
    const components = document.querySelectorAll('[data-component-uuid]') as NodeListOf<HTMLElement>;
    const images: Record<string, string> = {};
    await Promise.allSettled(Array.from(components).map(async (component) => {
      const componentUuid = component.dataset.componentUuid;
      if (!componentUuid || typeof snapdom === 'undefined') {
        return;
      }
      const scale = Math.min(1, 400 / Math.max(1, component.offsetWidth));
      const canvas = await snapdom.toCanvas(component, {
        scale: scale,
        dpr: 1,
        compress: true,
        fast: true,
        backgroundColor: '#ffffff',
      });
      images[componentUuid] = canvas.toDataURL('image/png');
    }));
    post({ type: 'screenshotComponents', images: images }, reqId);
  };

  window.addEventListener('message', (e) => {
    if (e.origin !== origin) {
      return;
    }
    const data = e.data;
    if (!data || typeof data.type !== 'string') {
      return;
    }
    const reqId = typeof data.requestId === 'string' ? data.requestId : '';
    switch (data.type) {
      case 'screenshotComponents':
        captureComponents(reqId);
        break;
      case 'thumbnailCaptureStart':
        if (!active) {
          enterCaptureMode(reqId);
        }
        break;
      case 'thumbnailCaptureWidth':
        if (active && typeof data.width === 'number') {
          setPreviewWidth(data.width);
        }
        break;
      case 'thumbnailCaptureValign':
        if (active && typeof data.value === 'string') {
          setValign(data.value);
        }
        break;
      case 'thumbnailCaptureCommit':
        if (active) {
          doCapture();
        }
        break;
      case 'thumbnailCaptureAbort':
        cancelCapture();
        break;
    }
  });

})();
