(function (Drupal, once, drupalSettings) {

  // TypeScript interfaces
  interface ClickOptions {
    capture?: boolean;
    passive?: boolean;
  }

  interface PropMapInfo {
    root: string;
    title: string;
    ref: string;
    type: string;
    hints?: {
      text?: string[];
      src?: string[];
      href?: string[];
    };
  }

  interface PropMap {
    component: string;
    props: Record<string, PropMapInfo>;
  }

  const id = new URLSearchParams(window.location.search).get('id');
  const size = new URLSearchParams(window.location.search).get('size');

  // Elements mapped back to a prop field: stamped server-side on
  // attribute-carrying values (data-neo-prop) or claimed here from the prop
  // map's hints (data-neo-prop-target). The heuristic claim is the more
  // content-specific of the two — a heading's text beats its size attribute —
  // so it wins in propIdOf().
  const targetSelector = '[data-neo-prop-target],[data-neo-prop]';
  // Re-read from the response on every in-place refresh: the map describes the
  // markup, so a swap that changed the markup invalidates it.
  let propMap: PropMap | undefined = drupalSettings.neoAlchemist?.propMap;
  let hoverTarget: HTMLElement | null = null;
  let activeTargets: HTMLElement[] = [];
  let hoverOverlay: HTMLElement | null = null;
  let hoverOverlayLabel: HTMLElement | null = null;
  let activeOverlays: HTMLElement[] = [];
  let pointerRaf = 0;
  // Held at module scope so an in-place refresh can disconnect the observer
  // watching the subtree it is about to discard.
  let resizeObserver: ResizeObserver | null = null;
  let refreshController: AbortController | null = null;

  Drupal.behaviors.neoAlchemistComponentChild = {
    attach: function () {
      once('neo.alchemist', '.neo-alchemist-preview').forEach(element => {

        // Handle iframe messages and send to parent if desktop size.
        const messages = document.querySelector('.alchemist-messages .messages--wrapper');
        if (messages) {
          const debug = messages.querySelector('.sf-dump') || messages.querySelector('.kint-rich');
          if (debug) {
            const wrapper = document.querySelector('.alchemist-messages');
            if (wrapper) {
              wrapper.classList.add('opacity-100');
              wrapper.classList.remove('invisible', 'opacity-0');
            }
          }
          else {
            if (size === 'desktop') {
              if (!debug) {
                window.parent.postMessage({
                  type: 'messages',
                  id: id,
                  size: size,
                  messages: messages.innerHTML,
                }, '*');
              }
            }
            messages.remove();
          }
        }

        // Create a ResizeObserver instance
        resizeObserver?.disconnect();
        resizeObserver = new ResizeObserver(entries => {
          for (const _entry of entries) {
            window.parent.postMessage({
              type: 'size',
              id: id,
              size: size,
              height: element.scrollHeight,
            }, '*');
            refreshOverlays();
          }
        });

        // Start observing the body element
        resizeObserver.observe(element);

        // Disable global left click on the body element
        disableGlobalLeftClick(element);

        // Map preview DOM back to the form's prop fields.
        initPropTargets(element as HTMLElement);
      });
    }
  };

  const disableGlobalLeftClick = (element:HTMLElement): void => {
    const handleClick = (event: MouseEvent): boolean => {
      // Check if it's a left click (button property is 0 for left clicks)
      if (event.button === 0) {
        event.preventDefault();
        return false;
      }
      return true;
    };

    element.addEventListener('click', handleClick, { capture: true } as ClickOptions);
  };

  const post = (type: string, data: Record<string, unknown>): void => {
    window.parent.postMessage({
      type: type,
      id: id,
      size: size,
      ...data,
    }, window.location.origin);
  };

  /**
   * Both ids an element may carry, heuristic claim first.
   */
  const propIdOf = (el: HTMLElement): string => {
    return el.dataset.neoPropTarget || el.dataset.neoProp || '';
  };

  /**
   * Index clickable prop targets and wire the pointer delegation.
   */
  const initPropTargets = (element: HTMLElement): void => {
    const scope = element.querySelector<HTMLElement>('[data-neo-component]') || element;

    // Authoritative targets stamped server-side.
    element.querySelectorAll<HTMLElement>('[data-neo-prop]').forEach(el => {
      el.classList.add('neo-alchemist--prop-target');
    });

    // Heuristic targets from the prop map. Absent on preview flavors that do
    // not attach one — stamped targets still work there.
    if (propMap && propMap.props) {
      indexTextHints(scope);
      indexSrcHints(scope);
      indexHrefHints(scope);
    }

    // Hit-test through the point rather than the event target: decorative
    // layers (gradients, stretched pseudo-links) often sit on top of the
    // element the editor means, and closest() from the covering layer walks
    // the wrong branch. The overlays are pointer-events: none, so they never
    // appear in the stack. The global left-click suppression is capture-phase
    // preventDefault only, so these bubble-phase listeners still see every
    // event.
    let pointerX = 0;
    let pointerY = 0;
    element.addEventListener('mousemove', (event: MouseEvent) => {
      pointerX = event.clientX;
      pointerY = event.clientY;
      if (pointerRaf) {
        return;
      }
      pointerRaf = requestAnimationFrame(() => {
        pointerRaf = 0;
        setHover(resolveFromPoint(pointerX, pointerY));
      });
    });
    element.addEventListener('mouseleave', () => setHover(null));
    element.addEventListener('click', (event: MouseEvent) => {
      if (event.button !== 0) {
        return;
      }
      const target = resolveFromPoint(event.clientX, event.clientY)
        || (event.target as HTMLElement).closest<HTMLElement>(targetSelector);
      // A click on nothing in particular is a deselect: it already blurs the
      // form field, so the outline has to go with it rather than being left
      // pointing at a prop no longer being edited.
      post('prop', { propId: target ? propIdOf(target) : null });
    });
  };

  /**
   * The prop target under a point: a direct target on top wins, then the
   * closest ancestor target of anything in the stack.
   */
  const resolveFromPoint = (x: number, y: number): HTMLElement | null => {
    const stack = document.elementsFromPoint(x, y) as HTMLElement[];
    for (const el of stack) {
      if (el.matches && el.matches(targetSelector)) {
        return el;
      }
    }
    for (const el of stack) {
      const target = el.closest && el.closest<HTMLElement>(targetSelector);
      if (target) {
        return target;
      }
    }
    return null;
  };

  /**
   * The part of an element actually visible through its clipping ancestors.
   *
   * Outlines painted on the element itself are at the mercy of author CSS —
   * a hover scale inside an overflow-hidden crop pushes every edge out of
   * view. The overlays instead draw over this rect, so a half-cropped slider
   * card gets a border around its visible half.
   */
  const visibleRect = (el: HTMLElement): DOMRect | null => {
    const rect = el.getBoundingClientRect();
    let left = rect.left;
    let top = rect.top;
    let right = rect.right;
    let bottom = rect.bottom;
    let parent = el.parentElement;
    while (parent && parent !== document.body) {
      const style = window.getComputedStyle(parent);
      if (style.overflowX !== 'visible' || style.overflowY !== 'visible') {
        const clip = parent.getBoundingClientRect();
        left = Math.max(left, clip.left);
        top = Math.max(top, clip.top);
        right = Math.min(right, clip.right);
        bottom = Math.min(bottom, clip.bottom);
      }
      parent = parent.parentElement;
    }
    if (right - left <= 0 || bottom - top <= 0) {
      return null;
    }
    return new DOMRect(left, top, right - left, bottom - top);
  };

  const buildOverlay = (kind: string): HTMLElement => {
    const overlay = document.createElement('div');
    overlay.className = 'neo-alchemist--prop-overlay ' + kind;
    document.body.appendChild(overlay);
    return overlay;
  };

  const positionOverlay = (overlay: HTMLElement, target: HTMLElement): void => {
    const rect = visibleRect(target);
    if (!rect) {
      overlay.style.display = 'none';
      return;
    }
    overlay.style.display = '';
    overlay.style.left = (rect.left + window.scrollX) + 'px';
    overlay.style.top = (rect.top + window.scrollY) + 'px';
    overlay.style.width = rect.width + 'px';
    overlay.style.height = rect.height + 'px';
  };

  const setHover = (target: HTMLElement | null): void => {
    if (target === hoverTarget) {
      // Keep tracking transforms mid-transition (hover scale effects).
      if (hoverTarget && hoverOverlay) {
        positionOverlay(hoverOverlay, hoverTarget);
      }
      return;
    }
    hoverTarget = target;
    if (!hoverTarget) {
      if (hoverOverlay) {
        hoverOverlay.style.display = 'none';
      }
      post('propHover', { propId: null });
      return;
    }
    if (!hoverOverlay) {
      hoverOverlay = buildOverlay('is-hover');
      hoverOverlayLabel = document.createElement('span');
      hoverOverlayLabel.className = 'neo-alchemist--prop-overlay-label';
      hoverOverlay.appendChild(hoverOverlayLabel);
    }
    const propId = propIdOf(hoverTarget);
    if (hoverOverlayLabel) {
      const title = propMap?.props[propId]?.title || '';
      hoverOverlayLabel.textContent = title;
      hoverOverlayLabel.style.display = title ? '' : 'none';
    }
    positionOverlay(hoverOverlay, hoverTarget);
    post('propHover', { propId: propId });
  };

  const renderActiveOverlays = (): void => {
    activeOverlays.forEach(overlay => overlay.remove());
    activeOverlays = activeTargets.map(target => {
      const overlay = buildOverlay('is-active');
      positionOverlay(overlay, target);
      return overlay;
    });
    // A refreshed subtree is measured the moment it lands, which is before
    // anything above it has finished resolving its height — so the outline
    // gets placed where the element briefly was. The resize observer corrects
    // this only if something happens to resize afterwards; re-measuring on the
    // next frame does not depend on that.
    requestAnimationFrame(refreshOverlays);
  };

  const refreshOverlays = (): void => {
    if (hoverTarget && hoverOverlay) {
      positionOverlay(hoverOverlay, hoverTarget);
    }
    activeTargets.forEach((target, index) => {
      if (activeOverlays[index]) {
        positionOverlay(activeOverlays[index], target);
      }
    });
  };

  /**
   * Claim an element for a prop; the first claim wins.
   */
  const claimTarget = (el: HTMLElement, propId: string): void => {
    if (el.dataset.neoPropTarget) {
      return;
    }
    el.dataset.neoPropTarget = propId;
    el.classList.add('neo-alchemist--prop-target');
  };

  /**
   * Exact-trim text-node matching, document-order pairing for duplicates.
   */
  const indexTextHints = (scope: HTMLElement): void => {
    const map = propMap;
    if (!map) {
      return;
    }
    // Hint text → prop ids carrying it, in map order (= delta order).
    const byText: Record<string, string[]> = {};
    Object.keys(map.props).forEach(propId => {
      (map.props[propId].hints?.text || []).forEach(text => {
        (byText[text] = byText[text] || []).push(propId);
      });
    });
    const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
    let node: Node | null;
    while ((node = walker.nextNode())) {
      const text = (node.textContent || '').trim();
      if (!text) {
        continue;
      }
      const queue = byText[text];
      const parent = (node as Text).parentElement;
      if (!queue || !queue.length || !parent) {
        continue;
      }
      claimTarget(parent, queue.shift() as string);
    }
  };

  /**
   * Match images by source basename (derivatives keep the filename).
   */
  const indexSrcHints = (scope: HTMLElement): void => {
    const map = propMap;
    if (!map) {
      return;
    }
    const imgs = Array.from(scope.querySelectorAll<HTMLImageElement>('img[src]'));
    Object.keys(map.props).forEach(propId => {
      (map.props[propId].hints?.src || []).forEach(basename => {
        for (const img of imgs) {
          if (img.dataset.neoPropTarget) {
            continue;
          }
          let src = img.src;
          try {
            src = decodeURIComponent(src);
          }
          catch (_e) {
            // Keep the raw src.
          }
          if (src.includes(basename)) {
            claimTarget(img, propId);
            break;
          }
        }
      });
    });
  };

  /**
   * Match links by href path.
   */
  const indexHrefHints = (scope: HTMLElement): void => {
    const map = propMap;
    if (!map) {
      return;
    }
    const anchors = Array.from(scope.querySelectorAll<HTMLAnchorElement>('a[href]'));
    Object.keys(map.props).forEach(propId => {
      (map.props[propId].hints?.href || []).forEach(href => {
        for (const anchor of anchors) {
          if (anchor.dataset.neoPropTarget) {
            continue;
          }
          const match = href.startsWith('/')
            ? (anchor.pathname + anchor.search === href || anchor.pathname === href)
            : (anchor.href === href || anchor.href.startsWith(href));
          if (match) {
            claimTarget(anchor, propId);
            break;
          }
        }
      });
    });
  };

  /**
   * The stylesheet and script URLs a document depends on, as one comparable
   * string.
   *
   * A prop edit can change which libraries the component needs — turning on a
   * slider pulls in JS that is simply not in this document. Swapping markup
   * cannot bring assets with it, so when the set differs the refresh has to
   * give up and let the frame reload instead.
   */
  const assetFingerprint = (root: Document): string => {
    return Array.from(root.querySelectorAll('link[rel="stylesheet"][href], script[src]'))
      .map(el => el.getAttribute('href') || el.getAttribute('src') || '')
      .sort()
      .join('\n');
  };

  /**
   * The prop map carried by a fetched document.
   */
  const readPropMap = (doc: Document): PropMap | undefined => {
    const json = doc.querySelector('script[type="application/json"][data-drupal-selector="drupal-settings-json"]');
    if (!json || !json.textContent) {
      return undefined;
    }
    try {
      return JSON.parse(json.textContent)?.neoAlchemist?.propMap;
    }
    catch (e) {
      return undefined;
    }
  };

  /**
   * Drop everything bound to the subtree about to be replaced.
   *
   * The overlays are the part that is easy to miss: they are appended to
   * document.body rather than to the preview root, so replacing the root
   * leaves them behind pointing at elements that no longer exist.
   */
  const teardownPreview = (element: HTMLElement): void => {
    resizeObserver?.disconnect();
    resizeObserver = null;
    if (pointerRaf) {
      cancelAnimationFrame(pointerRaf);
      pointerRaf = 0;
    }
    hoverOverlay?.remove();
    hoverOverlay = null;
    hoverOverlayLabel = null;
    activeOverlays.forEach(overlay => overlay.remove());
    activeOverlays = [];
    activeTargets = [];
    hoverTarget = null;
    Drupal.detachBehaviors(element, drupalSettings, 'unload');
  };

  /**
   * Reload as a last resort, staggered by frame.
   *
   * Three frames failing together would otherwise re-request the same ~25
   * asset URLs at once, which is the Firefox empty-body case the staggered
   * initial load exists to avoid. Spacing them by size keeps that property on
   * the one path that still reloads.
   */
  const fallbackReload = (): void => {
    const order = ['desktop', 'tablet', 'mobile'].indexOf(size || 'desktop');
    window.setTimeout(() => window.location.reload(), Math.max(0, order) * 400);
  };

  /**
   * Re-render the component in place.
   *
   * This replaced a location.reload() of every preview frame on every
   * debounced edit — three documents discarded and rebuilt, assets and all,
   * which is where the flash came from. Editors type slowly and pause often,
   * so it fired constantly: one sentence measured 12 refreshes and 36 frame
   * reloads.
   *
   * Fetching the same URL and swapping only the component subtree keeps the
   * document, its assets and its scroll position alive. A newer edit aborts an
   * in-flight fetch, so a burst of typing costs one swap rather than a queue
   * of them. Anything this cannot do safely falls back to the reload, so the
   * worst case is the behaviour it replaced.
   */
  const refreshPreview = (provided: string | null): void => {
    const element = document.querySelector<HTMLElement>('.neo-alchemist-preview');
    if (!element) {
      fallbackReload();
      return;
    }
    refreshController?.abort();
    refreshController = new AbortController();
    // The parent renders once and hands the markup to all three frames, since
    // the three responses differ only by the data-size stamped below. Fetching
    // here is the fallback for a caller that sent none.
    const source = provided !== null
      ? Promise.resolve(provided)
      : fetch(window.location.href, {
        credentials: 'same-origin',
        signal: refreshController.signal,
      })
        .then(response => {
          if (!response.ok) {
            throw new Error(String(response.status));
          }
          return response.text();
        });
    source
      .then(html => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const fresh = doc.querySelector<HTMLElement>('.neo-alchemist-preview');
        if (!fresh) {
          throw new Error('Preview root missing from response');
        }
        if (assetFingerprint(doc) !== assetFingerprint(document)) {
          throw new Error('Asset set changed');
        }
        teardownPreview(element);
        const next = document.importNode(fresh, true);
        // Otherwise once() reads the marker the server never wrote but the
        // previous document did, and skips initialising the new subtree.
        next.removeAttribute('data-once');
        // The markup may have been rendered for a different frame — that is
        // the point of rendering once — so restamp the one attribute the
        // server varies by size.
        if (size) {
          next.setAttribute('data-size', size);
        }
        element.replaceWith(next);
        // Before behaviors run: initPropTargets() indexes against this.
        const nextMap = readPropMap(doc);
        if (nextMap) {
          propMap = nextMap;
          drupalSettings.neoAlchemist = drupalSettings.neoAlchemist || {};
          drupalSettings.neoAlchemist.propMap = nextMap;
        }
        Drupal.attachBehaviors(next, drupalSettings);
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return;
        }
        fallbackReload();
      });
  };

  /**
   * Elements matching a prop id exactly or as a `~` prefix.
   */
  const findPropElements = (propId: string): HTMLElement[] => {
    const matches: HTMLElement[] = [];
    document.querySelectorAll<HTMLElement>(targetSelector).forEach(el => {
      const ids = [el.dataset.neoPropTarget, el.dataset.neoProp];
      if (ids.some(target => target && (target === propId || target.startsWith(propId + '~')))) {
        matches.push(el);
      }
    });
    return matches;
  };

  // A refreshed subtree replays its scroll-entrance animation, which moves
  // elements by transform — and a transform resizes nothing, so the resize
  // observer never fires and an outline measured mid-flight stays where the
  // element briefly was. Both events bubble, so one listener covers the
  // subtree however it is replaced.
  document.addEventListener('animationend', refreshOverlays);
  document.addEventListener('transitionend', refreshOverlays);

  // Highlight requests from the parent editor (form focus, and re-asserted
  // after each preview reload). No scrolling here: the iframe is auto-sized
  // to its full content height, so it has nothing to scroll itself — the
  // browser would propagate the scroll to the embedding editor page and pan
  // the canvas instead.
  window.addEventListener('message', (e: MessageEvent) => {
    if (e.origin !== window.location.origin) {
      return;
    }
    const data = e.data;
    if (data && data.type === 'previewRefresh') {
      refreshPreview(typeof data.html === 'string' ? data.html : null);
      return;
    }
    if (!data || data.type !== 'propFocus') {
      return;
    }
    let propId: string = data.propId || '';
    let matches: HTMLElement[] = [];
    while (propId && !matches.length) {
      matches = findPropElements(propId);
      if (!matches.length) {
        const idx = propId.lastIndexOf('~');
        propId = idx === -1 ? '' : propId.substring(0, idx);
      }
    }
    activeTargets = matches;
    renderActiveOverlays();
  });

})(Drupal, once, drupalSettings);
