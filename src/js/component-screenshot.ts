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
  const DEFAULT_WIDTH = 1440;
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

  // The alignment declarations applyValign() may set. Cleared before measuring
  // so a natural height is always read against the component's own layout.
  const ALIGN_PROPS = ['display', 'flex-direction', 'justify-content', 'align-items', 'align-content'];

  const clearAlignment = (): void => {
    if (componentEl) {
      ALIGN_PROPS.forEach((prop) => componentEl!.style.removeProperty(prop));
    }
  };

  // Measure how tall the component is on its own — including its own min-height
  // (e.g. a hero's min-h-208) — with our stretch override removed.
  const measureNaturalHeight = (): void => {
    if (!componentEl) {
      naturalHeight = 0;
      return;
    }
    clearAlignment();
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
  // is no empty space to distribute otherwise — so nothing is applied
  // otherwise, leaving natural-height components entirely untouched.
  //
  // The component's own formatting context has to survive this. Imposing
  // `display: flex; flex-direction: column` centers a block-flow section
  // correctly but destroys any component whose root is already a flex row — a
  // header's logo and utility nav stack on top of each other instead of
  // sitting side by side. So the alignment is expressed in whatever terms the
  // root already uses, and its display is only set when it has none of its own.
  const applyValign = (): void => {
    if (!componentEl) {
      return;
    }
    clearAlignment();
    if (!isStretched()) {
      return;
    }
    const style = window.getComputedStyle(componentEl);
    const display = style.display;
    if (display === 'flex' || display === 'inline-flex') {
      // A row lays its children out along the inline axis, so the free space
      // is distributed by align-items; a column uses justify-content.
      const prop = style.flexDirection.startsWith('row') ? 'alignItems' : 'justifyContent';
      componentEl.style[prop] = valign === 'top' ? 'flex-start' : valign === 'bottom' ? 'flex-end' : 'center';
    }
    else if (display === 'grid' || display === 'inline-grid') {
      componentEl.style.alignContent = valign === 'top' ? 'start' : valign === 'bottom' ? 'end' : 'center';
    }
    else {
      componentEl.style.display = 'flex';
      componentEl.style.flexDirection = 'column';
      componentEl.style.justifyContent = valign === 'top' ? 'flex-start' : valign === 'bottom' ? 'flex-end' : 'center';
    }
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

  let snapdomLoader: Promise<void> | null = null;

  /**
   * Fetch snapdom the first time a capture actually needs it.
   *
   * It is ~140KB of JS whose only job is rasterising, and the frame it used to
   * be attached to is the desktop preview — the one an editor sits in all day.
   * Every component open paid for it to serve a button most opens never press.
   *
   * @see neo_alchemist_attach_screenshot()
   */
  const loadSnapdom = (): Promise<void> => {
    if (typeof snapdom !== 'undefined') {
      return Promise.resolve();
    }
    if (snapdomLoader) {
      return snapdomLoader;
    }
    const url = (window as any).drupalSettings?.neoAlchemist?.snapdomUrl;
    if (!url) {
      return Promise.reject(new Error('No snapdom URL was provided.'));
    }
    snapdomLoader = new Promise<void>((resolve, reject) => {
      const script = document.createElement('script');
      script.src = url;
      script.onload = () => {
        // A 200 carrying the wrong thing still fires onload.
        typeof snapdom === 'undefined'
          ? reject(new Error('snapdom did not define itself.'))
          : resolve();
      };
      script.onerror = () => reject(new Error('snapdom could not be fetched.'));
      document.head.appendChild(script);
    });
    // Let a later capture retry rather than inheriting this failure forever.
    snapdomLoader.catch(() => {
      snapdomLoader = null;
    });
    return snapdomLoader;
  };

  const enterCaptureMode = (reqId: string): void => {
    wrapper = document.querySelector('.neo-alchemist-preview');
    componentEl = wrapper ? wrapper.querySelector('[data-component-id]') : null;
    if (!wrapper || !componentEl) {
      post({ type: 'thumbnailCaptureError', message: 'Preview component not found.' }, reqId);
      return;
    }
    loadSnapdom()
      .then(() => beginCaptureMode(reqId))
      .catch(() => {
        post({ type: 'thumbnailCaptureError', message: 'The snapdom capture library failed to load.' }, reqId);
      });
  };

  const beginCaptureMode = (reqId: string): void => {
    if (!wrapper || !componentEl) {
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
      wrapper.classList.remove('neo-alchemist-capture-mode');
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

  /**
   * Resample a canvas to a target width, preserving its aspect ratio.
   *
   * Kept separate from rasterizing so the component is always laid out at its
   * real width — resizing pixels cannot change where anything wrapped.
   * Halving in steps when shrinking a long way keeps thin text from breaking
   * up, which a single large downscale does.
   */
  const resizeCanvas = (source: HTMLCanvasElement, targetWidth: number): HTMLCanvasElement => {
    if (!source.width || !source.height || source.width === targetWidth) {
      return source;
    }
    let current = source;
    while (current.width > targetWidth * 2) {
      current = drawTo(current, Math.round(current.width / 2));
    }
    return drawTo(current, targetWidth);
  };

  const drawTo = (source: HTMLCanvasElement, width: number): HTMLCanvasElement => {
    const out = document.createElement('canvas');
    out.width = width;
    out.height = Math.max(1, Math.round(source.height * (width / source.width)));
    const context = out.getContext('2d');
    if (!context) {
      return source;
    }
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(source, 0, 0, out.width, out.height);
    return out;
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
      // Rasterize the whole component (stretched to the floor when short) at
      // its native layout, then resize the bitmap to OUTPUT_WIDTH.
      //
      // snapdom's `scale` lays the clone out at the scaled size rather than
      // just rasterizing it smaller, so any value below 1 changes where the
      // component wraps — a three-column footer nav captured at 1440px came
      // back as two columns because the clone was laid out at 800px. `dpr`
      // multiplies the raster resolution without touching layout, so it is
      // what carries detail for components narrower than the output.
      const width = Math.max(1, target.offsetWidth);
      const canvas = await snapdom.toCanvas(target, {
        scale: 1,
        dpr: Math.min(2, Math.max(1, OUTPUT_WIDTH / width)),
        embedFonts: true,
        compress: true,
        fast: true,
        backgroundColor: '#ffffff',
      });
      const output = resizeCanvas(canvas, OUTPUT_WIDTH);
      const blob = await new Promise<Blob | null>((resolve) => output.toBlob(resolve, 'image/png'));
      if (!blob) {
        throw new Error('The capture produced no image data.');
      }
      exitCaptureMode();
      post({ type: 'thumbnailCaptureResult', blob: blob, width: output.width, height: output.height }, reqId);
    }
    catch (error) {
      exitCaptureMode();
      post({ type: 'thumbnailCaptureError', message: String(error) }, reqId);
    }
  };

  /**
   * Rasterize placed components for the reorder dialog.
   *
   * Strictly one at a time. snapdom reparents the node it is capturing, and
   * these are nested — a region's children sit inside the component that owns
   * the region — so overlapping captures running together tear each other's
   * subtree out mid-rasterize. The visible symptom was a component losing its
   * background while keeping its text, which for a dark colour scheme means
   * near-white text on white: an apparently blank thumbnail.
   *
   * `uuids` narrows the batch to the rows the dialog is actually showing, which
   * is both less work and, since a dialog only ever lists the children of one
   * container, enough on its own to keep parent and descendant out of the same
   * batch. Falls back to the whole tree so an older caller still works.
   */
  const captureComponents = async (reqId: string, uuids?: string[], boxWidth?: number, boxHeight?: number): Promise<void> => {
    const images: Record<string, string> = {};
    try {
      await loadSnapdom();
    }
    catch (e) {
      post({ type: 'screenshotComponents', images: images }, reqId);
      return;
    }
    const wanted = Array.isArray(uuids) && uuids.length ? uuids : null;
    const components = Array.from(
      document.querySelectorAll('[data-component-uuid]') as NodeListOf<HTMLElement>
    ).filter(component => {
      const uuid = component.dataset.componentUuid;
      return !!uuid && (!wanted || wanted.includes(uuid));
    });
    const boxW = Math.max(1, boxWidth || 400);
    const boxH = Math.max(1, boxHeight || 240);
    for (const component of components) {
      const componentUuid = component.dataset.componentUuid as string;
      try {
        // Enough pixels to cover the box, not merely to match its width. The
        // dialog draws these with object-cover, so a component wider than the
        // box's aspect gets scaled up until its *height* fills — capturing at
        // the box width alone left a wide hero upscaled ~2.3x and smeared.
        // Capped at native, and at a ceiling so a full-bleed component does not
        // rasterize enormous.
        const aspect = component.offsetWidth / Math.max(1, component.offsetHeight);
        const needed = Math.min(2400, Math.max(boxW, boxH * aspect));
        // Rasterize at the component's real width and resample down, never via
        // snapdom's `scale` — that lays the clone out at the reduced size, so a
        // component would wrap differently here than it does on the canvas.
        const canvas = await snapdom.toCanvas(component, {
          scale: 1,
          dpr: 1,
          // As the persisted-thumbnail capture does: without it the text falls
          // back to whatever the rasterizer has, which is never the real face.
          embedFonts: true,
          compress: true,
          fast: true,
          backgroundColor: '#ffffff',
        });
        const target = Math.max(1, Math.round(Math.min(needed, component.offsetWidth)));
        images[componentUuid] = resizeCanvas(canvas, target).toDataURL('image/png');
      }
      catch (err) {
        // One unrasterizable component must not cost the dialog every other
        // thumbnail; the row keeps its server-rendered fallback.
      }
    }
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
        captureComponents(
          reqId,
          Array.isArray(data.uuids) ? data.uuids : undefined,
          typeof data.width === 'number' ? data.width : undefined,
          typeof data.height === 'number' ? data.height : undefined
        );
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
