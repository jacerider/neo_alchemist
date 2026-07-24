(function (Drupal, once) {

  Drupal.behaviors.neoAlchemistComponentParent = {
    scale: 1,

    attach: function () {
      once('neo.alchemist.component.parent', '.neo-alchemist-manage').forEach(container => {
        init(container);
      });
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

      // Determine if vertical scrolling is needed
      if (elementRect.height > containerRect.height) {
        // alert('help');
      }
      else if (elementTop < containerVisibleTop) {
        // Element is above the visible area, scroll up
        scrollTop += elementTop - offsets.top;
        needsScroll = true;
      }
      else if (elementBottom > containerVisibleBottom) {
        // Element is above the visible area, scroll up
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
      hint.textContent = Drupal.t('Sized to fit the component');
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
        }
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

      function allowDrag(el: HTMLElement): boolean {
        // Check if target has data-alchemist-ignore
        if (el.dataset.alchemistIgnore !== undefined || el.closest('[data-alchemist-nodrag]')) {
          return false;
        }
        return true;
      }

      el.addEventListener('mousedown', handleDragStart);
      let moved: boolean;
      function handleDragStart(e: MouseEvent): void {
        if (!allowDrag(e.target as HTMLElement)) {
          return;
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
