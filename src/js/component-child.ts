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
    // A presentation prop, which owns no element of its own anywhere in the
    // markup — the server stamp skips it deliberately. Its scope is the whole
    // component, so that is what gets outlined when one is focused.
    style?: boolean;
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
  // The subtree the form edits; targets outside it are ignored. Reassigned by
  // every initPropTargets() run, so an in-place refresh re-scopes with it.
  let propScope: HTMLElement | null = null;
  // Props that have claimed an element, so none claims a second. Rebuilt by
  // every initPropTargets() run alongside the scope it indexed.
  let claimedProps = new Set<string>();
  let activeTargets: HTMLElement[] = [];
  // How the active targets are to be drawn. `exact` outlines each one, because
  // a prop stamped on several elements really is in several places. The other
  // two are one logical thing spread over several boxes — a card, a container,
  // a whole component — so they get a single outline round the union, which is
  // what stops a four-card list from drawing twelve.
  let activeMode: 'exact' | 'group' | 'component' | 'none' = 'none';
  let activeLabel = '';
  let hoverOverlay: HTMLElement | null = null;
  let hoverOverlayLabel: HTMLElement | null = null;
  let activeOverlays: HTMLElement[] = [];
  let pointerRaf = 0;
  // Held at module scope so an in-place refresh can disconnect the observer
  // watching the subtree it is about to discard.
  let resizeObserver: ResizeObserver | null = null;
  let refreshController: AbortController | null = null;
  // Which elements on screen came from the server, as opposed to ones a
  // component's own JS built after it rendered — list_s1 appends a canvas for
  // its dots, and plenty of widgets do something similar. A morph works from
  // the server's markup, so every one of those looks to it like a node to
  // delete; they are protected by asking this rather than by guessing from
  // what an element looks like. Node identity survives a morph, which is what
  // lets a set hold the answer across one.
  let serverNodes = new WeakSet<Element>();
  // The shape of the last markup the server sent. Compared against the next
  // one rather than against the DOM, because the DOM has the client's
  // additions in it and so never matches a render exactly.
  let lastServerSignature = '';
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
   * The subtree belonging to the component the form is editing.
   *
   * The SDC preview can render neighbor components above and below the one
   * being previewed, so the first [data-neo-component] in the document is not
   * necessarily the right one — and a neighbor's markup must claim nothing,
   * or its text steals a hint from the component the form actually edits.
   * The prop map names the uuid; without one, fall back to the old behavior.
   */
  const propComponentScope = (element: HTMLElement): HTMLElement => {
    const uuid = propMap?.component;
    const owned = uuid
      ? element.querySelector<HTMLElement>('[data-neo-component="' + CSS.escape(uuid) + '"]')
      : null;
    return owned || element.querySelector<HTMLElement>('[data-neo-component]') || element;
  };

  /**
   * Index clickable prop targets against the markup as it stands now.
   *
   * Split out of initPropTargets() because a morph re-runs this and must not
   * re-run the listener wiring below it: the morph leaves the preview root in
   * place, so binding again would stack a second set of handlers on it and
   * post every click twice.
   *
   * Claims are derived from the markup, so they are rebuilt rather than
   * amended — an edit moves text between elements, and a claim left pointing
   * at where the text used to be opens the wrong field.
   */
  const indexPropTargets = (element: HTMLElement): void => {
    const scope = propComponentScope(element);
    propScope = scope;
    claimedProps = new Set<string>();

    // Clear the previous pass. A morph preserves client-owned attributes (see
    // refreshPreview), so last pass's claims survive into this markup unless
    // they are dropped here; the class goes with them because a server-owned
    // class attribute is re-asserted by the morph and would otherwise keep a
    // target styled after it stopped being one.
    scope.querySelectorAll<HTMLElement>('[data-neo-prop-target]').forEach(el => {
      delete el.dataset.neoPropTarget;
    });
    scope.querySelectorAll<HTMLElement>('.neo-alchemist--prop-target').forEach(el => {
      el.classList.remove('neo-alchemist--prop-target');
    });

    // Authoritative targets stamped server-side.
    scope.querySelectorAll<HTMLElement>('[data-neo-prop]').forEach(el => {
      el.classList.add('neo-alchemist--prop-target');
    });

    // Heuristic targets from the prop map. Absent on preview flavors that do
    // not attach one — stamped targets still work there.
    if (propMap && propMap.props) {
      indexTextHints(scope);
      indexSrcHints(scope);
      indexHrefHints(scope);
    }
  };

  /**
   * Index clickable prop targets and wire the pointer delegation.
   */
  const initPropTargets = (element: HTMLElement): void => {
    indexPropTargets(element);

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
      if (el.matches && el.matches(targetSelector) && inPropScope(el)) {
        return el;
      }
    }
    for (const el of stack) {
      const target = el.closest && el.closest<HTMLElement>(targetSelector);
      if (target && inPropScope(target)) {
        return target;
      }
    }
    return null;
  };

  /**
   * Whether an element belongs to the component the form edits.
   */
  const inPropScope = (el: HTMLElement): boolean => {
    return !propScope || propScope === el || propScope.contains(el);
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

  let overlayLayer: HTMLElement | null = null;

  /**
   * The layer the outlines are drawn on, created on first use.
   *
   * Appending them straight to the body let them take part in the document's
   * scroll size — and the parent sizes this frame to exactly its content
   * height, so the document sits permanently one fraction of a pixel away from
   * needing a scrollbar. Where scrollbars take width (Firefox; not Chrome,
   * which is why it never reproduced there) that tipped into a loop: scrollbar
   * appears, the frame narrows, text rewraps, the height changes, the parent
   * resizes, the scrollbar goes, and round again — visibly, tens of times a
   * second, and only in the narrowest frame where a rewrap moves the most.
   *
   * Fixed and clipped, the layer cannot influence layout or scroll size at
   * all. Its children are positioned in viewport coordinates to match; the
   * frame never scrolls itself, which is the same assumption the highlight
   * handler already documents.
   */
  const getOverlayLayer = (): HTMLElement => {
    if (!overlayLayer || !overlayLayer.isConnected) {
      overlayLayer = document.createElement('div');
      overlayLayer.className = 'neo-alchemist--prop-overlays';
      document.body.appendChild(overlayLayer);
    }
    return overlayLayer;
  };

  const buildOverlay = (kind: string): HTMLElement => {
    const overlay = document.createElement('div');
    overlay.className = 'neo-alchemist--prop-overlay ' + kind;
    getOverlayLayer().appendChild(overlay);
    return overlay;
  };

  /**
   * Height the label chip needs above the box, including its gap and stroke.
   */
  const LABEL_CLEARANCE = 26;

  /**
   * Flips the label below when the box is too near the top of the frame.
   *
   * The overlay layer clips, so a chip placed above an element within a chip's
   * height of the top edge is simply not drawn — and that is exactly where a
   * component's own heading tends to sit, so the props most worth naming were
   * the ones losing their name.
   */
  const placeOverlayLabel = (overlay: HTMLElement, top: number): void => {
    overlay.classList.toggle('is-label-below', top < LABEL_CLEARANCE);
  };

  const positionOverlay = (overlay: HTMLElement, target: HTMLElement): void => {
    const rect = visibleRect(target);
    if (!rect) {
      overlay.style.display = 'none';
      return;
    }
    overlay.style.display = '';
    // Viewport coordinates: the layer is fixed, so no scroll offset applies.
    overlay.style.left = rect.left + 'px';
    overlay.style.top = rect.top + 'px';
    overlay.style.width = rect.width + 'px';
    overlay.style.height = rect.height + 'px';
    placeOverlayLabel(overlay, rect.top);
  };

  const setHover = (target: HTMLElement | null): void => {
    // Selection wins. An element carrying the focus outline does not also get a
    // hover one: the two are the same shape in the same place, so the second
    // reads as a duplicate rather than as more information — two strokes and
    // two identical name chips stacked on one element. The stronger state
    // already says everything the weaker one would.
    if (target && activeTargets.includes(target)) {
      target = null;
    }
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

  /**
   * The smallest box containing every target, in viewport coordinates.
   *
   * Targets clipped out of view contribute nothing — visibleRect() returns null
   * for them — so a union never stretches to a card scrolled off the page.
   * Null when nothing is visible, which positions as a hidden overlay exactly
   * as a single clipped target already does.
   */
  const unionRect = (targets: HTMLElement[]): DOMRect | null => {
    let left = Infinity, top = Infinity, right = -Infinity, bottom = -Infinity;
    targets.forEach(target => {
      const rect = visibleRect(target);
      if (!rect) {
        return;
      }
      left = Math.min(left, rect.left);
      top = Math.min(top, rect.top);
      right = Math.max(right, rect.right);
      bottom = Math.max(bottom, rect.bottom);
    });
    if (left === Infinity) {
      return null;
    }
    return new DOMRect(left, top, right - left, bottom - top);
  };

  const positionOverlayRect = (overlay: HTMLElement, rect: DOMRect | null): void => {
    if (!rect || rect.width <= 0 || rect.height <= 0) {
      overlay.style.display = 'none';
      return;
    }
    overlay.style.display = '';
    overlay.style.left = rect.left + 'px';
    overlay.style.top = rect.top + 'px';
    overlay.style.width = rect.width + 'px';
    overlay.style.height = rect.height + 'px';
    placeOverlayLabel(overlay, rect.top);
  };

  /**
   * Places the active overlays against their targets.
   *
   * Shared by the initial render and every reposition so the two can never
   * disagree about how many boxes there are — the grouped modes draw one
   * overlay whose geometry has to be recomputed from all of the targets, not
   * from the one that happens to sit at the same index.
   */
  const layoutActiveOverlays = (): void => {
    if (activeMode === 'exact') {
      activeTargets.forEach((target, index) => {
        if (activeOverlays[index]) {
          positionOverlay(activeOverlays[index], target);
        }
      });
      return;
    }
    if (activeOverlays[0]) {
      positionOverlayRect(activeOverlays[0], unionRect(activeTargets));
    }
  };

  const renderActiveOverlays = (): void => {
    activeOverlays.forEach(overlay => overlay.remove());
    const count = activeTargets.length && activeMode !== 'exact' ? 1 : activeTargets.length;
    activeOverlays = Array.from({ length: count }, () => {
      return buildOverlay(activeMode === 'component' ? 'is-active is-component' : 'is-active');
    });
    if (activeOverlays.length) {
      const label = document.createElement('span');
      label.className = 'neo-alchemist--prop-overlay-label';
      label.textContent = activeLabel;
      label.style.display = activeLabel ? '' : 'none';
      activeOverlays[0].appendChild(label);
    }
    // The pointer may already be resting on what just became selected — from a
    // click in the preview, which is the usual way in. Re-asserting the rule
    // here is what stops that path leaving the hover box stranded underneath.
    if (hoverTarget && activeTargets.includes(hoverTarget)) {
      setHover(null);
    }
    layoutActiveOverlays();
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
    layoutActiveOverlays();
  };

  /**
   * Claim an element for a prop; the first claim wins, one element per prop.
   *
   * One element per prop is the half that is easy to get wrong. These are
   * positional heuristics — five cards whose links are all `/` are told apart
   * only by pairing the map's order against the document's — so a prop that
   * takes a second element is not highlighting itself twice, it is consuming
   * the element the next prop was going to match, and every claim after it
   * shifts by one. That is how a button whose own title text had already
   * claimed it went on to claim the first card's anchor through its href, so
   * clicking the button outlined the card too and each card's link pointed at
   * the row before it.
   *
   * @return TRUE if the claim was taken.
   */
  const claimTarget = (el: HTMLElement, propId: string): boolean => {
    if (el.dataset.neoPropTarget || claimedProps.has(propId)) {
      return false;
    }
    el.dataset.neoPropTarget = propId;
    el.classList.add('neo-alchemist--prop-target');
    claimedProps.add(propId);
    return true;
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
      // Drop props another hint already placed rather than letting them hold
      // the queue: the pairing is positional, so a prop that cannot claim
      // must step aside for the next one rather than consume this text node.
      while (queue.length && claimedProps.has(queue[0])) {
        queue.shift();
      }
      if (queue.length) {
        claimTarget(parent, queue.shift() as string);
      }
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
        if (claimedProps.has(propId)) {
          return;
        }
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
        if (claimedProps.has(propId)) {
          return;
        }
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
   * The element tree's shape, ignoring every value hanging off it.
   *
   * Two renders with the same signature differ only in text and attributes, so
   * every element in the old tree has a counterpart in the new one and a morph
   * can patch values in place without adding or removing a node. That is the
   * common edit by far: typing, and picking a scheme, spacing or gap.
   *
   * A differing signature means the render gained or lost elements — a card
   * added, a conditional block appearing — and the morph would have to splice
   * the tree. That is where a widget's own state (a carousel's slide count, a
   * lightbox's index) goes stale against markup it no longer matches, so those
   * renders take the full replace below and let everything re-initialise.
   */
  const structureSignature = (root: HTMLElement): string => {
    const parts: string[] = [];
    const walk = (el: Element, depth: number): void => {
      parts.push(depth + el.tagName);
      for (let child = el.firstElementChild; child; child = child.nextElementSibling) {
        walk(child, depth + 1);
      }
    };
    walk(root, 0);
    return parts.join(',');
  };

  /**
   * Record every element of a tree the server produced.
   *
   * Called on the fresh markup before it is morphed in, so the live nodes it
   * merges onto are the ones that end up recorded — and anything already on
   * screen that it never touched is, by elimination, the client's.
   */
  const recordServerTree = (root: Element): void => {
    serverNodes.add(root);
    root.querySelectorAll('*').forEach(el => serverNodes.add(el));
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
    activeMode = 'none';
    activeLabel = '';
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
   *
   * Swapping the subtree was still a full teardown of it, though, which is why
   * an edit went on reading as a reload: every image became a new element and
   * re-decoded, and every widget re-initialised, so a carousel three slides in
   * snapped back to the first because the subtitle changed. When the re-render
   * has the same shape as what is on screen — every edit that changes values
   * rather than structure — the markup is morphed onto the live nodes instead,
   * and nothing is destroyed to begin with. See structureSignature().
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
        const next = document.importNode(fresh, true);
        // The markup may have been rendered for a different frame — that is
        // the point of rendering once — so restamp the one attribute the
        // server varies by size.
        if (size) {
          next.setAttribute('data-size', size);
        }

        // The prop map describes the markup, so it has to land before anything
        // indexes against it.
        const nextMap = readPropMap(doc);
        if (nextMap) {
          propMap = nextMap;
          drupalSettings.neoAlchemist = drupalSettings.neoAlchemist || {};
          drupalSettings.neoAlchemist.propMap = nextMap;
        }

        const signature = structureSignature(next);
        const sameShape = signature === lastServerSignature;
        lastServerSignature = signature;
        recordServerTree(next);

        if (sameShape) {
          // Idiomorph pairs two elements only if their ids agree, and a
          // component's ids are made out of its content: the section is
          // identified by an anchor slugged from the heading, so editing the
          // title renames it. The pairing then fails on the one element that
          // holds everything, and the morph deletes and rebuilds the whole
          // component — the very thing this branch exists to avoid.
          //
          // Taking the ids off first drops the morph back to matching by
          // position, which is exactly right here: this branch only runs when
          // the two trees have the same shape, so position identifies an
          // element unambiguously. The server's markup carries the ids it
          // wants, and the morph writes them back on the way through.
          const shedIds = new Map<Element, string>();
          const shed = (el: Element): void => {
            if (el.id) {
              shedIds.set(el, el.id);
              el.removeAttribute('id');
            }
          };
          shed(element);
          element.querySelectorAll('[id]').forEach(shed);

          // Same tree, new values: patch them onto the nodes already standing.
          // Replacing the subtree instead is what made an edit read as a
          // reload — every image re-decoded, and a carousel three slides in
          // snapped back to the first, however unrelated the edited prop was.
          Idiomorph.morph(element, next, {
            morphStyle: 'outerHTML',
            callbacks: {
              // An attribute the new markup does not carry at all was put
              // there by client JS, not by the server: `data-once`, a widget's
              // own init marker (list_s1 writes data-list-s1-init), the inline
              // transform a carousel tracks its position with. Removing those
              // tells that JS to start over, which is the flash this is here
              // to stop. An attribute the server does render is its own, so an
              // update — including one that adds it — still applies, and a
              // style or scheme prop lands as the class swap it should be.
              beforeAttributeUpdated: (_name, _node, mutationType) => {
                return mutationType !== 'remove';
              },
              // The same argument one level up: a node the server never sent
              // belongs to whatever script built it, and deleting it both
              // breaks that widget and leaves it no way to notice — its init
              // marker was just preserved, so it will not rebuild.
              beforeNodeRemoved: (node) => {
                return !(node instanceof Element) || serverNodes.has(node);
              },
            },
          });
          // A server element has whatever id the render just gave it, or none
          // because the render gave it none. What is left unnamed here is the
          // client's own, which the morph never visited and so never renamed.
          shedIds.forEach((value, el) => {
            if (!el.id && el.isConnected && !serverNodes.has(el)) {
              el.setAttribute('id', value);
            }
          });

          // The morph re-asserted every server-owned class and left the
          // client-owned attributes alone, so both halves of the target index
          // are now stale in opposite directions. No behaviors run: by
          // definition this branch added no element for them to attach to, and
          // re-running them against markers that were deliberately preserved
          // would either no-op or double-bind.
          indexPropTargets(element);
          refreshOverlays();
        }
        else {
          // Structure changed. Fall back to the replace this path has always
          // done, which re-initialises everything against the new tree.
          teardownPreview(element);
          // Otherwise once() reads the marker the server never wrote but the
          // previous document did, and skips initialising the new subtree.
          next.removeAttribute('data-once');
          element.replaceWith(next);
          Drupal.attachBehaviors(next, drupalSettings);
        }
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return;
        }
        fallbackReload();
      });
  };

  /**
   * Show a typed value before the render that confirms it arrives.
   *
   * Only the text node the prop actually owns is rewritten, never the claimed
   * element's textContent: a claim lands on the closest element wrapping the
   * text, and that element is often shared — the heading's `h2` holds the
   * title's own text and the subtitle's span beside it, so assigning to it
   * would delete a sibling prop.
   *
   * The map's hint is what that text last rendered as, which is how the right
   * node is picked out, and it is moved on with the text so a second keystroke
   * still finds it. The next real render replaces the map wholesale, so any
   * drift lasts until then at most.
   */
  const echoProp = (propId: string, text: string): void => {
    const info = propMap?.props?.[propId];
    // Presentation props own no element, and a non-string is not a thing that
    // can be dropped into a text node.
    if (!info || info.style || info.type !== 'string') {
      return;
    }
    const hints = info.hints?.text;
    const current = hints?.[0];
    // An empty value is not echoable: a prop with nothing in it usually makes
    // the server drop the element rather than render it blank, which is a
    // structural change and nothing this can imitate.
    if (!hints || !current || !text) {
      return;
    }
    const scope = propScope || document.body;
    const el = Array.from(scope.querySelectorAll<HTMLElement>('[data-neo-prop-target]'))
      .find(candidate => candidate.dataset.neoPropTarget === propId);
    if (!el) {
      return;
    }
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();
    while (node) {
      const value = node.textContent || '';
      if (value.trim() === current) {
        // Replacing inside the node rather than assigning to it keeps the
        // whitespace the template indented the markup with.
        node.textContent = value.replace(current, text);
        hints[0] = text;
        refreshOverlays();
        return;
      }
      node = walker.nextNode();
    }
  };

  /**
   * Elements matching a prop id exactly or as a `~` prefix.
   */
  const findPropElements = (propId: string): HTMLElement[] => {
    const matches: HTMLElement[] = [];
    const root: ParentNode = propScope || document;
    root.querySelectorAll<HTMLElement>(targetSelector).forEach(el => {
      const ids = [el.dataset.neoPropTarget, el.dataset.neoProp];
      if (ids.some(target => target && (target === propId || target.startsWith(propId + '~')))) {
        matches.push(el);
      }
    });
    return matches;
  };

  /**
   * Elements whose id is exactly this prop's.
   */
  const findExactPropElements = (propId: string): HTMLElement[] => {
    const matches: HTMLElement[] = [];
    const root: ParentNode = propScope || document;
    root.querySelectorAll<HTMLElement>(targetSelector).forEach(el => {
      if (el.dataset.neoPropTarget === propId || el.dataset.neoProp === propId) {
        matches.push(el);
      }
    });
    return matches;
  };

  /**
   * Drops any target that wraps another, keeping the innermost.
   *
   * One prop can land on two nested elements — a stamped wrapper and the
   * element the prop map's hints claimed inside it. Outlining both draws a box
   * inside a box round one value, which reads as two props rather than one.
   * The inner element is the content the prop actually produced, which is the
   * same reason propIdOf() prefers the claim over the stamp; targets that do
   * not contain each other are left alone, because a prop genuinely rendered in
   * two places really is in two places.
   */
  const dropEnclosing = (targets: HTMLElement[]): HTMLElement[] => {
    if (targets.length < 2) {
      return targets;
    }
    return targets.filter(candidate => !targets.some(other => other !== candidate && candidate.contains(other)));
  };

  /**
   * What to outline for a focus request, and how.
   *
   * This used to walk the id coarser a segment at a time until something
   * matched. Because findPropElements() already matches descendants by prefix,
   * that loop could only ever fire for a prop with no element AND no rendered
   * descendants — at which point it jumped to an ancestor and outlined that
   * ancestor's OTHER children. Focusing an empty Supertitle drew a box round
   * the Title, which is the one answer that is worse than none: it names the
   * wrong field while looking authoritative.
   *
   * So nothing climbs any more. A prop is outlined by its own elements, or as
   * the union of its descendants, or — for a style prop, which owns no element
   * anywhere because the stamp deliberately skips it — by the component it
   * restyles, since that genuinely is its scope. Failing all three it is not
   * outlined at all, and silence is the honest answer.
   */
  const resolveFocus = (propId: string, propIds: string[] | null): {
    targets: HTMLElement[];
    mode: 'exact' | 'group' | 'component' | 'none';
    label: string;
  } => {
    // An explicit set is a caller that has already decided these belong
    // together — a card's fields, say — so it is drawn as the one thing it is.
    if (propIds && propIds.length) {
      const targets: HTMLElement[] = [];
      propIds.forEach(id => {
        findPropElements(id).forEach(el => {
          if (!targets.includes(el)) {
            targets.push(el);
          }
        });
      });
      return targets.length
        ? { targets, mode: 'group', label: '' }
        : { targets: [], mode: 'none', label: '' };
    }
    if (!propId) {
      return { targets: [], mode: 'none', label: '' };
    }
    const label = propMap?.props[propId]?.title || '';
    const exact = dropEnclosing(findExactPropElements(propId));
    if (exact.length) {
      return { targets: exact, mode: 'exact', label };
    }
    const descendants = findPropElements(propId);
    if (descendants.length) {
      return { targets: descendants, mode: 'group', label };
    }
    if (propMap?.props[propId]?.style && propScope) {
      return { targets: [propScope], mode: 'component', label };
    }
    return { targets: [], mode: 'none', label: '' };
  };

  // Anything that moves an element by transform — an author's own transition,
  // a component's internal animation — resizes nothing, so the resize observer
  // never fires and an outline measured mid-flight would stay where the
  // element briefly was. Both events bubble, so one listener covers the
  // subtree however it is replaced.
  document.addEventListener('animationend', refreshOverlays);
  document.addEventListener('transitionend', refreshOverlays);

  // Escape inside the preview dismisses the outline. Routed through the same
  // upward `prop` channel a click on empty space already uses rather than a
  // message of its own: the parent owns the highlight, and a preview that
  // cleared its own overlays would have them replayed by the next resize.
  // Only when nothing is focused here, so it cannot swallow an Escape a
  // control inside the preview wanted.
  document.addEventListener('keydown', (e: KeyboardEvent) => {
    if (e.key !== 'Escape' || !activeTargets.length) {
      return;
    }
    const focused = document.activeElement;
    if (focused && focused !== document.body && focused.closest('[role="dialog"], dialog')) {
      return;
    }
    post('prop', { propId: null });
  });

  // Take the baseline before any behavior has run. This chunk is a module, so
  // it executes after the document is parsed but before DOMContentLoaded
  // brings up Drupal's behaviors — the one moment the preview is exactly what
  // the server sent, with nothing a component's JS added yet mixed into it.
  const initialPreview = document.querySelector<HTMLElement>('.neo-alchemist-preview');
  if (initialPreview) {
    recordServerTree(initialPreview);
    lastServerSignature = structureSignature(initialPreview);
  }

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
    if (data && data.type === 'propEcho') {
      if (typeof data.propId === 'string' && typeof data.text === 'string') {
        echoProp(data.propId, data.text);
      }
      return;
    }
    if (!data || data.type !== 'propFocus') {
      return;
    }
    const resolved = resolveFocus(
      typeof data.propId === 'string' ? data.propId : '',
      Array.isArray(data.propIds) ? data.propIds.filter((id: unknown) => typeof id === 'string') : null,
    );
    activeTargets = resolved.targets;
    activeMode = resolved.mode;
    activeLabel = (typeof data.label === 'string' && data.label)
      ? data.label
      : resolved.label;
    renderActiveOverlays();
  });

})(Drupal, once, drupalSettings);
