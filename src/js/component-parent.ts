(function (Drupal, once) {

  /**
   * Repaints that have to survive a partial AJAX rebuild.
   *
   * init() runs behind once(), but adding or removing an array row replaces
   * that whole fieldset — so anything the client had written into it is gone,
   * while the preview, which did not change, has nothing new to report. These
   * run on every attach to put it back.
   */
  const onFormRebuild: Array<() => void> = [];

  Drupal.behaviors.neoAlchemistComponentParent = {
    scale: 1,

    attach: function () {
      once('neo.alchemist.component.parent', '.neo-alchemist-manage').forEach(container => {
        init(container);
      });
      onFormRebuild.forEach(repaint => repaint());
    },

    scrollElementIntoView: function (
      element: HTMLElement|DOMRect,
      container: HTMLElement = document.documentElement,
      offset: number | { top?: number; bottom?: number; left?: number; right?: number } = 0,
      behavior: ScrollBehavior = 'smooth'
    ): void {
      // Get positions
      const containerRect = container.getBoundingClientRect();
      const elementRect = element instanceof DOMRect ? element : element.getBoundingClientRect();

      // Normalize offset to object
      const offsets = typeof offset === 'number'
        ? { top: offset, bottom: offset, left: offset, right: offset }
        : { top: 0, bottom: 0, left: 0, right: 0, ...offset };

      // Calculate positions
      const isRoot = container === document.documentElement;

      // Vertical calculations
      const elementTop = elementRect.top - (isRoot ? 0 : containerRect.top);
      const elementBottom = elementRect.bottom - (isRoot ? 0 : containerRect.top);
      const containerVisibleTop = isRoot ? 0 : offsets.top;
      const containerVisibleBottom = (isRoot ? window.innerHeight : containerRect.height) - offsets.bottom;

      // Horizontal calculations
      const elementLeft = elementRect.left - (isRoot ? 0 : containerRect.left);
      const elementRight = elementRect.right - (isRoot ? 0 : containerRect.left);
      const containerVisibleLeft = 0;
      const containerVisibleRight = (isRoot ? window.innerWidth : containerRect.width);

      // Calculate scroll values
      let scrollTop = container.scrollTop;
      let scrollLeft = container.scrollLeft;
      let needsScroll = false;

      // Determine if vertical scrolling is needed.
      //
      // All three cases land on the same answer — put the element's top at the
      // top inset — so they are asked as one question. An element taller than
      // the pane used to be a branch that did nothing, which meant a click on a
      // long field in the preview scrolled the form nowhere at all; there is no
      // position that shows all of such an element, and showing its start is
      // the one that lets you read down it.
      if (elementRect.height > containerRect.height
        || elementTop < containerVisibleTop
        || elementBottom > containerVisibleBottom) {
        scrollTop += elementTop - offsets.top;
        needsScroll = true;
      }

      // Determine if horizontal scrolling is needed
      if (containerRect.width < elementRect.width) {
        // We need to center the element
        const elementCenter = (elementLeft + elementRight) / 2;
        const containerCenter = (containerVisibleLeft + containerVisibleRight) / 2;
        const offsetLeft = elementCenter - containerCenter;
        scrollLeft += offsetLeft;
        needsScroll = true;
      } else if (elementLeft < containerVisibleLeft) {
        // Element is to the left of the visible area, scroll left
        scrollLeft += elementLeft - offsets.left;
        needsScroll = true;
      } else if (elementRight > containerVisibleRight) {
        // Element is to the right of the visible area, scroll right
        scrollLeft += elementRight - containerVisibleRight + offsets.right;
        needsScroll = true;
      }

      // Apply scroll if needed
      if (needsScroll) {
        container.scrollTo({
          top: scrollTop,
          left: scrollLeft,
          behavior
        });
      }
    }
  };

  /**
   * How much of a scroller's own box its pinned chrome is covering.
   *
   * The panel's header — title, state chips, tabs — is `sticky top-0` inside
   * the scrolling pane, and its footer is `sticky bottom-0`. Neither takes any
   * room out of the scroll box, so scrolling a field to the top of that box
   * parks it *under* the header: 118px of chrome over a field asked for by a
   * click. The insets have to come off the target position, and they have to be
   * measured rather than assumed — the header grows a row when the chips wrap
   * and the footer is not always there.
   *
   * Probed at the two edges rather than by walking the subtree, which answers
   * the question directly ("what is covering this point?") and cannot be fooled
   * by sticky content that is not currently pinned — a CKEditor toolbar sitting
   * mid-pane is `sticky top-0` too, and measuring it as chrome would inset the
   * pane by its full distance down the page.
   */
  function stickyInsets(scroller: HTMLElement): { top: number; bottom: number } {
    const box = scroller.getBoundingClientRect();
    const x = Math.round(box.left + box.width / 2);
    const pinnedAt = (y: number): HTMLElement[] =>
      (document.elementsFromPoint(x, y) as HTMLElement[]).filter(el => {
        if (el === scroller || !scroller.contains(el)) {
          return false;
        }
        const position = getComputedStyle(el).position;
        return position === 'sticky' || position === 'fixed';
      });

    let top = 0;
    pinnedAt(Math.round(box.top) + 1).forEach(el => {
      top = Math.max(top, el.getBoundingClientRect().bottom - box.top);
    });
    let bottom = 0;
    pinnedAt(Math.round(box.bottom) - 1).forEach(el => {
      bottom = Math.max(bottom, box.bottom - el.getBoundingClientRect().top);
    });
    return { top, bottom };
  }

  /**
   * Brings an element into the part of a scroller that is actually visible.
   *
   * The gap over and above the sticky chrome, so a field arrives just clear of
   * it rather than flush against it.
   */
  function scrollPropIntoView(element: HTMLElement, scroller: HTMLElement): void {
    const insets = stickyInsets(scroller);
    Drupal.behaviors.neoAlchemistComponentParent.scrollElementIntoView(element, scroller, {
      top: insets.top + 16,
      bottom: insets.bottom + 16,
    });
  }

  /**
   * Whether a frame has finished loading its own document.
   *
   * readyState alone is a trap: a frame that has not navigated yet — because it
   * still holds data-src, or because the browser has not got to its src — sits
   * on about:blank, which reports 'complete' straight away. Checking the
   * location too distinguishes "done" from "not started".
   */
  function iframeHasLoaded(iframe: HTMLIFrameElement): boolean {
    if (iframe.dataset.src) {
      return false;
    }
    try {
      return iframe.contentDocument?.readyState === 'complete'
        && !!iframe.contentWindow
        && iframe.contentWindow.location.href !== 'about:blank';
    }
    catch (e) {
      return false;
    }
  }

  function waitForAllIframesToLoad(iframes: NodeListOf<HTMLIFrameElement>): Promise<void> {
    if (iframes) {
      const promises = Array.from(iframes).map((iframe) => {
        return new Promise<void>((resolve) => {
          if (iframeHasLoaded(iframe)) {
            resolve();
          } else {
            iframe.addEventListener('load', () => resolve(), { once: true });
          }
        });
      });

      return Promise.all(promises).then(() => {});
    }
    return Promise.resolve();
  }

  /**
   * Starts the deferred previews one at a time, once the previous has loaded.
   *
   * The previews are separate documents that each pull the same ~25 core script
   * URLs (jquery, once, drupal.js…). Pointing all three at their src up front
   * means three concurrent requests for every one of those URLs. Firefox
   * services same-URL parallel requests off a cache entry that is still being
   * written, and intermittently hands one of them an empty body — a 200 with no
   * network error, so the script "loads", defines nothing, and every later
   * script dies on `X is not defined`. Chrome does not do this, which is why it
   * only ever reproduced in Firefox.
   *
   * Chaining the loads means a URL is never requested twice at once; the second
   * and third frames then read a warm cache, so this costs little.
   *
   * Frames opt in by carrying data-src instead of src — see
   * neo-alchemist-manage.html.twig.
   */
  function staggerIframeLoads(iframes: NodeListOf<HTMLIFrameElement>): void {
    const deferred = Array.from(iframes).filter((iframe) => iframe.dataset.src && !iframe.getAttribute('src'));
    if (!deferred.length) {
      return;
    }

    const start = (index: number): void => {
      const iframe = deferred[index];
      if (!iframe) {
        return;
      }
      let advanced = false;
      const advance = () => {
        if (advanced) {
          return;
        }
        advanced = true;
        start(index + 1);
      };
      iframe.addEventListener('load', advance, { once: true });
      // A frame that never fires load must not strand the ones behind it.
      setTimeout(advance, 10000);
      iframe.src = iframe.dataset.src as string;
      delete iframe.dataset.src;
    };

    // Wait for whichever frame was left eager (desktop) before starting. It is
    // usually still on about:blank at this point, so ask iframeHasLoaded()
    // rather than readyState — otherwise the chain starts immediately and the
    // frames overlap after all, which is the whole thing being avoided.
    const eager = Array.from(iframes).find((iframe) => iframe.getAttribute('src'));
    if (!eager || iframeHasLoaded(eager)) {
      start(0);
    }
    else {
      let started = false;
      const begin = () => {
        if (started) {
          return;
        }
        started = true;
        start(0);
      };
      eager.addEventListener('load', begin, { once: true });
      // Do not let a stalled first frame block the others indefinitely.
      setTimeout(begin, 10000);
    }
  }

  function init(container:HTMLElement): void {
    const id = container.id;

    window.addEventListener('message', function (e) {
      if (e.origin !== window.location.origin) {
        return;
      }
      const data = e.data;
      if (typeof data.id !== 'string') {
        return;
      }
      if (data.id !== container.id) {
        return;
      }
      if (typeof data.type === 'string') {
        if (typeof operations[data.type] !== 'function') {
          return;
        }
        operations[data.type](data);
      }
    });

    let initialized: boolean = false;
    const iframes = container.querySelectorAll('iframe');
    const wrapper = container.querySelector('.neo-alchemist-manage--wrapper') as HTMLElement;
    const messages = document.querySelector('.alchemist-messages');
    const formWrapper = container.querySelector('.neo-alchemist-manage--form-wrapper') as HTMLElement;
    const scroll = container.querySelector('.neo-alchemist-manage--form-scroll') as HTMLElement;
    const form = container.querySelector('.neo-alchemist-manage--form') as HTMLIFrameElement;

    /**
     * Records where the canvas sits so a refresh can restore it.
     */
    function savePosition(): void {
      if (!wrapper) {
        return;
      }
      localStorage.setItem(id + '-scroll-l', wrapper.scrollLeft.toString());
      localStorage.setItem(id + '-scroll-t', wrapper.scrollTop.toString());
    }

    /**
     * Puts the canvas back where it was left, if anywhere.
     *
     * Safe to call before the previews have loaded: the canvas gets its width
     * from the inline widths on the frame wrappers, so the horizontal offset —
     * the one that is actually visible — is valid immediately. Vertical can
     * clamp while the frames are still unsized, which is why this runs a second
     * time once they have all reported in.
     *
     * @return TRUE if a stored position was applied.
     */
    function restorePosition(): boolean {
      if (!wrapper) {
        return false;
      }
      const rawLeft = localStorage.getItem(id + '-scroll-l');
      const rawTop = localStorage.getItem(id + '-scroll-t');
      if (rawLeft === null && rawTop === null) {
        return false;
      }
      const left = parseInt(rawLeft || '0', 10);
      const top = parseInt(rawTop || '0', 10);
      wrapper.scrollLeft = Number.isNaN(left) ? 0 : left;
      wrapper.scrollTop = Number.isNaN(top) ? 0 : top;
      return true;
    }

    /**
     * Records the canvas position once a smooth scroll has come to rest.
     *
     * Dragging can save on mouseup because the canvas is already where it ends
     * up. The size and scale buttons scroll with behavior:'smooth', which
     * animates — reading the offsets straight after the call would store where
     * the canvas set off from, which is what made those buttons restore the
     * previous manual position instead of their own.
     */
    let settleTimer: number | undefined;
    let settling = false;
    const onSettleScroll = (): void => {
      window.clearTimeout(settleTimer);
      settleTimer = window.setTimeout(() => {
        if (wrapper) {
          wrapper.removeEventListener('scroll', onSettleScroll);
        }
        settling = false;
        savePosition();
      }, 120);
    };
    function savePositionWhenSettled(): void {
      if (!wrapper) {
        return;
      }
      if (!settling) {
        wrapper.addEventListener('scroll', onSettleScroll);
        settling = true;
      }
      // Arm the timer up front: if the target is already in view no scroll
      // event ever fires, and the position still needs recording.
      onSettleScroll();
    }

    /**
     * Whether a canvas position was left behind to go back to.
     */
    function hasStoredPosition(): boolean {
      return localStorage.getItem(id + '-scroll-l') !== null
        || localStorage.getItem(id + '-scroll-t') !== null;
    }

    const drag = container.querySelector('.neo-alchemist-manage--drag') as HTMLElement;
    waitForAllIframesToLoad(iframes).then(() => {
      if (drag) {
        dragInit(drag);
      }
    });

    const captureButton = document.getElementById('neo-alchemist-thumbnail-capture-button');
    let captureRequestId = '';
    let captureToolbar: HTMLElement | null = null;
    let captureButtonLabel = captureButton ? getButtonLabel(captureButton) : '';
    if (captureButton) {
      captureButton.addEventListener('click', (e) => {
        e.preventDefault();
        if (captureRequestId) {
          return;
        }
        const iframe = getIframe('desktop');
        if (!iframe || !iframe.contentWindow) {
          return;
        }
        captureRequestId = window.crypto && 'randomUUID' in window.crypto
          ? window.crypto.randomUUID()
          : Date.now() + '-' + Math.random();
        captureButton.setAttribute('disabled', 'disabled');
        setButtonLabel(captureButton, Drupal.t('Composing thumbnail…'));
        enterPreviewView();
        centerIframe('desktop', 'smooth');
        postToDesktop({ type: 'thumbnailCaptureStart' });
      });
    }

    // Maximize the preview while composing a thumbnail so more of the component
    // is visible, remembering the current view to restore afterwards.
    let priorViewButton: HTMLElement | null = null;
    function enterPreviewView(): void {
      const activeView = container.querySelector('.neo-alchemist--sizing.is-active');
      priorViewButton = activeView instanceof HTMLElement ? activeView : null;
      const previewView = container.querySelector('.neo-alchemist-manage--size-contract');
      if (previewView instanceof HTMLElement && previewView !== priorViewButton) {
        previewView.click();
      }
    }

    function restorePriorView(): void {
      if (priorViewButton) {
        priorViewButton.click();
        priorViewButton = null;
      }
    }

    function postToDesktop(message: Record<string, unknown>): void {
      const iframe = getIframe('desktop');
      if (iframe && iframe.contentWindow && captureRequestId) {
        iframe.contentWindow.postMessage(
          Object.assign({ requestId: captureRequestId }, message),
          window.location.origin,
        );
      }
    }

    // The framing toolbar lives in the parent window so it stays fixed in the
    // viewport regardless of how tall the preview iframe grows. It drives the
    // in-iframe crop frame via postMessage.
    function buildCaptureToolbar(cfg: { width: number, minWidth: number, maxWidth: number, valign?: string }): void {
      removeCaptureToolbar();
      const bar = document.createElement('div');
      bar.className = 'neo-alchemist-capture-toolbar';

      const hint = document.createElement('span');
      hint.className = 'neo-alchemist-capture-toolbar__hint';
      hint.textContent = Drupal.t('Sized to fit');
      bar.appendChild(hint);

      // Vertical alignment of the content when a short component is stretched.
      const activeValign = cfg.valign || 'center';
      const alignGroup = document.createElement('div');
      alignGroup.className = 'neo-alchemist-capture-toolbar__group';
      const alignLabel = document.createElement('span');
      alignLabel.className = 'neo-alchemist-capture-toolbar__label';
      alignLabel.textContent = Drupal.t('Align');
      alignGroup.appendChild(alignLabel);
      const alignButtons = document.createElement('div');
      alignButtons.className = 'btn-group';
      ([['Top', 'top'], ['Center', 'center'], ['Bottom', 'bottom']] as [string, string][]).forEach(([label, value]) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-xs' + (value === activeValign ? ' is-active' : '');
        b.textContent = label;
        b.addEventListener('click', () => {
          alignButtons.querySelectorAll('button').forEach(el => el.classList.remove('is-active'));
          b.classList.add('is-active');
          postToDesktop({ type: 'thumbnailCaptureValign', value });
        });
        alignButtons.appendChild(b);
      });
      alignGroup.appendChild(alignButtons);
      bar.appendChild(alignGroup);

      const widthGroup = document.createElement('div');
      widthGroup.className = 'neo-alchemist-capture-toolbar__group';
      const widthLabel = document.createElement('span');
      widthLabel.className = 'neo-alchemist-capture-toolbar__label';
      widthLabel.textContent = Drupal.t('Width');
      const slider = document.createElement('input');
      slider.type = 'range';
      slider.min = String(cfg.minWidth);
      slider.max = String(cfg.maxWidth);
      slider.step = '10';
      slider.value = String(cfg.width);
      const readout = document.createElement('span');
      readout.className = 'neo-alchemist-capture-toolbar__readout';
      readout.textContent = cfg.width + 'px';
      slider.addEventListener('input', () => {
        readout.textContent = slider.value + 'px';
        postToDesktop({ type: 'thumbnailCaptureWidth', width: parseInt(slider.value, 10) });
      });
      widthGroup.appendChild(widthLabel);
      widthGroup.appendChild(slider);
      widthGroup.appendChild(readout);
      bar.appendChild(widthGroup);

      const actions = document.createElement('div');
      actions.className = 'neo-alchemist-capture-toolbar__group neo-alchemist-capture-toolbar__actions';
      const capture = document.createElement('button');
      capture.type = 'button';
      capture.className = 'btn btn-xs btn-alert';
      capture.textContent = Drupal.t('Capture');
      capture.addEventListener('click', () => postToDesktop({ type: 'thumbnailCaptureCommit' }));
      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'btn btn-xs';
      cancel.textContent = Drupal.t('Cancel');
      cancel.addEventListener('click', () => postToDesktop({ type: 'thumbnailCaptureAbort' }));
      actions.appendChild(capture);
      actions.appendChild(cancel);
      bar.appendChild(actions);

      document.body.appendChild(bar);
      captureToolbar = bar;
    }

    function removeCaptureToolbar(): void {
      if (captureToolbar) {
        captureToolbar.remove();
        captureToolbar = null;
      }
    }

    function getButtonLabel(button: HTMLElement): string {
      return button instanceof HTMLInputElement ? button.value : (button.textContent || '');
    }

    function setButtonLabel(button: HTMLElement, label: string): void {
      if (button instanceof HTMLInputElement) {
        button.value = label;
      }
      else {
        button.textContent = label;
      }
    }

    function resetCaptureButton(): void {
      captureRequestId = '';
      removeCaptureToolbar();
      restorePriorView();
      if (captureButton) {
        captureButton.removeAttribute('disabled');
        setButtonLabel(captureButton, captureButtonLabel);
      }
    }

    function showCaptureMessage(text: string, type: 'status' | 'error'): void {
      const messagesWrapper = document.querySelector('.alchemist-messages');
      if (!messagesWrapper) {
        return;
      }
      const content = document.createElement('div');
      content.classList.add('neo-alchemist--messages-content');
      const message = document.createElement('div');
      message.classList.add('messages', 'messages--' + type);
      message.textContent = text;
      content.appendChild(message);
      messagesWrapper.appendChild(content);
      setTimeout(() => {
        fadeOutAndRemove(content);
      }, type === 'error' ? 8000 : 4000);
    }

    /**
     * Wait for a selector to appear under root, surviving AJAX DOM swaps.
     *
     * Keys on stable name attributes rather than Drupal's ajax-wrapper ids,
     * which get an incremented suffix on every rebuild.
     */
    function waitForElement(root: HTMLElement, selector: string, timeout: number): Promise<HTMLElement> {
      return new Promise((resolve, reject) => {
        const existing = root.querySelector(selector) as HTMLElement | null;
        if (existing) {
          resolve(existing);
          return;
        }
        const timer = window.setTimeout(() => {
          observer.disconnect();
          reject(new Error('Timed out waiting for ' + selector));
        }, timeout);
        const observer = new MutationObserver(() => {
          const el = root.querySelector(selector) as HTMLElement | null;
          if (el) {
            window.clearTimeout(timer);
            observer.disconnect();
            resolve(el);
          }
        });
        observer.observe(root, { childList: true, subtree: true });
      });
    }

    /**
     * Feed a captured image into the thumbnail managed-file element.
     *
     * Behaves exactly as if the user picked the file by hand: core's
     * fileAutoUpload behavior reacts to the change event and runs the
     * element's upload AJAX, so all neo_config_file machinery (rename,
     * dependencies, permanent-on-save) applies unchanged.
     */
    async function attachThumbnail(file: File): Promise<void> {
      const formElement = captureButton ? captureButton.closest('form') as HTMLElement | null : null;
      if (!formElement) {
        resetCaptureButton();
        return;
      }
      try {
        let input = formElement.querySelector('input[type="file"][name="files[thumbnail]"]') as HTMLInputElement | null;
        if (!input) {
          // A thumbnail already exists: trigger the element's Remove so the
          // upload input comes back. Drupal AJAX buttons act on mousedown.
          const removeButton = formElement.querySelector('[name="thumbnail_remove_button"]');
          if (!removeButton) {
            throw new Error('Thumbnail field not found.');
          }
          removeButton.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
          input = await waitForElement(formElement, 'input[type="file"][name="files[thumbnail]"]', 15000) as HTMLInputElement;
        }
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        // The remove button only renders once the element holds a file, so its
        // appearance confirms the upload completed.
        await waitForElement(formElement, '[name="thumbnail_remove_button"]', 30000);
        captureButtonLabel = Drupal.t('Re-capture thumbnail');
        resetCaptureButton();
        showCaptureMessage(Drupal.t('Thumbnail captured — save the component to keep it.'), 'status');
      }
      catch (_error) {
        resetCaptureButton();
        showCaptureMessage(Drupal.t('The captured thumbnail could not be attached — check the form for details.'), 'error');
      }
    }

    /**
     * POST a captured PNG into the component's own directory.
     *
     * Used by the raw-SDC preview workspace, where the image belongs beside
     * the .component.yml — so it travels with the component in git — rather
     * than in a config file. Unlike attachThumbnail() this needs no save step:
     * the server writes the file and core picks it up on the next request.
     *
     * The button only carries a URL when the server has already decided the
     * write is possible, so an error here is genuinely exceptional.
     */
    async function uploadThumbnail(url: string, blob: Blob): Promise<void> {
      if (captureButton) {
        setButtonLabel(captureButton, Drupal.t('Saving thumbnail…'));
      }
      try {
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'image/png' },
          body: blob,
        });
        const result = await response.json().catch(() => ({} as any));
        if (!response.ok) {
          throw new Error(result.message || response.statusText);
        }
        captureButtonLabel = Drupal.t('Re-capture thumbnail');
        resetCaptureButton();
        showCaptureMessage(Drupal.t('Thumbnail written to @path', {
          '@path': result.path || 'thumbnail.png',
        }), 'status');
        // Every capture writes the same filename, so the preview only updates
        // if it takes the cache-busted URL the server just handed back.
        const preview = document.querySelector('.neo-alchemist--thumbnail-preview') as HTMLImageElement | null;
        if (preview && result.url) {
          preview.src = result.url;
        }
      }
      catch (error) {
        resetCaptureButton();
        showCaptureMessage(Drupal.t('The thumbnail could not be saved: @message', {
          '@message': (error as Error).message || Drupal.t('unknown error'),
        }), 'error');
      }
    }

    if (messages) {
      setTimeout(() => {
        messages.classList.add('opacity-100');
        messages.classList.remove('invisible', 'opacity-0');
        setTimeout(() => {
          const debug = messages.querySelector('.sf-dump') || messages.querySelector('.kint-rich');
          if (!debug) {
            const children = messages.querySelector('.messages--wrapper') as HTMLElement;
            if (children) {
              fadeOutAndRemove(children);
            }
          }
        }, 3000);
      }, 100);
    }

    // Watch for errors.
    iframes.forEach(iframe => {
      if (iframe.dataset.size === 'desktop') {
        const wrap = iframe.closest('.neo-alchemist--iframe-wrapper') as HTMLElement;
        if (wrap && wrap.clientWidth > window.innerWidth && window.innerWidth > 920) {
          wrap.style.width = (window.innerWidth - 20 ) + 'px';
        }
      }
      iframe.onload = () => {
        if (iframe.contentWindow) {
          const html = iframe.contentWindow.document.querySelector('html');
          if (html && !html.classList.contains('js') && wrapper) {
            wrapper.style.visibility = '';
            iframe.style.height = html.offsetHeight + 'px';
          }
        }
      };
    });

    // Only after every onload above is registered, so no deferred frame can
    // load before its handler exists.
    staggerIframeLoads(iframes);

    if (scroll) {
      const resizeObserver = new ResizeObserver(() => {
        scroll.style.width = '';
        if (scroll && scroll.scrollWidth > scroll.offsetWidth) {
          // Get the parent's padding
          const computedStyle = window.getComputedStyle(scroll);
          const paddingRight = parseFloat(computedStyle.paddingRight);
          scroll.style.width = scroll.offsetWidth + paddingRight + (scroll.scrollWidth - scroll.offsetWidth) + 'px';
        }
      });
      resizeObserver.observe(form);

      const expand = formWrapper.querySelector('.neo-alchemist-manage--expand') as HTMLElement;
      const collapse = formWrapper.querySelector('.neo-alchemist-manage--collapse') as HTMLElement;
      if (drag && expand && collapse) {
        expand.addEventListener('click', function (e) {
          e.preventDefault();
          resizeObserver.disconnect();
          drag.style.opacity = '0';
          scroll.style.width = '';
          expand.classList.toggle('hidden');
          collapse.classList.toggle('hidden');
          scroll.classList.toggle('expanded');
        });
        collapse.addEventListener('click', function (e) {
          e.preventDefault();
          drag.style.opacity = '';
          expand.classList.toggle('hidden');
          collapse.classList.toggle('hidden');
          scroll.classList.toggle('expanded');
          resizeObserver.observe(form);
        });
      }
    }

    const active: string = localStorage.getItem('neo-alchemist-size') || 'split';
    [
      {id: 'expand', contentHeight: '0%', formHeight: '100%', hideIframe: true, hideForm: false, active: active === 'expand'},
      {id: 'split', contentHeight: '50%', formHeight: '50%', hideIframe: false, hideForm: false, active: active === 'split'},
      {id: 'contract', contentHeight: '100%', formHeight: '0%', hideIframe: false, hideForm: true, active: active === 'contract'}
    ].forEach(data => {
      once('neo.alchemist', '.neo-alchemist-manage--size-' + data.id, container).forEach(el => {
        if (data.active) {
          el.classList.add('is-active');
          wrapper.style.height = data.contentHeight;
          formWrapper.style.height = data.formHeight;
        }
        wrapper.style.transition = 'all 500ms';
        formWrapper.style.transition = 'all 500ms';
        el.addEventListener('click', (e) => {
          e.preventDefault();
          const sizes = container.querySelectorAll('.neo-alchemist--sizing');
          sizes.forEach((el) => {
            el.classList.remove('is-active');
          });
          localStorage.setItem('neo-alchemist-size', data.id);
          el.classList.add('is-active');
          wrapper.style.height = data.contentHeight;
          wrapper.style.transform = data.hideIframe ? 'scale(0.5)' : '';
          wrapper.style.opacity = data.hideIframe ? '0' : '';
          formWrapper.style.height = data.formHeight;
          formWrapper.style.transform = data.hideForm ? 'scale(0.5)' : '';
          formWrapper.style.opacity = data.hideForm ? '0' : '';
        });
      });
    });

    let sizeCount = 0;
    let activePropId: string | null = null;
    // Set when the highlight is a row rather than a single prop: the row's
    // fields, outlined together as the one card they are.
    let activePropIds: string[] | null = null;
    let activePropLabel = '';
    let propExpiryTimer = 0;
    let hintedWrapper: HTMLElement | null = null;
    let suppressFocusin = false;

    /**
     * How long an untouched highlight survives.
     *
     * A backstop, not a behaviour: the outline should normally go when focus
     * leaves the form or Escape is pressed, and this only catches the cases
     * neither reaches — focus lost to browser chrome, a preview reloaded out
     * from under it. Long enough that it never fires on someone who is simply
     * reading the preview they just lit up.
     */
    const PROP_EXPIRY_MS = 10000;

    /**
     * Restarts the expiry countdown.
     *
     * The timer lives here rather than in the preview because the preview is
     * not the owner: `operations.size` re-posts `activePropId` to any frame
     * that reports a resize, so an outline the preview had expired locally
     * would be resurrected by the next reflow. Clearing the id at the source is
     * what makes the expiry stick.
     */
    function restartPropExpiry(): void {
      window.clearTimeout(propExpiryTimer);
      if (!activePropId && !activePropIds) {
        return;
      }
      propExpiryTimer = window.setTimeout(() => {
        // A cursor sitting in the form is not an abandoned highlight — it is
        // the one thing that explains the outline. Expiring it there would
        // take the box away from someone still working in the field it
        // describes, which is the confusion this whole change is undoing. The
        // guarantee the backstop exists for is unaffected: every way of
        // leaving the field also leaves the form, and that clears outright.
        if (form && form.contains(document.activeElement)) {
          restartPropExpiry();
          return;
        }
        clearPropFocus();
      }, PROP_EXPIRY_MS);
    }
    const operations:any = {
      size: function (data:any) {
        const size = data.size;
        const height = Math.max(data.height, 0);
        const iframe = Array.from(iframes).find(el =>
          el.getAttribute('data-size') === size
        );
        if (iframe instanceof HTMLIFrameElement) {
          sizeCount++;
          iframe.style.height = height + 'px';
          const iframeWrapper = iframe.closest('.neo-alchemist--iframe-wrapper') as HTMLIFrameElement;
          const iframeSize = iframeWrapper?.querySelector('.neo-alchemist--iframe-size') as HTMLIFrameElement;
          if (iframeSize) {
            iframeSize.innerHTML = iframe.clientWidth + '×' + height;
          }
          // Reveal as soon as the first preview has sized itself, not once all
          // of them have. The frames load in sequence now, so waiting for the
          // last would hold the page blank behind two loads the user is not
          // even looking at yet.
          if (wrapper && sizeCount >= 1) {
            wrapper.style.visibility = '';
          }
          // The frame reloads after every debounced form refresh and loses its
          // highlight state; size is the first message a fresh document sends.
          // Deliberately does not restart the expiry: a preview that reflows on
          // its own is not the editor still working, and letting it feed the
          // timer would keep a forgotten outline alive indefinitely.
          if (activePropId || activePropIds) {
            iframe.contentWindow?.postMessage({
              type: 'propFocus',
              propId: activePropId,
              propIds: activePropIds,
              label: activePropLabel,
            }, window.location.origin);
          }
        }
      },

      prop: function (data:any) {
        if (typeof data.propId === 'string' && data.propId) {
          focusProp(data.propId);
          return;
        }
        // Deselected in the preview — a click on empty space, or Escape in
        // there. Drop the highlight in every frame rather than leaving it on
        // the prop that was being edited a moment ago.
        hintProp(null);
        clearPropFocus();
      },

      // Which of the component's props that frame can currently see. Stored
      // against the frame that measured it, never merged: the frames are three
      // different renderings and a slider sits wherever each one left it.
      propItems: function (data:any) {
        if (!Array.isArray(data.items) || typeof data.size !== 'string') {
          return;
        }
        const report: Record<string, { visible: boolean; steerable: boolean }> = {};
        data.items.forEach((item:any) => {
          if (item && typeof item.propId === 'string' && item.propId) {
            report[item.propId] = { visible: !!item.visible, steerable: !!item.steerable };
          }
        });
        propItemsBySize[data.size] = report;
        renderArrayNav();
      },

      propHover: function (data:any) {
        // Hovering the preview is the editor at work, so it keeps the highlight
        // alive; the expiry is only meant to catch an abandoned one.
        restartPropExpiry();
        hintProp(typeof data.propId === 'string' ? data.propId : null);
      },

      messages: function (data:any) {
        const messages = document.querySelector('.alchemist-messages');
        if (messages) {
          const messagesContent = document.createElement('div');
          messagesContent.classList.add('neo-alchemist--messages-content');
          messagesContent.innerHTML = data.messages;
          messages.appendChild(messagesContent);
          setTimeout(() => {
            fadeOutAndRemove(messagesContent);
          }, 3000);
        }
      },

      thumbnailCaptureReady: function (data:any) {
        if (data.requestId !== captureRequestId) {
          return;
        }
        buildCaptureToolbar({
          width: data.width,
          minWidth: data.minWidth,
          maxWidth: data.maxWidth,
          valign: data.valign,
        });
      },

      thumbnailCaptureResult: function (data:any) {
        if (!captureButton || !captureRequestId || data.requestId !== captureRequestId) {
          return;
        }
        removeCaptureToolbar();
        restorePriorView();
        // The raw-SDC preview workspace has no managed-file element and no
        // config entity to hang the image on — it posts the PNG straight into
        // the component's own directory instead.
        const captureUrl = captureButton.dataset.captureUrl;
        if (captureUrl) {
          uploadThumbnail(captureUrl, data.blob);
          return;
        }
        const filename = (captureButton.dataset.filename || 'thumbnail') + '.png';
        attachThumbnail(new File([data.blob], filename, { type: 'image/png' }));
      },

      thumbnailCaptureCancel: function (data:any) {
        if (data.requestId !== captureRequestId) {
          return;
        }
        resetCaptureButton();
      },

      thumbnailCaptureError: function (data:any) {
        if (data.requestId !== captureRequestId) {
          return;
        }
        resetCaptureButton();
        showCaptureMessage(Drupal.t('Thumbnail capture failed: @message', {
          '@message': data.message || Drupal.t('unknown error'),
        }), 'error');
      },
    };

    /**
     * The form wrapper for a prop id, one trailing ~segment coarser on miss.
     *
     * Preview and form both carry the shape id verbatim in data-neo-prop, so
     * an exact hit covers rows, whose delta sits at its own depth in the id
     * (items~heading~1~title reaches that row's field and no other);
     * stripping handles hint-only ids whose child has no wrapper of its own
     * (heading~title → heading).
     */
    function resolvePropWrapper(propId: string): HTMLElement | null {
      if (!form) {
        return null;
      }
      let candidate = propId;
      while (candidate) {
        const wrapper = form.querySelector<HTMLElement>('[data-neo-prop="' + CSS.escape(candidate) + '"]');
        if (wrapper) {
          return wrapper;
        }
        const idx = candidate.lastIndexOf('~');
        candidate = idx === -1 ? '' : candidate.substring(0, idx);
      }
      return null;
    }

    /**
     * Plays the attention flash on an element.
     */
    function flashElement(el: HTMLElement): void {
      el.classList.remove('neo-alchemist--prop-flash');
      // Force a restyle so a repeated click replays the animation.
      void el.offsetWidth;
      el.classList.add('neo-alchemist--prop-flash');
      el.addEventListener('animationend', () => {
        el.classList.remove('neo-alchemist--prop-flash');
      }, { once: true });
    }

    /**
     * Shows one tab pane and marks its tab selected.
     *
     * The panes are server-rendered visible so the form still works without
     * JS; the first call here is what hides the inactive ones.
     */
    function setTab(key: string): void {
      if (!form) {
        return;
      }
      form.querySelectorAll<HTMLElement>('.neo-alchemist--form-pane').forEach(pane => {
        pane.hidden = pane.dataset.neoAlchemistPane !== key;
      });
      form.querySelectorAll<HTMLElement>('[data-neo-alchemist-tab]').forEach(tab => {
        // aria-selected is the whole state: component-form.css draws the active
        // underline off it, so there are no classes to keep in step here.
        tab.setAttribute('aria-selected', tab.dataset.neoAlchemistTab === key ? 'true' : 'false');
      });
      // Report the open tab to the server. The debounced refresh replaces the
      // whole header, so the strip it sends back has to know which tab to mark
      // — otherwise every keystroke would snap the underline back to Content
      // while the pane on screen stayed where it was.
      const field = form.querySelector<HTMLInputElement>('[data-neo-alchemist-active-tab]');
      if (field) {
        field.value = key;
      }
    }

    /**
     * Reveals the tab pane holding an element, if it is in a hidden one.
     *
     * Tab state is plain DOM owned by this file, so unlike the Alpine-bound
     * accordion below this is a direct call rather than a synthetic click.
     */
    function revealTabFor(element: HTMLElement): boolean {
      const pane = element.closest<HTMLElement>('.neo-alchemist--form-pane');
      if (!pane || !pane.hidden) {
        return false;
      }
      setTab(pane.dataset.neoAlchemistPane || 'content');
      return true;
    }

    /**
     * Wires the tab strip and the state chips.
     *
     * Bound once against the container. The debounced refresh replaces the
     * whole header — the chips are a readout of the values being edited, so
     * they have to be rebuilt server-side — while the per-field and per-filter
     * ajax callbacks replace subtrees *inside* a pane. Every listener here is
     * therefore delegated on the form, which survives both.
     */
    function initTabs(): void {
      if (!form) {
        return;
      }
      const strip = form.querySelector<HTMLElement>('.neo-alchemist--form-tabs');
      if (!strip) {
        return;
      }

      // Delegated on the form rather than bound to the strip and the chips
      // themselves: the refresh replaces the whole header, and a listener on
      // an element that gets swapped out goes with it.
      form.addEventListener('click', event => {
        const tab = (event.target as HTMLElement).closest<HTMLElement>('[data-neo-alchemist-tab]');
        if (tab && tab.dataset.neoAlchemistTab) {
          setTab(tab.dataset.neoAlchemistTab);
          return;
        }

        const chip = (event.target as HTMLElement).closest<HTMLElement>('[data-neo-alchemist-chip]');
        if (!chip) {
          return;
        }
        setTab(chip.dataset.neoAlchemistChip || 'content');

        // A chip naming a prop goes through focusProp, which already reveals
        // the pane, opens any enclosing groups, scrolls, focuses the control
        // and flashes it. Only filters need the id path below — they are not
        // prop shapes and so carry no data-neo-prop.
        const prop = chip.dataset.neoAlchemistChipProp;
        if (prop) {
          focusProp(prop);
          return;
        }

        const targetId = chip.dataset.neoAlchemistChipTarget;
        if (!targetId) {
          return;
        }
        const target = form.querySelector<HTMLElement>('#' + CSS.escape(targetId));
        if (!target) {
          return;
        }
        openPropGroups(target);
        const scroller = scroll || formWrapper;
        if (scroller) {
          scrollPropIntoView(target, scroller);
        }
        flashElement(target);
      });

      const selected = strip.querySelector<HTMLElement>('[aria-selected="true"][data-neo-alchemist-tab]');
      setTab(selected?.dataset.neoAlchemistTab || 'content');
    }

    /**
     * Opens every collapsed group enclosing the wrapper, outermost first.
     *
     * @return TRUE if anything had to open (the caller then waits for the
     *   Alpine collapse transition before measuring).
     */
    function openPropGroups(wrapper: HTMLElement): boolean {
      const groups: HTMLElement[] = [];
      let node = wrapper.closest<HTMLElement>('details, .accordion-item');
      while (node) {
        groups.unshift(node);
        node = node.parentElement?.closest<HTMLElement>('details, .accordion-item') || null;
      }
      let opened = false;
      groups.forEach(group => {
        if (group instanceof HTMLDetailsElement) {
          if (!group.open) {
            group.open = true;
            opened = true;
          }
          return;
        }
        // Accordion items toggle through their Alpine-bound summary button.
        // The template writes `:aria-expanded` — an Alpine bind — so the plain
        // attribute this matches on only exists once Alpine has initialised.
        // Before that the group is left alone rather than opened blindly.
        const summary = group.querySelector<HTMLElement>(':scope > button[aria-expanded]');
        if (summary && summary.getAttribute('aria-expanded') !== 'true') {
          summary.click();
          opened = true;
        }
      });
      return opened;
    }

    /**
     * Restores a contracted form pane so a focused field is actually visible.
     *
     * Contract mode sets the form wrapper to `height: 0%` and fades it out, so
     * scrolling and focusing inside it would land somewhere the editor cannot
     * see. Clicking the split button rather than setting the styles directly
     * keeps the active-state classes and the remembered size in step with a
     * manual click.
     *
     * @return TRUE if the pane had to reopen (the caller then waits for the
     *   500ms height transition before measuring).
     */
    function reopenFormPane(): boolean {
      const contracted = container.querySelector<HTMLElement>('.neo-alchemist-manage--size-contract.is-active');
      if (!contracted) {
        return false;
      }
      const split = container.querySelector<HTMLElement>('.neo-alchemist-manage--size-split');
      if (!split) {
        return false;
      }
      split.click();
      return true;
    }

    /**
     * Brings the form field controlling a preview element into focus.
     */
    /**
     * Brings a prop's field forward in the form and highlights it.
     *
     * `propIds` and `label` are the row form: an array row carries no
     * data-neo-prop of its own, so it is named by the set of fields inside it
     * and outlined as the one card it is. `override` is that row's <details>,
     * which is what the arrival sequence below should open, scroll to and
     * flash — its first field is still what ends up focused, but scrolling to
     * the field alone would leave the row's own header above the fold.
     */
    function focusProp(propId: string, propIds: string[] | null = null, label = '', override: HTMLElement | null = null): void {
      const wrapper = override || resolvePropWrapper(propId);
      if (!wrapper) {
        return;
      }
      setActiveProp(propId, propIds, label);
      postPropFocus();
      // The hover hint has done its job the moment the click it was inviting
      // lands. Left up, it outlines the same wrapper the flash is about to
      // cross and the focus ring is about to settle on — three marks on one
      // field in under a second, which is what made the highlight look like it
      // was changing its mind rather than following you.
      hintProp(null);
      // The pane has to be visible before anything measures or scrolls inside
      // it, so this runs ahead of the group opening.
      revealTabFor(wrapper);
      const opened = openPropGroups(wrapper);
      const reopened = reopenFormPane();
      // A freshly opened accordion item is still mid x-collapse transition,
      // and a reopened form pane is mid height transition; measure once
      // whichever ran has settled.
      setTimeout(() => {
        // The side layout scrolls an inner pane, the footer layout scrolls the
        // form wrapper itself. Falling back keeps this working on the SDC
        // preview and component manage pages, which render the footer layout
        // and so have no --form-scroll element at all.
        const scroller = scroll || formWrapper;
        if (scroller) {
          scrollPropIntoView(wrapper, scroller);
        }
        // The per-field state controls (Default / Hide) now render in the
        // legend, which precedes the body — so a plain "first control" query
        // lands on the Default toggle rather than the field the user asked
        // for. Skip anything inside that options group.
        const candidates = wrapper.querySelectorAll<HTMLElement>('input:not([type="hidden"]):not([disabled]), select, textarea, [contenteditable="true"], .ck-editor__editable');
        const input = [...candidates].find(el => !el.closest('.form--inline-min')) || candidates[0];
        if (input) {
          suppressFocusin = true;
          input.focus({ preventScroll: true });
          setTimeout(() => {
            suppressFocusin = false;
          }, 0);
        }
        flashElement(wrapper);
      }, Math.max(opened ? 350 : 0, reopened ? 550 : 0));
    }

    /**
     * Soft pre-highlight while hovering a preview element.
     */
    function hintProp(propId: string | null): void {
      const wrapper = propId ? resolvePropWrapper(propId) : null;
      if (wrapper === hintedWrapper) {
        return;
      }
      if (hintedWrapper) {
        hintedWrapper.classList.remove('neo-alchemist--prop-hint');
      }
      hintedWrapper = wrapper;
      if (hintedWrapper) {
        hintedWrapper.classList.add('neo-alchemist--prop-hint');
      }
    }

    /**
     * Sends the active prop highlight to the previews.
     */
    function postPropFocus(frame?: HTMLIFrameElement): void {
      const targets = frame ? [frame] : Array.from(iframes);
      targets.forEach(target => {
        target.contentWindow?.postMessage({
          type: 'propFocus',
          propId: activePropId,
          propIds: activePropIds,
          label: activePropLabel,
        }, window.location.origin);
      });
    }

    /**
     * Drops the highlight everywhere.
     */
    function clearPropFocus(): void {
      if (!activePropId && !activePropIds) {
        return;
      }
      setActiveProp(null, null, '');
      postPropFocus();
    }

    function setActiveProp(propId: string | null, propIds: string[] | null, label: string): void {
      activePropId = propId;
      activePropIds = propIds;
      activePropLabel = label;
      restartPropExpiry();
    }

    /**
     * The row a focus event landed in, when it landed on the row's own chrome.
     *
     * An array row carries no `data-neo-prop` of its own, so focusing its
     * summary, drag handle or Remove button resolves up to the bare container
     * — and the preview then outlines every row at once. The tell is which way
     * round the two elements nest: `closest()` returns the innermost match, so
     * focus inside a real field resolves to that field's wrapper, which sits
     * *inside* the row. A wrapper that instead *contains* the row is the
     * container, which means the focus never reached a field.
     */
    function rowChromeFor(target: HTMLElement, wrapper: HTMLElement | null): HTMLElement | null {
      const row = target.closest<HTMLElement>('.neo-alchemist-draggable-item');
      return row && wrapper && wrapper.contains(row) ? row : null;
    }

    // Reverse direction: focusing a form field highlights the preview
    // element(s) it controls.
    if (form) {
      form.addEventListener('focusin', (e) => {
        if (suppressFocusin) {
          return;
        }
        const target = e.target as HTMLElement;
        // The stepper sits in the array's own legend, so focusing it resolves
        // to the array itself and would outline every row at once for the
        // moment before the step lands on one of them.
        if (target.closest('.neo-alchemist-array-nav')) {
          return;
        }
        const wrapper = target.closest<HTMLElement>('[data-neo-prop]');
        const propId = wrapper?.dataset.neoProp || null;
        const row = rowChromeFor(target, wrapper);
        // The row's own fields, named so the preview outlines that one card
        // rather than the whole list. Read straight from the DOM because
        // renumberDraggableList() keeps these deltas in visual order, which is
        // the order the preview renumbers to.
        const propIds = row
          ? [...row.querySelectorAll<HTMLElement>('[data-neo-prop]')]
            .map(el => el.dataset.neoProp || '')
            .filter(Boolean)
          : null;
        const label = row
          ? (row.querySelector<HTMLElement>('.details--title')?.textContent || '').trim()
          : '';
        if (propId === activePropId && sameIds(propIds, activePropIds)) {
          restartPropExpiry();
          return;
        }
        setActiveProp(propId, propIds, label);
        postPropFocus();
      });

      // Leaving the editor drops the highlight — it describes where the cursor
      // is, and once the cursor is gone it is describing nothing. Deferred and
      // re-checked against activeElement so a move between two fields, which
      // fires focusout before the next focusin, never blinks it; and so the
      // focus churn of an AJAX subtree swap settles first.
      //
      // The boundary is the editor, not the form, because the preview is the
      // other half of the same tool. Clicking an element in it moves focus onto
      // the <iframe>, which sits outside the form — so a form-shaped boundary
      // read the most ordinary gesture in the editor as leaving it, and cleared
      // the very outline that click had just asked for. Worse, invisibly: the
      // field this path focuses a moment later is focused programmatically,
      // under `suppressFocusin`, so nothing re-asserted the highlight and it
      // stayed gone.
      form.addEventListener('focusout', () => {
        window.setTimeout(() => {
          if (suppressFocusin) {
            return;
          }
          if (!container.contains(document.activeElement)) {
            clearPropFocus();
          }
        }, 150);
      });
    }

    function sameIds(a: string[] | null, b: string[] | null): boolean {
      if (!a || !b) {
        return a === b;
      }
      return a.length === b.length && a.every((id, i) => id === b[i]);
    }

    /**
     * What each preview last reported about which of its props are on screen.
     *
     * Kept per frame because the three previews disagree by design — a slider
     * sits on a different slide at each width, and the two lazy frames are not
     * even painting until they are scrolled to. Storing one report per size and
     * reading the one the editor is looking at is what stops the last frame to
     * speak from deciding what the form says.
     */
    const propItemsBySize: Record<string, Record<string, { visible: boolean; steerable: boolean }>> = {};

    /**
     * The rows belonging to this array and not to an array nested inside it.
     *
     * A row carries no data-neo-prop, so the nearest one above it is its own
     * array's fieldset — and a nested array's rows answer with that array
     * instead. Same rule as draggableRows() in component-ajax-form.ts, reached
     * from the other end.
     */
    function arrayRows(fieldset: HTMLElement): HTMLElement[] {
      return Array.from(fieldset.querySelectorAll<HTMLElement>('.neo-alchemist-draggable-item'))
        .filter(row => row.closest<HTMLElement>('[data-neo-prop]') === fieldset);
    }

    /**
     * Reads the preview's report onto one array: which row is on screen, and
     * whether stepping through them is offered at all.
     *
     * Three row states, and the third is load-bearing. A row whose fields are
     * all empty renders nothing at all, so none of its ids come back — that is
     * `unknown`, not `hidden`, and reading it as hidden would make an array of
     * empty rows look like a fully collapsed slider.
     */
    function readArray(fieldset: HTMLElement, report: Record<string, { visible: boolean; steerable: boolean }> | null): {
      rows: HTMLElement[];
      onScreen: number;
      steerable: boolean;
    } {
      const rows = arrayRows(fieldset);
      let onScreen = -1;
      let anyHidden = false;
      let steerable = false;
      rows.forEach((row, idx) => {
        let reported = false;
        let visible = false;
        row.querySelectorAll<HTMLElement>('[data-neo-prop]').forEach(el => {
          const item = report?.[el.dataset.neoProp || ''];
          if (!item) {
            return;
          }
          reported = true;
          visible = visible || item.visible;
          steerable = steerable || item.steerable;
        });
        if (!reported) {
          return;
        }
        if (visible) {
          if (onScreen === -1) {
            onScreen = idx;
          }
          return;
        }
        anyHidden = true;
      });
      // Both halves are required: something must be off screen for stepping to
      // mean anything, and something must be on screen for the readout to name
      // a row. A component that answers no reveal fails the third test whatever
      // its markup does, so a control that nothing would respond to is never
      // drawn.
      return { rows, onScreen, steerable: steerable && anyHidden && onScreen !== -1 };
    }

    /**
     * Repaints every array stepper and row marker from the current report.
     */
    function renderArrayNav(): void {
      if (!form) {
        return;
      }
      const size = getMostVisibleIframe()?.getAttribute('data-size') || '';
      const report = propItemsBySize[size] || null;
      form.querySelectorAll<HTMLElement>('.neo-alchemist-array-nav').forEach(nav => {
        const fieldset = nav.closest<HTMLElement>('[data-neo-prop]');
        if (!fieldset) {
          return;
        }
        const state = readArray(fieldset, report);
        nav.classList.toggle('is-active', state.steerable);
        const count = nav.querySelector<HTMLElement>('.neo-alchemist-array-nav--count');
        if (count) {
          count.textContent = state.steerable
            ? (state.onScreen + 1) + ' / ' + state.rows.length
            : '';
        }
        state.rows.forEach((row, idx) => {
          if (state.steerable && idx === state.onScreen) {
            row.setAttribute('aria-current', 'true');
            return;
          }
          row.removeAttribute('aria-current');
        });
      });
    }

    /**
     * Moves the preview one item along, by moving the form there.
     *
     * Everything past picking the row is the path a click on that row's header
     * already takes: focusProp opens it, scrolls it clear of the sticky
     * headers, focuses its first field and posts the highlight — and the
     * preview answers the highlight by revealing the item, because that is the
     * contract it already implements. So the readout is never set from here;
     * it is repainted by the report the preview sends once it has settled,
     * which is why it cannot end up describing a slide the component did not
     * actually move to.
     */
    function stepArray(button: HTMLElement): void {
      const fieldset = button.closest<HTMLElement>('[data-neo-prop]');
      if (!fieldset) {
        return;
      }
      const rows = arrayRows(fieldset);
      if (!rows.length) {
        return;
      }
      const current = rows.findIndex(row => row.hasAttribute('aria-current'));
      const step = button.dataset.neoAlchemistStep === 'prev' ? -1 : 1;
      // Wraps rather than stopping: both slider engines in this site's theme
      // wrap, and a stepper that dead-ends where the component does not would
      // be describing a limit that is not there.
      const next = rows[(Math.max(current, 0) + step + rows.length) % rows.length];
      const ids = Array.from(next.querySelectorAll<HTMLElement>('[data-neo-prop]'))
        .map(el => el.dataset.neoProp || '')
        .filter(Boolean);
      if (!ids.length) {
        return;
      }
      const label = (next.querySelector<HTMLElement>('.details--title')?.textContent || '').trim();
      focusProp(ids[0], ids, label, next);
    }

    if (form) {
      onFormRebuild.push(renderArrayNav);

      form.addEventListener('click', (e: MouseEvent) => {
        const button = (e.target as HTMLElement).closest<HTMLElement>('[data-neo-alchemist-step]');
        if (!button) {
          return;
        }
        e.preventDefault();
        stepArray(button);
      });

      // The canvas scrolls between the three frames, and which one is in view
      // decides whose report the form is showing.
      if (wrapper) {
        let navScrollTimer: number | undefined;
        wrapper.addEventListener('scroll', () => {
          window.clearTimeout(navScrollTimer);
          navScrollTimer = window.setTimeout(() => renderArrayNav(), 120);
        });
      }
    }

    // Escape clears from the form side, and the preview routes its own Escape
    // up through the `prop` channel to land here too. Skipped while a dialog is
    // open so one press does not both close the dialog and drop the outline.
    document.addEventListener('keydown', (e: KeyboardEvent) => {
      if (e.key !== 'Escape' || document.querySelector('dialog[open], .ui-dialog')) {
        return;
      }
      clearPropFocus();
    });

    // Pad the edges of the drag area
    const padding = Math.floor(document.body.clientWidth * 0.9);
    drag.style.paddingLeft = padding + 'px';
    drag.style.paddingRight = padding + 'px';

    const scale:string = localStorage.getItem('neo-alchemist-scale') || '1';
    const scaleButtons = container.querySelectorAll('.neo-alchemist--scale');
    [
      {size: 'full', scale: '1'},
      {size: '75', scale: '0.75'},
      {size: '50', scale: '0.5'},
    ].forEach(data => {
      once('neo.alchemist', '.neo-alchemist--scale[data-size="' + data.size + '"]').forEach(el => {
        if (scale === data.scale) {
          el.classList.add('is-active');
        }
        el.addEventListener('click', (e) => {
          e.preventDefault();
          scaleButtons.forEach((el) => {
            el.classList.remove('is-active');
          });
          el.classList.add('is-active');
          setScale(data.scale);
          // Only on click. setScale() also runs during init, where saving would
          // overwrite the stored position with the default one.
          savePositionWhenSettled();
        });
      });
    });

    const scaleWrapper = container.querySelector('.neo-alchemist-manage--scale') as HTMLIFrameElement;
    setScale(scale);

    // Restore here, and not a line earlier: setScale() puts a transform on the
    // canvas, which is what gives it its full scrollable width. Before that the
    // canvas is barely wider than the viewport, so a large stored offset clamps
    // to the old maximum and stays there. Restoring now — still ahead of the
    // reveal, which waits on the first preview reporting its size — means the
    // canvas is already in place when it appears, instead of being centred on
    // desktop and then jumping once every preview has loaded.
    restorePosition();
    initTabs();
    scaleWrapper.addEventListener('transitionend', (event: TransitionEvent) => {
      // Check if the transition was specifically for transform
      if (event.propertyName === 'transform') {
        const customEvent = new CustomEvent('alchemistManageScaleEnd');
        container.dispatchEvent(customEvent);
      }
    });
    function setScale(scale: string): void {
      if (scaleWrapper) {
        if (!initialized) {
          scaleWrapper.style.transformOrigin = 'top left';
        }
        scaleWrapper.style.transform = `scale(${scale})`;
        if (wrapper) {
          if (!initialized) {
            // On initial load, center the desktop frame in the viewport — but
            // only as a default. A stored position is restored immediately
            // after this and centring would just be undone.
            if (!hasStoredPosition()) {
              centerIframe('desktop', 'auto');
            }
          }
          else {
            // Reset position each time scale is changed
            const rect = scaleWrapper.getBoundingClientRect();
            wrapper.scrollTo({
              top: 0,
              left: rect.left + wrapper.scrollLeft,
              behavior: 'smooth',
            });
          }
        }
        if (!initialized) {
          scaleWrapper.style.transition = 'transform 0.2s ease-in-out';
        }
      }

      localStorage.setItem('neo-alchemist-scale', scale);
      const customEvent = new CustomEvent('alchemistManageScale', {
        bubbles: true,
        cancelable: true,
        detail: {
          scale: scale,
        }
      });
      container.dispatchEvent(customEvent);
    }

    function dragInit(el:HTMLElement): void {
      let startX:number;
      let startY:number;
      let scrollLeft:number
      let scrollTop:number;

      // Applied once already, before the canvas was on screen. Repeat it now
      // that every preview has reported its height: a stored vertical offset
      // clamps to nothing while the canvas is still empty. The horizontal
      // offset is unchanged by this, so nothing moves on screen.
      restorePosition();

      const focusButtons = container.querySelectorAll('.neo-alchemist--focus');
      const mostVisibleSize = getMostVisibleIframe()?.getAttribute('data-size') || null;
      [
        {size: 'desktop', active: mostVisibleSize === 'desktop'},
        {size: 'tablet', active: mostVisibleSize === 'tablet'},
        {size: 'mobile', active: mostVisibleSize === 'mobile'},
      ].forEach(data => {
        once('neo.alchemist', '.neo-alchemist--focus[data-size="' + data.size + '"]').forEach(el => {
          if (data.active) {
            el.classList.add('is-active');
          }
          el.addEventListener('click', (e) => {
            e.preventDefault();
            const iframe = getIframe(data.size as string);
            if (iframe) {
              focusButtons.forEach((el) => {
                el.classList.remove('is-active');
              });
              el.classList.add('is-active');
              iframe.closest('.neo-alchemist--iframe-wrapper')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
                inline: 'center',
              });
              savePositionWhenSettled();
            }
          });
        });
      });

      /**
       * Whether a pan may start here.
       *
       * data-alchemist-nodrag is the only authority. data-alchemist-ignore
       * deliberately is not: it also sits on the transparent hit targets that
       * blanket every component, and treating it as a pan blocker made most of
       * the canvas surface undraggable — the components are the canvas.
       */
      function allowDrag(el: HTMLElement): boolean {
        return !el.closest('[data-alchemist-nodrag]');
      }

      // Bound on the wrapper, not the drag element: the hit targets are
      // siblings of the drag element, so a mousedown over a component never
      // reached a listener bound further in.
      (wrapper || el).addEventListener('mousedown', handleDragStart);

      // Space held, or the middle button, pans from anywhere — the convention
      // in design tools, and unambiguous where a plain drag is not.
      let spacePan = false;
      document.addEventListener('keydown', (e) => {
        if (e.key !== ' ' || e.repeat || isTyping(e.target)) {
          return;
        }
        spacePan = true;
        el.style.cursor = 'grab';
        // Otherwise space scrolls the page out from under the canvas.
        e.preventDefault();
      });
      document.addEventListener('keyup', (e) => {
        if (e.key === ' ') {
          spacePan = false;
        }
      });

      function isTyping(target: EventTarget | null): boolean {
        if (!(target instanceof HTMLElement)) {
          return false;
        }
        return target.isContentEditable
          || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
      }

      let moved: boolean;
      function handleDragStart(e: MouseEvent): void {
        const forced = e.button === 1 || spacePan;
        if (!forced && e.button !== 0) {
          return;
        }
        // Even a deliberate pan gesture leaves the real UI alone — the panels
        // scroll themselves, and their buttons still need their clicks.
        if (!allowDrag(e.target as HTMLElement)) {
          return;
        }
        if (forced) {
          // Middle-click otherwise starts the browser's autoscroll.
          e.preventDefault();
        }
        if (wrapper) {
          moved = false;
          wrapper.style.userSelect = 'none';
          el.style.cursor = 'grabbing';
          startX = e.clientX;
          startY = e.clientY;
          scrollLeft = wrapper.scrollLeft;
          scrollTop = wrapper.scrollTop;
          document.addEventListener('mouseup', handleDragEnd);
          document.addEventListener('mousemove', handleMouseMove);
          iframes.forEach(iframe => {
            if (iframe instanceof HTMLIFrameElement) {
              iframe.style.pointerEvents = 'none';
            }
          });
        }
      }

      function handleMouseMove(e: MouseEvent): void {
        if (wrapper) {
          const dx = e.clientX - startX;
          const dy = e.clientY - startY;
          moved = true;
          wrapper.style.userSelect = '';
          wrapper.scrollLeft = scrollLeft - dx;
          wrapper.scrollTop = scrollTop - dy;
        }
      }

      function handleDragEnd(): void {
        if (wrapper) {
          savePosition();
          el.style.cursor = 'grab';
          document.removeEventListener('mouseup', handleDragEnd);
          document.removeEventListener('mousemove', handleMouseMove);
          iframes.forEach(iframe => {
            if (iframe instanceof HTMLIFrameElement) {
              iframe.style.pointerEvents = '';
            }
          });
          if (moved) {
            focusButtons.forEach((el) => {
              el.classList.remove('is-active');
            });
            const mostVisibleSize = getMostVisibleIframe()?.getAttribute('data-size') || null;
            if (mostVisibleSize) {
              const focusButton = container.querySelector('.neo-alchemist--focus[data-size="' + mostVisibleSize + '"]');
              if (focusButton) {
                focusButton.classList.add('is-active');
              }
            }
          }
        }
      }
    }

    /**
     * Get iframe by size
     */
    function getIframe(size: string): HTMLIFrameElement | undefined {
      return Array.from(iframes).find(el => el.getAttribute('data-size') === size) as HTMLIFrameElement | undefined;
    }

    /**
     * Horizontally center a frame within the scrollable wrapper.
     */
    function centerIframe(size: string, behavior: ScrollBehavior = 'auto'): void {
      const iframe = getIframe(size);
      if (!iframe || !wrapper) {
        return;
      }
      const wrap = (iframe.closest('.neo-alchemist--iframe-wrapper') as HTMLElement) || iframe;
      const wrapRect = wrap.getBoundingClientRect();
      const wrapperRect = wrapper.getBoundingClientRect();
      // Content-space left edge of the frame, then offset to center it.
      const contentLeft = wrapRect.left - wrapperRect.left + wrapper.scrollLeft;
      const targetLeft = contentLeft + (wrapRect.width / 2) - (wrapper.clientWidth / 2);
      wrapper.scrollTo({
        top: 0,
        left: Math.max(0, targetLeft),
        behavior,
      });
    }

    /**
     * Get the most visible iframe within the container
     */
    function getMostVisibleIframe(): HTMLElement | null {
      const children = Array.from(iframes) as HTMLElement[];
      const containerRect = container.getBoundingClientRect();

      let mostVisibleDiv: HTMLElement | null = null;
      let maxVisibleArea = 0;
      let foundFullyVisible = false;

      children.forEach((child) => {
        const childRect = child.getBoundingClientRect();

        // Calculate the visible portion
        const visibleLeft = Math.max(childRect.left, containerRect.left);
        const visibleRight = Math.min(childRect.right, containerRect.right);

        // Calculate visible width (0 if not visible at all)
        const visibleWidth = Math.max(0, visibleRight - visibleLeft);

        // Check if element is fully visible
        const isFullyVisible =
          childRect.left >= containerRect.left &&
          childRect.right <= containerRect.right;

        // If we found a fully visible element and haven't found one before, prioritize it
        if (isFullyVisible && !foundFullyVisible) {
          mostVisibleDiv = child;
          maxVisibleArea = visibleWidth;
          foundFullyVisible = true;
        }
        // If we already found a fully visible element, ignore partially visible ones
        else if (!foundFullyVisible && visibleWidth > maxVisibleArea) {
          maxVisibleArea = visibleWidth;
          mostVisibleDiv = child;
        }
      });

      return mostVisibleDiv;
    }

    initialized = true;
  };

  /**
   * Fades out an element and moves it up 1rem while removing it from the DOM
   * @param element The element to fade out and remove (or its ID as string)
   * @param duration The duration of the fade-out animation in milliseconds
   * @param callback Optional callback function to run after element is removed
   */
  function fadeOutAndRemove(
    element: HTMLElement | string,
    duration: number = 500,
    callback?: () => void
  ): void {
    // Get the element if a string ID was provided
    const targetElement = typeof element === 'string'
      ? document.getElementById(element)
      : element;

    // Return if element doesn't exist
    if (!targetElement) {
      console.error(`Element ${typeof element === 'string' ? element : 'provided'} not found`);
      return;
    }

    // Store the element's original opacity
    const originalOpacity = window.getComputedStyle(targetElement).opacity;

    // Ensure the element is visible
    targetElement.style.opacity = originalOpacity;

    // Store the original position information
    const originalPosition = window.getComputedStyle(targetElement).position;

    // Set relative positioning if the element isn't already positioned
    if (originalPosition === 'static') {
      targetElement.style.position = 'relative';
    }

    // Add CSS transition for both opacity and transform
    targetElement.style.transition = `opacity ${duration}ms ease, transform ${duration}ms ease`;

    // Start the fade out and move up
    targetElement.style.opacity = '0';
    targetElement.style.transform = 'translateY(-1rem)';

    // Remove the element after the transition completes
    setTimeout(() => {
      targetElement.parentNode?.removeChild(targetElement);

      // Call the callback function if provided
      if (callback) {
        callback();
      }
    }, duration);
  }

})(Drupal, once);
