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
        scheduleItemsReport();
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
      indexPropHints(scope);
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
   * Places every hinted prop on the element it came from.
   *
   * Hints are values, not addresses: a heading's text, an image's basename, a
   * link's href. Matching them is an assignment problem — n props onto n
   * elements — and it used to be solved as three independent greedy sweeps,
   * one per hint type, each pairing the first prop to the first element that
   * matched. Three ways that went wrong, none of them specific to any one
   * component:
   *
   * - A value can match more elements than there are props carrying it. A
   *   component that renders its own breadcrumb has anchors to `/` that belong
   *   to no prop at all, and one surplus element shifted every later claim by
   *   one — so each card's link pointed at the row before it.
   * - A value can be carried by more than one prop. A link whose title *is*
   *   the caption beside it puts both props in the queue for one text node;
   *   whichever lost then fell through to a weaker sweep where it was no
   *   longer distinguishable from anything else.
   * - Each sweep saw one hint type alone, so a prop identifiable by its text
   *   AND its href together never got to use both.
   *
   * So: score every element a prop could have come from, counting all its
   * hint types at once, then commit only to what is forced, and fall back to
   * pairing by position only where that is actually sound.
   */
  const indexPropHints = (scope: HTMLElement): void => {
    const map = propMap;
    if (!map) {
      return;
    }

    const anchors = Array.from(scope.querySelectorAll<HTMLAnchorElement>('a[href]'));
    const imgs = Array.from(scope.querySelectorAll<HTMLImageElement>('img[src]'));

    // Text value → the elements directly holding a text node with that value.
    const byText: Record<string, HTMLElement[]> = {};
    const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
    let node: Node | null;
    while ((node = walker.nextNode())) {
      const text = (node.textContent || '').trim();
      const parent = (node as Text).parentElement;
      if (text && parent) {
        (byText[text] = byText[text] || []).push(parent);
      }
    }

    // Document position, so a candidate list can be compared and paired in the
    // order the markup renders — which is the order the map lists rows in.
    const order = new Map<HTMLElement, number>();
    scope.querySelectorAll<HTMLElement>('*').forEach((el, i) => order.set(el, i));

    // A hint type matching the element itself. Several of these on one element
    // is the whole point: text AND href together identify a link that neither
    // does alone.
    const SIGNAL = 4;
    // The element merely contains the matching text rather than holding it.
    // Worth less than a signal but more than nothing, and it is exactly what
    // tells an anchor from the span inside it.
    const NESTED = 1;

    const matchesHref = (anchor: HTMLAnchorElement, href: string): boolean => {
      return href.startsWith('/')
        ? (anchor.pathname + anchor.search === href || anchor.pathname === href)
        : (anchor.href === href || anchor.href.startsWith(href));
    };

    const scores = new Map<string, Map<HTMLElement, number>>();
    Object.keys(map.props).forEach(propId => {
      const hints = map.props[propId].hints;
      if (!hints) {
        return;
      }
      const score = new Map<HTMLElement, number>();
      const bump = (el: HTMLElement, by: number): void => {
        score.set(el, (score.get(el) || 0) + by);
      };
      // Whole-element matches first, so the text pass below can find the ones
      // that wrap a matching text node.
      const whole: HTMLElement[] = [];
      (hints.href || []).forEach(href => {
        anchors.forEach(anchor => {
          if (matchesHref(anchor, href)) {
            bump(anchor, SIGNAL);
            whole.push(anchor);
          }
        });
      });
      (hints.src || []).forEach(basename => {
        imgs.forEach(img => {
          let src = img.src;
          try {
            src = decodeURIComponent(src);
          }
          catch (_e) {
            // Keep the raw src.
          }
          if (src.includes(basename)) {
            bump(img, SIGNAL);
            whole.push(img);
          }
        });
      });
      (hints.text || []).forEach(text => {
        (byText[text] || []).forEach(el => {
          bump(el, SIGNAL);
          whole.forEach(candidate => {
            if (candidate !== el && candidate.contains(el)) {
              bump(candidate, NESTED);
            }
          });
        });
      });
      if (score.size) {
        scores.set(propId, score);
      }
    });

    /**
     * The elements still free that score highest for this prop.
     */
    const topCandidates = (propId: string): HTMLElement[] => {
      const score = scores.get(propId);
      if (!score) {
        return [];
      }
      let best = 0;
      score.forEach((value, el) => {
        if (!el.dataset.neoPropTarget && value > best) {
          best = value;
        }
      });
      if (!best) {
        return [];
      }
      const top: HTMLElement[] = [];
      score.forEach((value, el) => {
        if (!el.dataset.neoPropTarget && value === best) {
          top.push(el);
        }
      });
      return top.sort((a, b) => (order.get(a) ?? 0) - (order.get(b) ?? 0));
    };

    // Forced choices only, until nothing more is forced. Claiming an element
    // removes it from every other prop's candidates, which can force the next
    // one — so a link that only its href identifies frees the span inside it
    // for the caption that only its text identifies. Iterating rather than
    // sweeping once is what stops a single wrong guess cascading.
    let progressed = true;
    while (progressed) {
      progressed = false;
      Object.keys(map.props).forEach(propId => {
        if (claimedProps.has(propId)) {
          return;
        }
        const top = topCandidates(propId);
        if (top.length === 1) {
          progressed = claimTarget(top[0], propId) || progressed;
        }
      });
    }

    // What is left is genuinely ambiguous: rows whose content is identical, so
    // nothing but position tells them apart. Position IS the answer there —
    // but only when the candidates and the props claiming them are the same
    // set, which is the assumption the old sweeps made without ever checking
    // it. Where the counts differ something in the markup belongs to no prop,
    // and pairing in order would hand every prop its neighbour's element.
    const groups = new Map<string, { props: string[]; elements: HTMLElement[] }>();
    Object.keys(map.props).forEach(propId => {
      if (claimedProps.has(propId)) {
        return;
      }
      const top = topCandidates(propId);
      if (!top.length) {
        return;
      }
      const key = top.map(el => order.get(el)).join(',');
      const group = groups.get(key) || { props: [], elements: top };
      group.props.push(propId);
      groups.set(key, group);
    });
    groups.forEach(group => {
      if (group.props.length !== group.elements.length) {
        return;
      }
      group.props.forEach((propId, i) => claimTarget(group.elements[i], propId));
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
    // The markup that was going to answer it is about to be replaced.
    clearPendingReveal();
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
          scheduleItemsReport();
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
  /**
   * Whether an element is hidden by something other than its geometry.
   *
   * visibleRect() already discounts anything clipped out of view, which covers
   * the usual ways content goes away. It cannot see the other family: a
   * carousel stacks its slides at one rect and fades between them, so an
   * inactive slide has a perfectly good box and is simply not on screen. Left
   * to geometry alone the outline draws over whichever slide *is* showing, and
   * names it as the field the editor asked for.
   *
   * `aria-hidden` and `inert` are read as well as the visual properties,
   * because a component that hides a panel properly says so there — and it
   * says it on the panel, which is why this walks up rather than testing the
   * element alone.
   */
  const isHiddenTarget = (el: HTMLElement): boolean => {
    let node: HTMLElement | null = el;
    while (node) {
      if (node.hasAttribute('inert') || node.getAttribute('aria-hidden') === 'true') {
        return true;
      }
      const style = getComputedStyle(node);
      if (style.visibility === 'hidden' || style.visibility === 'collapse' || Number(style.opacity) === 0) {
        return true;
      }
      if (node === propScope) {
        break;
      }
      node = node.parentElement;
    }
    return false;
  };

  // The last state posted upward, so a burst of transitions that settles back
  // where it started says nothing.
  let lastItemsSignature = '';
  let itemsReportTimer = 0;

  /**
   * Tells the form which of this component's props are on screen right now.
   *
   * A component that shows one item at a time — a carousel, an accordion, a
   * tablist — has no way of saying so that the form could rely on, and the two
   * slider engines in this site's theme do not even agree with each other: one
   * keeps a numeric index and stamps a state class on the live slide, the
   * other rotates its own DOM and publishes nothing at all. What they do agree
   * on is how they hide what is not showing, which is exactly what
   * isHiddenTarget() already reads.
   *
   * So nothing is asked and nothing is declared. The preview measures what it
   * can see and reports it; the form decides what that means for its rows. The
   * form keeping no count of its own is the point — a counter maintained on
   * that side drifts the moment the component is swiped, re-rendered, or
   * revealed from somewhere else, and this cannot, because it is re-derived
   * from the markup every time the preview settles.
   */
  const reportPropItems = (): void => {
    if (!propScope) {
      return;
    }
    const byId = new Map<string, { propId: string; visible: boolean; steerable: boolean }>();
    propScope.querySelectorAll<HTMLElement>(targetSelector).forEach(el => {
      const visible = !isHiddenTarget(el);
      // Asked per element rather than once of the component root, so a
      // steerable region inside a static one answers for itself.
      const steerable = !!el.closest('[data-neo-reveal]');
      [el.dataset.neoPropTarget, el.dataset.neoProp].forEach(propId => {
        if (!propId) {
          return;
        }
        const seen = byId.get(propId);
        if (!seen) {
          byId.set(propId, { propId, visible, steerable });
          return;
        }
        // A prop can be stamped in more than one place — hero_s1 renders each
        // slide's image and its copy in separate stacks — and showing any one
        // of them is showing the prop. Read the other way round it comes out
        // wrong: that component's whole image stage is aria-hidden, so every
        // slide would report off screen and the form would believe the
        // component had no current item at all.
        seen.visible = seen.visible || visible;
        seen.steerable = seen.steerable || steerable;
      });
    });
    const items = Array.from(byId.values());
    const signature = items
      .map(item => item.propId + ':' + (item.visible ? '1' : '0') + (item.steerable ? '1' : '0'))
      .sort()
      .join('|');
    if (signature === lastItemsSignature) {
      return;
    }
    lastItemsSignature = signature;
    post('propItems', { items });
  };

  /**
   * Coalesces a burst of reports into one.
   *
   * Every slide in a crossfade ends its transition separately, so one move
   * fires the motion listener once per element. The form only wants the state
   * it settled into.
   */
  const scheduleItemsReport = (): void => {
    window.clearTimeout(itemsReportTimer);
    itemsReportTimer = window.setTimeout(() => reportPropItems(), 50);
  };

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

  /**
   * The event a component listens for to bring a hidden prop into view.
   *
   * The editor cannot know what a carousel is, and a carousel should not have
   * to know what the editor is — so it asks, in the one vocabulary they share:
   * this element, please. A component that hides parts of itself listens on its
   * root, works out which of its panels holds the target and shows that one.
   * Anything that does not listen simply does not answer, and the outline is
   * withheld rather than drawn somewhere untrue.
   *
   * @see the neo-component skill, "Revealing a hidden prop in the preview".
   */
  const REVEAL_EVENT = 'neo-alchemist:reveal';

  // How many times one focus request may ask to be revealed, and how long it
  // waits between asks.
  //
  // Asking once is not enough, because a component is entitled to ignore the
  // question. Every slider in this site's theme holds a lock while it moves —
  // `hero_s1` sets `busy` for 1080ms and `go()` returns early the whole time —
  // so a request made while the previous one is still animating is dropped on
  // the floor, silently and by design. Three asks 1200ms apart outlast that
  // lock with room for an image that loads slowly, and the bound is what keeps
  // a component that simply never answers from being asked forever.
  const REVEAL_ASKS = 3;
  const REVEAL_ASK_INTERVAL = 1200;

  // The request waiting on a reveal, and the asks it has left. A reveal is
  // usually a CSS transition, so the answer does not arrive in the same tick
  // as the question.
  let pendingReveal: { propId: string; propIds: string[] | null; label: string; asks: number } | null = null;
  let pendingRevealTimer = 0;

  const clearPendingReveal = (): void => {
    pendingReveal = null;
    window.clearTimeout(pendingRevealTimer);
    pendingRevealTimer = 0;
  };

  /**
   * Resolves a focus request and draws it, asking for a reveal if it is hidden.
   *
   * `asks` is how many times this request may still dispatch the reveal event.
   * It counts down rather than being a flag, because the honest answer to "did
   * the component act on it?" is "not yet" far more often than "no" — see
   * REVEAL_ASKS above.
   */
  const applyFocus = (propId: string, propIds: string[] | null, label: string, asks: number): void => {
    clearPendingReveal();
    const resolved = resolveFocus(propId, propIds);
    const visible = resolved.targets.filter(el => !isHiddenTarget(el));

    if (resolved.targets.length && !visible.length) {
      if (asks > 0) {
        pendingReveal = { propId, propIds, label, asks: asks - 1 };
        // From the target, so a listener can read `event.target` as well as the
        // detail, and bubbling so any ancestor component can answer.
        resolved.targets[0].dispatchEvent(new CustomEvent(REVEAL_EVENT, {
          bubbles: true,
          detail: { propId, target: resolved.targets[0] },
        }));
        // A reveal that needs no transition has landed by now; one that does is
        // picked up by settlePendingReveal() below, and this is both the floor
        // under that and the moment the next ask goes out.
        pendingRevealTimer = window.setTimeout(() => retryPendingReveal(), REVEAL_ASK_INTERVAL);
      }
      // Nothing is drawn meanwhile: an outline over the panel that happens to
      // be showing would name it as a field it is not.
      activeTargets = [];
      activeMode = 'none';
      activeLabel = '';
      renderActiveOverlays();
      return;
    }

    activeTargets = visible.length ? visible : resolved.targets;
    activeMode = resolved.mode;
    activeLabel = label || resolved.label;
    renderActiveOverlays();
  };

  /**
   * A component finishing a move: reposition, and notice a reveal landing.
   */
  const onMotionEnd = (): void => {
    refreshOverlays();
    settlePendingReveal();
    // A component that just moved may have moved a different item into view —
    // including when the editor did not ask it to, as a swipe in the canvas
    // does.
    scheduleItemsReport();
  };

  /**
   * Draws a pending reveal the moment it lands — and only then.
   *
   * Motion end is the earliest notice that a component has arrived somewhere,
   * so it is worth checking on. What it is not is evidence that the component
   * answered *this* request: one `hero_s1` crossfade fires 154 transitionend
   * events, the first about 200ms in, and every one of them is some other
   * element finishing its own move. Taking the request down on that reading is
   * what made the retry below dead code — the timer was cleared long before it
   * could fire, so the only ask that ever went out was the first one, and a
   * component that had dropped it was never asked again.
   *
   * So a still-hidden target is left strictly alone here. Giving up is the
   * timer's decision, and it makes it by running out of asks.
   */
  const settlePendingReveal = (): void => {
    if (!pendingReveal) {
      return;
    }
    const request = pendingReveal;
    const resolved = resolveFocus(request.propId, request.propIds);
    if (!resolved.targets.some(el => !isHiddenTarget(el))) {
      return;
    }
    applyFocus(request.propId, request.propIds, request.label, 0);
  };

  /**
   * Asks again, having waited REVEAL_ASK_INTERVAL for the last ask to land.
   */
  const retryPendingReveal = (): void => {
    if (!pendingReveal) {
      return;
    }
    const request = pendingReveal;
    applyFocus(request.propId, request.propIds, request.label, request.asks);
  };

  // Anything that moves an element by transform — an author's own transition,
  // a component's internal animation — resizes nothing, so the resize observer
  // never fires and an outline measured mid-flight would stay where the
  // element briefly was. Both events bubble, so one listener covers the
  // subtree however it is replaced.
  document.addEventListener('animationend', onMotionEnd);
  document.addEventListener('transitionend', onMotionEnd);

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
    applyFocus(
      typeof data.propId === 'string' ? data.propId : '',
      Array.isArray(data.propIds) ? data.propIds.filter((id: unknown) => typeof id === 'string') : null,
      typeof data.label === 'string' ? data.label : '',
      REVEAL_ASKS,
    );
  });

})(Drupal, once, drupalSettings);
