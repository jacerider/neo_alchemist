(function (Drupal) {

  /**
   * How an outline relates to the current selection.
   *
   * - hover:  the pointer is over it
   * - active: an ancestor of the selection, shown as context
   * - ghost:  a child container of the selection, shown so nesting is visible
   * - focus:  the selection itself
   */
  type OverlayState = 'hover' | 'active' | 'ghost' | 'focus';

  // Define interfaces for better type safety
  interface ElementData {
    type: string;
    data: any;
    parents: string[];
    children: string[];
    events?: Record<string, any>;
  }

  /**
   * Interface for component operations
   */
  interface Actions {
    library: (uuid: string | null) => void;
    sort: (uuid: string | null) => void;
  }

  /**
   * Interface for component operations
   */
  interface Operations {
    edit: (uuid: string) => void;
    sort: (uuid: string) => void;
    delete: (uuid: string) => void;
    clone: (uuid: string) => void;
    add: (uuid: string, position: string) => void;
    move: (uuid: string, direction: string) => void;
  }

  // Elements
  const container = document.querySelector('.neo-alchemist-manage') as HTMLElement;
  if (!container) return;
  const wrapper = container.querySelector('.neo-alchemist-manage--wrapper') as HTMLElement;
  if (!wrapper) return;
  const iframes = {} as Record<'desktop' | 'tablet' | 'mobile', HTMLIFrameElement>;
  const panelTop = container.querySelector('.neo-alchemist--panel-top') as HTMLElement;
  const panelBottom = container.querySelector('.neo-alchemist--panel-bottom') as HTMLElement;
  const panelTitle = container.querySelector('.neo-alchemist--panel-title') as HTMLElement;
  const panelPositionOps = container.querySelector('.neo-alchemist--panel-position-ops') as HTMLElement | null;
  // Scoped to this layout. A single global preference leaked a choice made on
  // a nested layout onto every flat one, which is exactly what the depth
  // default exists to avoid.
  const layersStorageKey = `neo-alchemist-layers:${drupalSettings.neoAlchemist.baseUrl}`;
  localStorage.removeItem('neo-alchemist-layers');
  const layersPanel = container.querySelector('.neo-alchemist--layers') as HTMLElement | null;
  const layersTree = container.querySelector('.neo-alchemist--layers-tree') as HTMLElement | null;
  const layersToggleButton = container.querySelector('.neo-alchemist--layers-toggle') as HTMLElement | null;
  const layersCloseButton = container.querySelector('.neo-alchemist--layers-close') as HTMLElement | null;
  // The component edit form takes the right-hand side, which is where the
  // layers panel floats. Editing one component's props is not a moment for
  // navigating the tree, so the panel stays out of that view entirely.
  const hasSideForm = !!container.querySelector('.neo-alchemist--form-side');
  container.querySelectorAll('iframe').forEach(iframe => {
    if (!iframe.dataset.size) return;
    const size = iframe.dataset.size as 'desktop' | 'tablet' | 'mobile';
    iframes[size] = iframe;
  });
  Drupal.neoAlchemist = Drupal.neoAlchemist || {};
  Drupal.neoAlchemist.iframes = iframes;

  // Data
  const structureElements: Record<string, Record<'desktop' | 'tablet' | 'mobile', HTMLElement>> = {};
  // Shades are the dimming layer, and only one node per depth is ever shaded
  // (the focused node and its ancestor chain), so they stay keyed by depth —
  // but created on demand rather than from a fixed pool, so nesting is not
  // capped at three tiers.
  const shadeElements = {} as Record<'desktop' | 'tablet' | 'mobile', Record<number, HTMLElement>>;
  // Outlines are keyed by uuid, not depth: several are visible at once (the
  // focused node plus its child containers), which a per-depth pool cannot do.
  const overlayElements: Record<string, Record<'desktop' | 'tablet' | 'mobile', HTMLElement>> = {};
  const transitionSpeed = 200; // in ms
  const sizes = ['desktop', 'tablet', 'mobile'] as const;
  const baseModalOptions: any = {
    width: '100%',
    height: '100%',
    neo: {
      displaceTop: '0px',
      displaceBottom: '0px',
    },
  };
  const debugElements = false;
  const actionButtons = container.querySelectorAll<HTMLElement>('.neo-alchemist--action');
  let opButtons:NodeListOf<HTMLElement> | null = null;
  let scale: string = localStorage.getItem('neo-alchemist-scale') || '1';
  let structureData: Record<string, ElementData> = {};
  let positionData = {} as Record<'desktop' | 'tablet' | 'mobile', Record<string, DOMRect>>;
  let overlayTimeouts = {} as Record<string, ReturnType<typeof setTimeout> | null>;
  let overlayState = {} as Record<string, null | OverlayState>;
  let layerUuid: string | null = null;
  let regionUuid: string | null = null;
  // Child containers of the selection, drawn permanently while it holds.
  let ghostUuids: string[] = [];
  // Where the pointer went down on the canvas. Panning works over components
  // now, so a press that turns into a drag must not also select or deselect;
  // a few pixels of slop keeps an ordinary click from being read as a drag.
  const dragThreshold = 4;
  let pointerStartX = 0;
  let pointerStartY = 0;
  // The open breadcrumb descend menu, if any. Only one at a time.
  let openCrumbMenu: HTMLElement | null = null;
  // -1 when nothing is focused. Otherwise the focused node's depth, unbounded.
  let layerLevel: number = -1;
  // Detached prototypes, cloned on demand.
  let shadeBase: HTMLElement;
  let overlayBase: HTMLElement;
  let seamBase: HTMLElement;
  // Insertion points currently drawn on the canvas.
  let seamElements: HTMLElement[] = [];
  // True when the only seam drawn is the toolbar Add's hover preview, which is
  // torn down again on leave rather than left behind.
  let seamPreviewOnly = false;
  // Grace period before hover-drawn seams go, so the pointer can travel from
  // the component onto the seam's own button without losing it.
  let seamHoverTimeout: ReturnType<typeof setTimeout> | null = null;
  // Outline op buttons currently standing in for a suppressed tail seam.
  let seamTargetButtons: HTMLElement[] = [];
  const seamTargetButtonClasses = ['animate-pulse', 'ring-2', 'ring-primary-500'];

  // Hybrid mode: the field layout is locked, but one or more regions are
  // entity-customizable. Inherited components (the locked owner/header/footer)
  // become inert and customizable regions are directly clickable at the top
  // level, so the creator interacts only with the editable regions. All of
  // this is gated by `hybrid` — normal editing is unaffected.
  const hybrid = !!(drupalSettings.neoAlchemist && drupalSettings.neoAlchemist.hybrid);
  function isInheritedComponent(uuid: string | null): boolean {
    return !!uuid && structureData[uuid]?.data?.inherited === true;
  }
  function isCustomRegion(uuid: string | null): boolean {
    return !!uuid && structureData[uuid]?.type === 'region' && structureData[uuid]?.data?.custom === true;
  }
  // A layer that can be focused directly (without drilling through a parent).
  function isEditableTop(uuid: string | null): boolean {
    return hybrid && isCustomRegion(uuid);
  }
  // A first-level customizable region in an entity layout. Its inherited owner
  // is inert, so the region shades as if it were the page's top level: light
  // page shade clipped around the region, no dark shade inside the owner.
  // Nested regions deeper in the tree keep the normal owner-scoped shading.
  function isHybridRootRegion(uuid: string | null): boolean {
    if (!uuid || !isEditableTop(uuid)) {
      return false;
    }
    const parents = structureData[uuid].parents;
    return parents.length === 1 && isInheritedComponent(parents[0]);
  }
  let layerInteractSize: 'desktop' | 'tablet' | 'mobile' = 'desktop';
  let passthroughTimeout: ReturnType<typeof setTimeout> | null = null;
  let eventPendingStatus = false;
  let eventPendingHistory: Array<string> = [];
  const eventHistory: Record<string, {
    uuid: string;
    events: Array<string>;
  }> = {};

  // Iframe load handling
  //
  // What is needed is one report per frame, which is not the same thing as
  // counting load events. Two of them are not a frame finishing:
  //
  //  - A frame that carries data-src has no src at all until
  //    staggerIframeLoads() starts it, so it sits on about:blank — and the
  //    browser fires load for that empty document, within a few ms of the
  //    frame being parsed. A blind counter reaches three off two about:blank
  //    loads plus the desktop frame, asks all three for position data while
  //    two are still empty, and only ever hears back from one. ready() is
  //    then never reached: no outlines, no hit targets, nothing to click, and
  //    the real loads that follow cannot recover it because the count was
  //    already spent. Whether it happens comes down to a few milliseconds
  //    between the frames being parsed and this script running, which is why
  //    it follows the machine rather than the page.
  //  - The desktop frame carries its src in the markup, so it can equally
  //    finish before this script runs, and that load is simply never heard.
  //
  // Keying on the frame and seeding from whatever has already finished makes
  // the handshake independent of when this script runs relative to the loads.
  // iframeReady() mirrors iframeHasLoaded() in component-parent.ts, which
  // draws the same distinction for the same reason.
  const iframesLoaded = new Set<string>();

  function iframeReady(iframe: HTMLIFrameElement): boolean {
    if (iframe.dataset.src) {
      // Deferred, and not started yet.
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

  function iframeLoaded(size: string, iframe: HTMLIFrameElement): void {
    if (!iframe.contentWindow || !iframeReady(iframe) || iframesLoaded.has(size)) {
      return;
    }
    iframesLoaded.add(size);

    if (size === 'desktop') {
      // Request structure data from desktop iframe.
      iframe.contentWindow.postMessage({
        type: 'getStructureData',
      }, "*");
    }

    if (iframesLoaded.size === Object.keys(iframes).length) {
      iframesLoaded.clear();
      // All frames loaded.
      Object.values(iframes).forEach(iframe => {
        if (!iframe.contentWindow) {
          return;
        }
        iframe.contentWindow.postMessage({
          type: 'getPositionData',
        }, "*");
      });
    }
  }

  Object.entries(iframes).forEach(([size, iframe]) => {
    iframe.addEventListener('load', () => iframeLoaded(size, iframe));
    // Catch up on a frame that finished before this script ran. Its load event
    // is gone and is not coming back.
    iframeLoaded(size, iframe);
  });

  // Watch for scale changes
  const scaleCallback = (event: CustomEvent<any>) => {
    const detail = event.detail as { scale: string };
    scale = detail.scale as string;
  }
  container.addEventListener('alchemistManageScale', scaleCallback as EventListener);
  container.addEventListener('alchemistManageScaleEnd', () => {
    elementsPosition();
    layerShow(layerUuid, true, true);
  });

  // Handle postMessage communication
  window.addEventListener('message', (e) => {
    const data = e.data;
    if (typeof data.type === 'string') {
      const handler = onChild[data.type];
      if (typeof handler === 'function') {
        handler(data);
      }
    }
  });

  // Events called from child iframes.
  //
  // Keyed by frame rather than counted, for the same reason as the loads
  // above: three replies is only three frames if they are three different
  // frames, and ready() reads every size's rects.
  const iframesFinished = new Set<string>();
  const onChild:any = {
    structureData: function (data: any) {
      structureData = data.data;
    },
    regionAdd: function (data: any) {
      // The empty-region placeholder was clicked in a preview iframe. Open the
      // library scoped to that region (uuid--shape) regardless of focus state.
      if (data.uuid) {
        actions.library(data.uuid);
      }
    },
    positionData: function (data: any) {
      const size = data.size as 'desktop' | 'tablet' | 'mobile';
      positionData[size] = data.data;
      iframesFinished.add(size);
      if (iframesFinished.size === Object.keys(iframes).length) {
        iframesFinished.clear();
        ready();
      }
    },
    positionUpdateData: function (data: any) {
      const size = data.size as 'desktop' | 'tablet' | 'mobile';
      positionData[size] = data.data;
      elementsPosition();
      layerShow(layerUuid, true, true);
    },
    onEvent: function (data: any) {
      const eventType = data.eventType as string;
      const eventUuid = data.uuid as string;
      const eventData = getEventData(eventUuid);

      sizes.forEach(size => {
        if (size !== data.size) {
          const iframe = iframes[size];
          if (!iframe.contentWindow) {
            return;
          }
          iframe.contentWindow.postMessage({
            type: 'doEvent',
            uuid: eventUuid,
            eventType: eventType,
          }, "*");
        }
      });

      switch (eventType) {
        case 'mouseover':
          eventPendingHistory = [];
          eventPendingStatus = false;
          break;
        case 'mouseenter':
          clearTimeout(passthroughTimeout || undefined);
          container.classList.add('neo-alchemist--passthrough');
          break;
        case 'mouseleave':
          passthroughTimeout = setTimeout(() => {
            container.classList.remove('neo-alchemist--passthrough');
          }, 50);
          if (eventPendingStatus) {
            if (eventData.action === 'toggle' && eventHistory[eventData.group]) {
              delete eventHistory[eventData.group];
            }
            else {
              eventHistory[eventData.group] = {
                uuid: eventUuid,
                events: eventPendingHistory,
              };
            }
          }
          break;
      }

      eventPendingHistory.push(eventType);

      if (eventType === eventData.type) {
        // Only record the event if it matches the defined type.
        eventPendingStatus = true;
      }
    }
  };

  initialize();

  /**
   * We have the component data.
   *
   * This is called only once.
   */
  function initialize() {
    shadeBase = container.querySelector('.neo-alchemist--shade-base') as HTMLElement;
    overlayBase = container.querySelector('.neo-alchemist--overlay-base') as HTMLElement;
    seamBase = container.querySelector('.neo-alchemist--seam-base') as HTMLElement;
    sizes.forEach(size => {
      shadeElements[size] = {} as Record<number, HTMLElement>;
    });
    shadeBase.remove();
    overlayBase.remove();
    seamBase.remove();

    // Close button handling
    wrapper.querySelectorAll<HTMLElement>('.neo-alchemist--close').forEach(closeButton => {
      closeButton.addEventListener('click', (e) => {
        e.preventDefault();
        layerShow();
      });
    });

    // Action button handling
    actionButtons.forEach(actionButton => {
      actionButton.addEventListener('click', (e) => {
        e.preventDefault();
        const actionKey = actionButton.dataset.action as keyof Actions;
        if (actionKey) {
          actionExecute(actionKey);
        }
      });
      // Show on the canvas where Add would drop a component, before it is
      // clicked. The button names its target; this points at it.
      if (actionButton.dataset.action === 'library') {
        actionButton.addEventListener('mouseenter', seamTargetShow);
        actionButton.addEventListener('mouseleave', seamTargetHide);
        actionButton.addEventListener('focus', seamTargetShow);
        actionButton.addEventListener('blur', seamTargetHide);
      }
    });

    // Operation button handling. The overlay ops live on per-uuid outlines
    // that only exist once structure data has arrived, so this re-runs from
    // ready() and skips buttons it has already bound.
    opButtonsBind();

    // The editor's only scroll listener. Overlays live in the wrapper's
    // scroll-content space so they translate for free — the name chips are the
    // one thing that has to be recomputed against the viewport. Covers the
    // wheel, the pan gesture (which writes scrollLeft/scrollTop directly) and
    // programmatic smooth scrolls alike.
    wrapper.addEventListener('scroll', labelsClampQueue, { passive: true });

    // Wrapper click handling
    wrapper.addEventListener('mousedown', e => {
      pointerStartX = e.clientX;
      pointerStartY = e.clientY;
    });
    wrapper.addEventListener('mouseup', e => {
      if (layerUuid === null) {
        return;
      }
      // Check if target has data-alchemist-ignore
      if (e.target instanceof HTMLElement && (e.target.dataset.alchemistIgnore !== undefined || e.target.closest('[data-alchemist-ignore]'))) {
        return;
      }
      // If mouse hasn't moved, we blur the component.
      if (!pointerDragged(e)) {
        layerBack();
      }
    });

    // Layers panel.
    layersTree?.addEventListener('keydown', layersKeydown);
    layersToggleButton?.addEventListener('click', (e) => {
      e.preventDefault();
      layersPanelToggle(!layersPanelIsOpen());
    });
    layersCloseButton?.addEventListener('click', (e) => {
      e.preventDefault();
      layersPanelToggle(false);
    });
    if (hasSideForm) {
      // No panel in the edit view, so nothing for the toggle to act on.
      layersToggleButton?.style.setProperty('display', 'none');
    }

    // Escape climbs out of the selection — component, then its region, then the
    // page — the same step clicking the dimmed area takes, so pressing it
    // repeatedly deselects. Escape had no other job on this page, but two
    // things do treat it as "close me" and get the press first: an open
    // breadcrumb menu, and any modal (its own handler runs on body, and
    // stealing the press would back the canvas out from under the dialog).
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') {
        return;
      }
      if (openCrumbMenu) {
        crumbMenuClose();
        return;
      }
      if (document.body.classList.contains('has-neo-modal')) {
        return;
      }
      layerBack();
    });
    document.addEventListener('click', (e) => {
      if (openCrumbMenu && e.target instanceof Node && !openCrumbMenu.parentElement?.contains(e.target)) {
        crumbMenuClose();
      }
    });

    // Watch for component focus event
    const focusCallback = (event: CustomEvent<any>) => {
      const detail = event.detail as { uuid: string };
      layerUuid = detail.uuid;
    }
    container.addEventListener('alchemistManageComponentFocus', focusCallback as EventListener);

    panelTop.style.display = '';
    panelTop.style.opacity = '0';
    panelTop.style.zIndex = '30';
    panelTop.style.cursor = 'default';
    panelTop.style.transition = `opacity ${transitionSpeed}ms ease-in-out, transform ${transitionSpeed}ms ease-in-out`;
    panelBottom.style.display = '';
    panelBottom.style.opacity = '0';
    panelBottom.style.zIndex = '30';
    panelBottom.style.cursor = 'default';
    panelBottom.style.transition = `opacity ${transitionSpeed}ms ease-in-out, transform ${transitionSpeed}ms ease-in-out`;
    panelsPosition();
  }

  /**
   * Pin the breadcrumb and ops bars to the canvas.
   *
   * They span the canvas rather than the viewport, so they stay aligned to the
   * previews rather than to the window.
   */
  function panelsPosition(): void {
    const rect = wrapper.getBoundingClientRect();
    panelTop.style.top = `${rect.top}px`;
    panelTop.style.left = `${rect.left}px`;
    panelTop.style.right = `${window.innerWidth - rect.right}px`;
    if (panelTop.style.transform !== 'translate(0px, 0px)') {
      panelTop.style.transform = `translate(0, -${rect.top}px)`;
    }
    panelBottom.style.bottom = `${window.innerHeight - rect.bottom}px`;
    panelBottom.style.left = `${rect.left}px`;
    panelBottom.style.right = `${window.innerWidth - rect.right}px`;
    if (panelBottom.style.transform !== 'translate(0px, 0px)') {
      panelBottom.style.transform = `translate(0, ${rect.top}px)`;
    }
    layersPosition();
  }

  /**
   * Bind the operation buttons, including any newly built overlay ops.
   *
   * Safe to call repeatedly: already-bound buttons are skipped, and overlay
   * clones start unbound because the prototype is never marked.
   */
  function opButtonsBind(): void {
    opButtons = wrapper.querySelectorAll('.neo-alchemist--op') as NodeListOf<HTMLElement>;
    opButtons.forEach(opButton => {
      if (opButton.dataset.opBound) {
        return;
      }
      opButton.dataset.opBound = 'true';
      opButton.addEventListener('click', (e) => {
        e.preventDefault();
        const opKey = opButton.dataset.op as keyof Operations;
        if (opKey) {
          operationExecute(opKey);
        }
      });
    });
  }

  /**
   * The shade for a depth, created on first use.
   */
  function getShade(size: 'desktop' | 'tablet' | 'mobile', level: number): HTMLElement {
    let shade = shadeElements[size][level];
    if (shade) {
      return shade;
    }
    shade = shadeBase.cloneNode(true) as HTMLElement;
    shade.classList.remove('neo-alchemist--shade-base');
    shade.classList.add('neo-alchemist--shade');
    shade.setAttribute('data-size', size);
    shade.setAttribute('data-level', level.toString());
    shade.style.transition = `opacity ${transitionSpeed}ms ease-in-out`;
    shade.style.opacity = '0';
    shade.style.zIndex = (10 + level).toString();
    shade.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      // Panning across the dimmed area must not also back out of the layer.
      if (pointerDragged(e)) {
        return;
      }
      layerBack();
    });
    wrapper.appendChild(shade);
    shadeElements[size][level] = shade;
    return shade;
  }

  /**
   * Build the outline for one node at one size.
   */
  function buildOverlay(uuid: string, size: 'desktop' | 'tablet' | 'mobile', level: number): HTMLElement {
    const overlay = overlayBase.cloneNode(true) as HTMLElement;
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
    overlay.classList.remove('neo-alchemist--overlay-base');
    overlay.classList.add('neo-alchemist--overlay');
    overlay.setAttribute('data-uuid', uuid);
    overlay.setAttribute('data-size', size);
    overlay.setAttribute('data-level', level.toString());
    overlay.setAttribute('data-node-type', structureData[uuid]?.type || 'component');
    overlay.style.zIndex = (15 + level).toString();
    overlay.style.opacity = '0';
    // Allow editing with a double-click
    overlay.addEventListener('dblclick', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (layerUuid) {
        operationExecute('edit');
      }
    });
    overlay.style.transition = `all ${transitionSpeed}ms ease-in-out`;
    overlayLabel.style.opacity = '0';
    overlayActions.forEach(action => action.style.opacity = '0');
    wrapper.appendChild(overlay);
    // The op buttons carry #tooltip, which is inert markup until a behaviour
    // turns it into a tippy instance. This outline is a clone of a prototype
    // that initialize() pulled out of the DOM before behaviours ever ran, so
    // nothing has processed it and nothing else will — every copy of the +/move
    // cluster would sit there permanently untooltipped.
    Drupal.attachBehaviors(overlay, drupalSettings);
    return overlay;
  }

  /**
   * We have all the elements and positions, we are ready to go.
   *
   * This will be called once all iframes have sent position data. It can be
   * called multiple times if the iframes are reloaded.
   */
  function ready(): void {
    // Reset overlay state
    overlayState = {};
    // Clear existing elements. Outlines are rebuilt per uuid below, so stale
    // ones for nodes that no longer exist go with them.
    container.querySelectorAll<HTMLElement>('.neo-alchemist--element, .neo-alchemist--event, .neo-alchemist--overlay').forEach(element => {
      // Behaviours attached to an outline own state that outlives the node —
      // a tooltip is a tippy instance held in a module-level list, not markup.
      // Dropping the element without this runs ready() as a slow leak: every
      // save would strand another set, and they are walked on every attach.
      Drupal.detachBehaviors(element, drupalSettings, 'unload');
      element.remove();
    });
    Object.keys(structureElements).forEach(key => delete structureElements[key]);
    Object.keys(overlayElements).forEach(key => delete overlayElements[key]);
    Object.keys(overlayTimeouts).forEach(key => clearTimeout(overlayTimeouts[key] || undefined));
    overlayTimeouts = {};
    ghostUuids = [];

    // Build structure elements
    const elementBase = container.querySelector('.neo-alchemist--element-base') as HTMLElement;
    const eventBase = container.querySelector('.neo-alchemist--event-base') as HTMLElement;
    Object.entries(structureData).forEach(([uuid, data]) => {
      structureElements[uuid] = {} as Record<'desktop' | 'tablet' | 'mobile', HTMLElement>;
      overlayElements[uuid] = {} as Record<'desktop' | 'tablet' | 'mobile', HTMLElement>;
      sizes.forEach(size => {
        const level = data.parents.length;
        overlayElements[uuid][size] = buildOverlay(uuid, size, level);
        const element = elementBase.cloneNode(true) as HTMLElement;
        element.classList.remove('neo-alchemist--element-base');
        element.classList.add('neo-alchemist--element');
        element.setAttribute('data-uuid', uuid);
        element.setAttribute('data-size', size);
        element.setAttribute('data-level', level.toString());
        if (debugElements) {
          const debugColors = ['red', 'green', 'blue', 'magenta', 'cyan'];
          element.style.border = `1px solid ${debugColors[level % debugColors.length]}`;
        }
        element.style.cursor = 'pointer';
        element.style.zIndex = (20 + level).toString();
        element.addEventListener('mouseenter', () => {
          layerInteractSize = size;
          if (hybrid && isInheritedComponent(uuid)) {
            return;
          }
          // A customizable region is directly interactive at the top level.
          if (layerLevel + 1 === level || (isEditableTop(uuid) && layerLevel === -1)) {
            elementHover(uuid);
          }
        });
        element.addEventListener('mouseleave', () => {
          if (hybrid && isInheritedComponent(uuid)) {
            return;
          }
          if (layerLevel + 1 === level || (isEditableTop(uuid) && layerLevel === -1)) {
            elementBlur(uuid);
          }
        });
        element.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (hybrid && isInheritedComponent(uuid)) {
            return;
          }
          // The press that ended here was a pan, not a selection.
          if (pointerDragged(e)) {
            return;
          }
          elementFocus(uuid);
        });
        wrapper.appendChild(element);
        structureElements[uuid][size] = element;
        if (data.events) {
          Object.keys(data.events).forEach(eventId => {
            const eventTrigger = eventBase.cloneNode(true) as HTMLElement;
            eventTrigger.classList.remove('neo-alchemist--event-base');
            eventTrigger.classList.add('neo-alchemist--event');
            eventTrigger.setAttribute('data-event-id', eventId);
            eventTrigger.setAttribute('data-size', size);
            eventTrigger.style.display = 'none';
            eventTrigger.style.zIndex = '40';
            if (debugElements) {
              eventTrigger.style.border = '1px solid orange';
              eventTrigger.style.display = '';
            }
            eventTrigger.addEventListener('mouseenter', () => {
              layerInteractSize = size;
              clearTimeout(passthroughTimeout || undefined);
              container.classList.add('neo-alchemist--passthrough');
            });
            wrapper.appendChild(eventTrigger);
            structureElements[eventId] = structureElements[eventId] || {} as Record<'desktop' | 'tablet' | 'mobile', HTMLElement>;
            structureElements[eventId][size] = eventTrigger;
          });
        }
      });
    });

    // Pick up the ops on the outlines just built.
    opButtonsBind();

    elementsPosition();
    elementsToggle();
    // Hide the root Add/Sort in hybrid mode until a region is focused. When a
    // layer is restored below, layerShow() re-runs these.
    actionsToggle();
    actionLabelsSet(layerUuid);
    // Seams are positioned from the rects that were just replaced. With
    // nothing focused this draws the page's own insertion points; layerShow()
    // redraws them for the restored layer below.
    seamPreviewOnly = false;
    seamsShow(layerUuid);
    layersBuild();
    // An explicit choice wins, but only on the layout it was made on;
    // otherwise whether this tree has regions decides.
    const layersChoice = localStorage.getItem(layersStorageKey);
    layersPanelToggle(layersChoice ? layersChoice === 'open' : layersHasRegions(), true);

    if (layerUuid) {
      if (eventHistory && Object.keys(eventHistory).length > 0) {
        Object.entries(eventHistory).forEach(([_eventGroup, eventInfo]) => {
          eventInfo.events.forEach(eventType => {
            Object.entries(iframes).forEach(([size, iframe]) => {
              iframe.contentWindow?.postMessage({
                type: 'doEvent',
                size: size,
                uuid: eventInfo.uuid,
                eventType: eventType,
              });
            });
          });
        });
      }
      layerShow(layerUuid, true, true);
    }
  }

  function elementHover(uuid: string): void {
    clearTimeout(overlayTimeouts[uuid] || undefined);
    overlayShow(uuid);
    seamsHoverShow(uuid);
  }

  function elementFocus(uuid: string): void {
    // Inherited components are inert in hybrid mode.
    if (hybrid && isInheritedComponent(uuid)) {
      return;
    }
    layerShow(uuid);
  }

  /**
   * Whether the pointer travelled far enough to count as a drag.
   *
   * A short pan that stays over the same hit target still produces a click, so
   * selection has to check this or nudging the canvas would change the
   * selection under the cursor.
   */
  function pointerDragged(e: MouseEvent): boolean {
    return Math.abs(e.clientX - pointerStartX) > dragThreshold
      || Math.abs(e.clientY - pointerStartY) > dragThreshold;
  }

  function elementBlur(uuid: string): void {
    overlayState[uuid] = null;
    seamsHoverHide();
    overlayTimeouts[uuid] = setTimeout(() => {
      // A child container of the selection is drawn permanently; leaving it
      // should fall back to that, not erase it.
      if (ghostUuids.includes(uuid)) {
        overlayShow(uuid, 'ghost', true);
        return;
      }
      overlayHide(uuid);
    }, 200);
  }

  function getEventData(eventUuid: string): any {
    for (const uuid in structureData) {
      const data = structureData[uuid];
      if (data.events && data.events[eventUuid]) {
        return data.events[eventUuid];
      }
    }
    return null;
  }

  /**
   * Show overlay for the given component/region UUID.
   */
  function overlayShow(uuid: string, state: OverlayState = 'hover', force: boolean = false): void {
    if (!force && overlayState[uuid] && overlayState[uuid] === state) {
      return;
    }
    sizes.forEach(size => {
      overlayShowSize(uuid, size, state);
    });
    overlayState[uuid] = state;
  }

  function overlayShowSize(uuid: string, size: 'desktop' | 'tablet' | 'mobile', state: OverlayState = 'hover'): void {
    const data = structureData[uuid];
    const level = data.parents.length;
    const overlay = overlayElements[uuid]?.[size];
    const rect = positionData[size]?.[uuid];
    if (!overlay || !rect) {
      return;
    }
    const heightOffset = level >= 1 ? 0 : 0;
    const absoluteRect = calculateIframeRect(size, rect, heightOffset);
    overlay.style.left = `${absoluteRect.left}px`;
    overlay.style.top = `${absoluteRect.top}px`;
    overlay.style.width = `${absoluteRect.width}px`;
    overlay.style.height = `${absoluteRect.height}px`;
    overlay.style.opacity = '1';
    overlay.style.pointerEvents = 'none';
    overlay.setAttribute('data-state', state);
    switch (state) {
      case 'hover':
        overlayHover(uuid, size, overlay, data);
        break;
      case 'active':
        overlayActive(uuid, size, overlay, data);
        break;
      case 'ghost':
        overlayGhost(uuid, size, overlay, data);
        break;
      case 'focus':
        overlayFocus(uuid, size, overlay, data);
        break;
    }
  }

  /**
   * Keep every visible name chip inside the canvas viewport.
   *
   * A chip sits at its outline's top-left corner. Six of the thirty nodes on a
   * typical page are taller than the canvas, so once one of those is focused
   * and scrolled into, its top-left corner — and with it the only thing on the
   * canvas naming the selection — is off screen. Slide the chip along the
   * outline so it rides the visible edge, and let it settle back onto the true
   * corner as soon as that corner returns.
   */
  function labelsClamp(): void {
    const wrapperRect = wrapper.getBoundingClientRect();
    // Clamp to the space actually left over, not the raw canvas edge: the
    // breadcrumb bar covers the top of the canvas whenever anything is
    // focused, which is exactly when chips are on screen.
    const clampTop = wrapperRect.top + panelTopHeight();
    Object.entries(overlayElements).forEach(([uuid, bySize]) => {
      if (!overlayState[uuid]) {
        return;
      }
      sizes.forEach(size => {
        const overlay = bySize[size];
        const label = overlay?.querySelector<HTMLElement>('.neo-alchemist--overlay-label');
        if (!overlay || !label || label.style.opacity !== '1') {
          return;
        }
        const rect = overlay.getBoundingClientRect();
        const labelRect = label.getBoundingClientRect();
        // Subtract the offset already applied to recover where the chip sits
        // untouched. Deriving that from the outline instead would have to model
        // the chip's own margin, and would drift if that styling changed.
        const appliedX = parseFloat(label.dataset.clampX || '0');
        const appliedY = parseFloat(label.dataset.clampY || '0');
        const shiftY = clamp(clampTop - (labelRect.top - appliedY), 0, Math.max(0, rect.height - labelRect.height));
        const shiftX = clamp(wrapperRect.left - (labelRect.left - appliedX), 0, Math.max(0, rect.width - labelRect.width));
        label.dataset.clampX = String(shiftX);
        label.dataset.clampY = String(shiftY);
        label.style.transform = shiftX || shiftY ? `translate(${shiftX}px, ${shiftY}px)` : '';
      });
    });
  }

  function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
  }

  /**
   * How much of the canvas the breadcrumb bar is covering, if it is up.
   */
  function panelTopHeight(): number {
    return panelTop.style.opacity === '1' ? panelTop.getBoundingClientRect().height : 0;
  }

  /**
   * How much of the canvas the ops bar is covering, if it is up.
   */
  function panelBottomHeight(): number {
    return panelBottom.style.opacity === '1' ? panelBottom.getBoundingClientRect().height : 0;
  }

  /**
   * Run labelsClamp() at most once a frame while the canvas scrolls.
   */
  let labelsClampFrame: number | null = null;
  function labelsClampQueue(): void {
    if (labelsClampFrame !== null) {
      return;
    }
    labelsClampFrame = requestAnimationFrame(() => {
      labelsClampFrame = null;
      labelsClamp();
    });
  }

  /**
   * Escape text for interpolation into an overlay or breadcrumb.
   *
   * Labels carry entity-authored content — an array row's title is what tells
   * two sibling regions apart — so it never reaches innerHTML unescaped.
   */
  function escapeHtml(value: string): string {
    const span = document.createElement('span');
    span.textContent = value;
    return span.innerHTML;
  }

  /**
   * The type marker: filled for a component, a dashed outline for a container.
   *
   * Only for the lists that let you pick a node — the layers rows and the
   * breadcrumb's descend menus — where a name alone does not say which kind of
   * thing a row is, and the choice depends on it. Deliberately absent from the
   * canvas chip and the breadcrumb trail: both already carry the distinction
   * (the chip and the outline change colour for a region) and a second, quieter
   * copy of it there read as noise.
   */
  const markerClasses = 'neo-alchemist--node-marker shrink-0 w-2 h-2 rounded-xs';

  function markerElement(type: string): HTMLElement {
    const marker = document.createElement('span');
    marker.className = type === 'region'
      ? `${markerClasses} border border-dashed border-current`
      : `${markerClasses} bg-current`;
    return marker;
  }

  /**
   * Render the name chip for an outline.
   *
   * The label is escaped — it carries entity-authored content, since an array
   * row's title is what tells two sibling regions apart. Badges are not: they
   * arrive as server-rendered icon markup.
   */
  function overlayLabelSet(overlay: HTMLElement, data: ElementData): void {
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    // Colour alone carries the type here — the chip and the outline it sits on
    // both shift for a region, so a marker would only repeat what is already
    // being said.
    const chipClasses = data.type === 'region'
      ? 'bg-base-400 text-base-0'
      : 'bg-base-500 text-base-500-content';
    let html = `<span class="inline-flex items-center rounded-sm px-1.5 py-0.5 ${chipClasses}">`
      + `${escapeHtml(String(data.data.label ?? ''))}</span>`;
    (data.data.warnings || []).forEach((warning: string) => {
      html += ` <span class="badge rounded-sm bg-warning-500 text-warning-500-content">${warning}</span>`;
    });
    (data.data.alerts || []).forEach((alert: string) => {
      html += ` <span class="badge rounded-sm bg-alert-500 text-alert-500-content">${alert}</span>`;
    });
    overlayLabel.innerHTML = html;
  }

  function overlayHover(_uuid: string, _size: 'desktop' | 'tablet' | 'mobile', overlay: HTMLElement, data: ElementData): void {
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
    overlayLabel.style.transition = 'none';
    overlayLabel.style.opacity = '1';
    overlayLabelSet(overlay, data);
    if (data.type === 'component') {
      overlayActions.forEach(action => {
        action.style.transition = 'none';
        action.style.opacity = '0';
      });
    }
  }

  function overlayActive(uuid: string, size: 'desktop' | 'tablet' | 'mobile', overlay: HTMLElement, data: ElementData): void {
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
    // Ancestors stay quiet: the breadcrumb already names them, and a chip per
    // ancestor would crowd the canvas.
    overlayLabel.style.transition = '';
    overlayLabel.style.opacity = '0';
    overlayLabelSet(overlay, data);
    if (data.type === 'component') {
      overlayActions.forEach(action => {
        action.style.transition = 'none';
        action.style.opacity = '0';
      });
    }
    setTimeout(() => {
      shadeShowLevel(uuid, size);
    });
  }

  /**
   * A child container of the selection.
   *
   * Named but not shaded — the point is to answer "where can this go?" without
   * the author having to hover every part of the component to find out.
   */
  function overlayGhost(_uuid: string, _size: 'desktop' | 'tablet' | 'mobile', overlay: HTMLElement, data: ElementData): void {
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
    overlayLabel.style.transition = '';
    overlayLabel.style.opacity = '1';
    overlayLabelSet(overlay, data);
    overlayActions.forEach(action => {
      action.style.transition = 'none';
      action.style.opacity = '0';
    });
  }

  function overlayFocus(uuid: string, size: 'desktop' | 'tablet' | 'mobile', overlay: HTMLElement, data: ElementData): void {
    const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
    const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
    // Keep the name up while selected. Hiding it meant the canvas stopped
    // saying what was selected at the moment the author committed to it.
    overlayLabel.style.transition = '';
    overlayLabel.style.opacity = '1';
    overlayLabelSet(overlay, data);
    if (data.type === 'component') {
      overlayActions.forEach(action => {
        action.style.transition = '';
        action.style.opacity = '1';
      });
    }
    // An empty region renders only its "Add a component" placeholder, and this
    // overlay covers it exactly — an interactive overlay swallows that click and
    // the placeholder silently becomes a selection target. There is nothing in
    // an empty region to select, so leave the overlay inert and let the pointer
    // through to the placeholder, which already posts `regionAdd`.
    if (data.type !== 'region' || data.children.length) {
      overlay.style.pointerEvents = 'auto';
    }
    setTimeout(() => {
      shadeShowLevel(uuid, size);
    });
  }

  function overlayHide(uuid: string): void {
    const previousState = overlayState[uuid];
    overlayState[uuid] = null;
    const data = structureData[uuid];
    if (!data) {
      return;
    }
    overlayHideUuid(uuid);
    // Only 'active' and 'focus' ever raised a shade; a plain hover leaving
    // must not tear down the shade of whatever is focused at that depth.
    if (previousState === 'active' || previousState === 'focus') {
      shadeHideLevel(data.parents.length);
    }
  }

  function overlayHideUuid(uuid: string): void {
    const overlays = overlayElements[uuid];
    if (!overlays) {
      return;
    }
    sizes.forEach(size => {
      const overlay = overlays[size];
      if (overlay) {
        overlay.style.opacity = '0';
        // Drop the state with the outline, so the attribute always describes
        // something actually on screen.
        overlay.removeAttribute('data-state');
      }
    });
    overlayTimeouts[uuid] = setTimeout(() => {
      sizes.forEach(size => {
        const overlay = overlays[size];
        if (!overlay) {
          return;
        }
        const overlayLabel = overlay.querySelector('.neo-alchemist--overlay-label') as HTMLElement;
        const overlayActions = overlay.querySelectorAll('.neo-alchemist--overlay-actions') as NodeListOf<HTMLElement>;
        overlay.style.left = '';
        overlay.style.top = '';
        overlay.style.width = '';
        overlay.style.height = '';
        overlayLabel.style.opacity = '0';
        // Drop any clamp offset with the outline, so it does not reappear
        // shifted the next time this node is shown.
        overlayLabel.style.transform = '';
        delete overlayLabel.dataset.clampX;
        delete overlayLabel.dataset.clampY;
        overlayActions.forEach(action => action.style.opacity = '0');
      });
    }, transitionSpeed);
  }

  function shadeShowLevel(uuid: string, size: 'desktop' | 'tablet' | 'mobile'): void {
    const data = structureData[uuid];
    const level = data.parents.length;
    const shade = getShade(size, level);
    const rootRegion = isHybridRootRegion(uuid);
    const container = getShadeContainer(uuid, size);
    if (!structureElements[uuid]?.[size]) {
      return;
    }
    // Regions sit flush; components get a small vertical bleed so their ops
    // clear the edge. A hybrid root region shades as the page's top level, so
    // it takes the component treatment.
    const heightOffset = data.type === 'region' && !rootRegion ? 0 : 0;
    const absoluteRect = calculateRect(container.getBoundingClientRect());
    shade.style.left = `${absoluteRect.left}px`;
    shade.style.top = `${absoluteRect.top}px`;
    shade.style.width = `${absoluteRect.width}px`;
    shade.style.height = `${absoluteRect.height}px`;
    shade.style.opacity = '1';
    if (data.type === 'component' || rootRegion) {
      shade.classList.remove('bg-base-950/60');
      shade.classList.add('bg-base-0/60');
    }
    else {
      shade.classList.remove('bg-base-0/60');
      shade.classList.add('bg-base-950/60');
    }
    createClipPath(shade, structureElements[uuid][size], container, heightOffset);
  }

  function shadeHideLevel(level: number): void {
    sizes.forEach(size => {
      const shade = shadeElements[size][level];
      if (shade) {
        shade.style.opacity = '0';
      }
    });
  }

  /**
   * Hide every shade deeper than the given depth.
   *
   * Used when the focus falls back to a shallower node — anything the old,
   * deeper focus had raised must come down with it.
   */
  function shadeHideBelow(level: number): void {
    sizes.forEach(size => {
      Object.keys(shadeElements[size]).forEach(key => {
        if (Number(key) > level) {
          shadeElements[size][Number(key)].style.opacity = '0';
        }
      });
    });
  }

  function getShadeContainer(uuid: string, size: 'desktop' | 'tablet' | 'mobile'): HTMLElement {
    const data = structureData[uuid];
    const level = data.parents.length;
    if (level === 0 || isHybridRootRegion(uuid)) {
      return getIframe(size);
    }
    const lastParent = data.parents[data.parents.length - 1];
    return structureElements[lastParent]?.[size] || getIframe(size);
  }

  /**
   * Render the breadcrumb for the current selection.
   *
   * Each crumb also carries its own children, so the trail navigates in both
   * directions. Climbing out was already possible; getting back down meant
   * hunting for the node on the canvas again.
   */
  function titleSet(uuid: string | null): void {
    crumbMenuClose();
    panelTitle.innerHTML = '';
    if (!uuid || !structureData[uuid]) {
      panelTop.style.opacity = '0';
      panelTop.style.transform = `translate(0, -${panelTop.getBoundingClientRect().height}px)`;
      return;
    }
    const trail: Array<string | null> = [null, ...structureData[uuid].parents, uuid];
    trail.forEach((crumbUuid, index) => {
      if (index) {
        const sep = document.createElement('div');
        sep.textContent = '»';
        sep.style.opacity = '0.5';
        panelTitle.appendChild(sep);
      }
      panelTitle.appendChild(crumbBuild(crumbUuid, crumbUuid === uuid));
    });
    panelTop.style.opacity = '1';
    panelTop.style.transform = 'translate(0, 0)';
  }

  /**
   * The nodes reachable one step down from a crumb.
   */
  function crumbChildren(uuid: string | null): string[] {
    const children = uuid
      ? (structureData[uuid]?.children || [])
      : Object.keys(structureData).filter(key => structureData[key].parents.length === 0);
    // Inherited components are inert in hybrid mode, so offering them as
    // destinations would just lead to a dead end.
    return children.filter(child => !(hybrid && isInheritedComponent(child)));
  }

  function crumbBuild(crumbUuid: string | null, isCurrent: boolean): HTMLElement {
    const data = crumbUuid ? structureData[crumbUuid] : null;
    // The current layer, and inherited (locked) ancestors in hybrid mode, are
    // shown for context but are not clickable.
    const inert = hybrid && isInheritedComponent(crumbUuid);
    const crumb = document.createElement('div');
    crumb.className = 'neo-alchemist--crumb relative flex items-center whitespace-nowrap bg-base-0/15 border border-base-600 px-1 rounded';

    const link = document.createElement((isCurrent || inert) ? 'div' : 'a');
    link.className = `neo-alchemist--crumb-link flex items-center h-6 px-1 gap-1.5 text-base-900-content ${isCurrent ? 'font-medium' : 'font-light'}`;
    if (link instanceof HTMLAnchorElement) {
      link.href = '#';
      link.classList.add('hover:underline');
      link.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        layerShowOffCanvas(crumbUuid);
      });
    }
    if (data) {
      // No type marker in the trail. A crumb answers "where am I", and the
      // kind of each ancestor is not part of that answer.
      const text = document.createElement('span');
      text.textContent = String(data.data.label ?? '');
      link.appendChild(text);
      (data.data.warnings || []).forEach((warning: string) => {
        link.appendChild(badgeBuild(warning, 'warning'));
      });
      (data.data.alerts || []).forEach((alert: string) => {
        link.appendChild(badgeBuild(alert, 'alert'));
      });
    }
    else {
      link.innerHTML = '<i class="neo-icon neo-icon-font icon-regular-home"></i>';
      link.setAttribute('title', Drupal.t('Whole page'));
    }
    crumb.appendChild(link);

    const children = crumbChildren(crumbUuid);
    if (children.length) {
      crumb.appendChild(crumbMenuBuild(children));
    }

    // if (isCurrent) {
    //   const target = resolveAddTarget(crumbUuid);
    //   const add = document.createElement('button');
    //   add.type = 'button';
    //   add.className = 'neo-alchemist--crumb-add inline-flex items-center justify-center w-4.5 h-4.5 rounded-sm text-xs leading-none cursor-pointer transition bg-primary-500/85 text-primary-500-content hover:bg-primary-500';
    //   add.textContent = '+';
    //   add.setAttribute('title', target.uuid
    //     ? Drupal.t('Add a component to the end of @target', { '@target': target.label })
    //     : Drupal.t('Add a component to the end of the page'));
    //   add.addEventListener('click', (e) => {
    //     e.preventDefault();
    //     e.stopPropagation();
    //     actionExecute('library');
    //   });
    //   add.addEventListener('mouseenter', seamTargetShow);
    //   add.addEventListener('mouseleave', seamTargetHide);
    //   crumb.appendChild(add);
    // }

    return crumb;
  }

  /**
   * A warning or alert badge.
   *
   * Unlike the label, these arrive as markup already rendered by the server
   * (Component::prepareRenderableForPreview builds them through the icon
   * system from a fixed set of strings), so they are inserted as HTML.
   */
  function badgeBuild(markup: string, kind: 'warning' | 'alert'): HTMLElement {
    const badge = document.createElement('span');
    badge.className = `badge rounded-sm bg-${kind}-500 text-${kind}-500-content`;
    badge.innerHTML = markup;
    return badge;
  }

  /**
   * The descend menu for a crumb.
   */
  function crumbMenuBuild(children: string[]): HTMLElement {
    const holder = document.createElement('div');
    holder.className = 'neo-alchemist--crumb-menu-holder relative flex';

    const toggle = document.createElement('button');
    toggle.type = 'button';
    // The class is a hook, not styling — crumbMenuClose() finds the toggle
    // through it to put aria-expanded back.
    toggle.className = 'neo-alchemist--crumb-toggle inline-flex items-center justify-center w-4.5 h-4.5 rounded-sm text-xs leading-none cursor-pointer opacity-55 transition hover:opacity-100 hover:bg-base-0/15 focus-visible:opacity-100';
    toggle.setAttribute('aria-haspopup', 'true');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('title', Drupal.t('Go to a child'));
    toggle.textContent = '▾';

    const menu = document.createElement('div');
    menu.className = 'neo-alchemist--crumb-menu absolute top-full left-0 mt-1.5 z-40 min-w-48 max-h-72 overflow-y-auto p-1 rounded-md shadow-lg bg-base-0 text-base-0-content';
    menu.hidden = true;
    menu.setAttribute('role', 'menu');

    children.forEach(childUuid => {
      const child = structureData[childUuid];
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'neo-alchemist--crumb-menu-item flex items-center gap-1.5 w-full px-2 py-1 rounded-sm text-xs font-light text-left cursor-pointer hover:bg-base-100 focus-visible:bg-base-100';
      item.setAttribute('role', 'menuitem');
      item.appendChild(markerElement(child.type));
      const text = document.createElement('span');
      text.textContent = String(child.data.label ?? '');
      item.appendChild(text);
      // Preview on the canvas before committing to the jump.
      item.addEventListener('mouseenter', () => overlayShow(childUuid, 'hover', true));
      item.addEventListener('mouseleave', () => {
        if (overlayState[childUuid] === 'hover') {
          elementBlur(childUuid);
        }
      });
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        crumbMenuClose();
        layerShowOffCanvas(childUuid);
      });
      menu.appendChild(item);
    });

    toggle.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const open = !menu.hidden;
      crumbMenuClose();
      if (!open) {
        menu.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        openCrumbMenu = menu;
      }
    });

    holder.appendChild(toggle);
    holder.appendChild(menu);
    return holder;
  }

  function crumbMenuClose(): void {
    if (!openCrumbMenu) {
      return;
    }
    openCrumbMenu.hidden = true;
    openCrumbMenu.parentElement
      ?.querySelector('.neo-alchemist--crumb-toggle')
      ?.setAttribute('aria-expanded', 'false');
    openCrumbMenu = null;
  }

  let opTimeout: ReturnType<typeof setTimeout> | null = null;
  function opsSet(uuid: string | null): void {
    if (!opButtons) return;
    if (opTimeout) {
      clearTimeout(opTimeout);
      opTimeout = null;
    }

    // Find enabled buttons
    const enabledButtons: HTMLElement[] = [];
    if (uuid) {
      const data = structureData[uuid];
      const ops = data?.data?.ops;
      if (ops) {
        for (const [opKey, status] of Object.entries(ops)) {
          if (!status) {
            continue;
          }
          // Every copy of the op, not just the first found. The positional ops
          // now live in the bar as well as on the outline, and stopping at the
          // first match would leave the other copy hidden.
          const panelButton = panelBottom.querySelector<HTMLElement>(`[data-op="${opKey}"]`);
          if (panelButton) {
            enabledButtons.push(panelButton);
          }
          sizes.forEach(size => {
            const overlayButton = overlayElements[uuid]?.[size]?.querySelector<HTMLElement>(`[data-op="${opKey}"]`);
            if (overlayButton) {
              enabledButtons.push(overlayButton);
            }
          });
        }
      }
    }

    // Show/hide buttons and animate panel
    if (enabledButtons.length > 0) {
      const enabledSet = new Set(enabledButtons);
      opButtons.forEach(button => {
        button.style.display = enabledSet.has(button) ? '' : 'none';
      });
      // The positional group carries a divider, so it has to go when every one
      // of its buttons is denied — otherwise the bar keeps a rule with nothing
      // after it.
      if (panelPositionOps) {
        const anyVisible = Array.from(panelPositionOps.querySelectorAll<HTMLElement>('.neo-alchemist--op'))
          .some(button => button.style.display !== 'none');
        panelPositionOps.style.display = anyVisible ? '' : 'none';
      }
      panelBottom.style.opacity = '1';
      panelBottom.style.transform = 'translate(0, 0)';
    } else {
      const height = panelBottom.getBoundingClientRect().height;
      panelBottom.style.opacity = '0';
      panelBottom.style.transform = `translate(0, ${height}px)`;
      opTimeout = setTimeout(() => {
        if (!opButtons) return;
        opButtons.forEach(button => {
          button.style.display = 'none';
        });
      }, transitionSpeed);
    }
  }

  // let layerTimeout: ReturnType<typeof setTimeout> | null = null;
  function layerShow(uuid: string | null = null, force: boolean = false, instant: boolean = false): void {
    if (!uuid && !layerUuid) {
      return;
    }
    // Inherited components are inert in hybrid mode. Reachable here only via a
    // breadcrumb parent link; treat it as a deselect rather than focusing the
    // locked component.
    if (hybrid && isInheritedComponent(uuid)) {
      layerShow(null, force, instant);
      return;
    }

    let level: number = -1;
    let parents: string[] = [];
    if (uuid === layerUuid) {
      layerUuid = null;
    }
    if (uuid && !structureData[uuid]) {
      if (regionUuid && structureData[regionUuid]) {
        // The focused node is gone (deleted, or the tree re-rendered). Fall
        // back to its region and drop anything the old, deeper focus raised.
        uuid = regionUuid;
        shadeHideBelow(structureData[regionUuid].parents.length);
      }
      else {
        uuid = null;
      }
    }

    if (instant) {
      container.classList.add('neo-alchemist--instant');
      setTimeout(() => {
        container.classList.remove('neo-alchemist--instant');
      });
    }

    if (uuid) {
      let lastTree: string[] = [];
      let currentTree: string[] = [];
      if (layerUuid && structureData[layerUuid]) {
        const lastData = structureData[layerUuid];
        lastTree = [ ...lastData.parents, layerUuid ];
      }
      const data = structureData[uuid];
      layerUuid = uuid;
      currentTree = [ ...data.parents, uuid ];
      parents = data.parents;
      level = parents.length;
      parents.forEach(parentUuid => {
        // Inert inherited owners never render as an active layer.
        if (hybrid && isInheritedComponent(parentUuid)) {
          return;
        }
        overlayShow(parentUuid, 'active', force);
      });
      overlayShow(uuid, 'focus', force);

      // Draw the selection's own containers. Without this the only way to
      // learn a component has regions — or how many — is to sweep the pointer
      // across it until an invisible hit target lights up.
      const nextGhosts = data.children.filter(child => structureData[child]?.type === 'region');

      // Cleanup overlays that are not in current tree.
      const diff = lastTree.filter(item => !currentTree.includes(item));
      diff.concat(ghostUuids).forEach(removedUuid => {
        if (currentTree.includes(removedUuid) || nextGhosts.includes(removedUuid)) {
          return;
        }
        overlayHide(removedUuid);
      });

      nextGhosts.forEach(ghostUuid => overlayShow(ghostUuid, 'ghost', force));
      ghostUuids = nextGhosts;

      // Only the focused node and its ancestors raise a shade, so nothing
      // deeper than the focus may stay lit. overlayHide() normally takes a
      // shade down with its outline, but a node that becomes a ghost of the
      // new focus is deliberately not hidden — selecting a component, then its
      // region, then the owning component again would otherwise leave the
      // region's shade up and the preview reading as though the region were
      // still selected. The shades for this tree are queued asynchronously by
      // overlayActive()/overlayFocus(), so they re-raise after this.
      shadeHideBelow(level);
    }
    else {
      hideAll();
    }

    elementsToggle(uuid);
    titleSet(uuid);
    opsSet(uuid);

    if (uuid) {
      sizes.forEach(size => {
        const element = structureElements[uuid as string]?.[size];
        if (size === layerInteractSize && element) {
          Drupal.behaviors.neoAlchemistComponentParent.scrollElementIntoView(element, wrapper, 100);
        }
      });
    }

    layerLevel = level;
    regionUuid = nearestRegion(uuid);
    layerUuid = uuid;
    actionsToggle();
    actionLabelsSet(uuid);
    seamsShow(uuid);
    seamPreviewOnly = false;
    layersSelectionSet(uuid);
    // The breadcrumb and ops bars just changed visibility, so the space the
    // panel has to sit in changed with them.
    layersPosition();
    // Focusing something already scrolled past has to clamp straight away, not
    // wait for the next scroll.
    labelsClampQueue();
  }

  /**
   * Rebuild the layers rail from the current structure data.
   *
   * The canvas can only ever show one level below the selection, which makes a
   * nested tree something you discover by clicking rather than something you
   * can see. This renders the whole tree at once — every region, including the
   * empty and off-screen ones — with its own scoped add.
   */
  function layersBuild(): void {
    if (!layersTree) {
      return;
    }
    layersTree.innerHTML = '';
    const roots = Object.keys(structureData).filter(uuid => structureData[uuid].parents.length === 0);
    if (!roots.length) {
      layersPanelToggle(false, true);
      return;
    }
    roots.forEach(uuid => layersRowBuild(uuid, layersTree as HTMLElement));
    layersSelectionSet(layerUuid);
  }

  function layersRowBuild(uuid: string, parent: HTMLElement): void {
    const data = structureData[uuid];
    if (!data) {
      return;
    }
    const inert = hybrid && isInheritedComponent(uuid);
    const row = document.createElement('div');
    row.className = 'neo-alchemist--layer group flex items-center gap-1.5 py-1 pr-2 text-xs font-light'
      + (inert
        // Locked by an inherited layout: context only, not a destination.
        ? ' opacity-45 cursor-default'
        : ' cursor-pointer hover:bg-base-100 aria-selected:font-semibold aria-selected:bg-primary-500/15 aria-selected:shadow-[inset_2px_0_0_rgb(var(--color-primary-500))]');
    row.setAttribute('role', 'treeitem');
    row.setAttribute('data-uuid', uuid);
    row.setAttribute('aria-level', String(data.parents.length + 1));
    // Indent by depth. The value is per-row, so it stays inline.
    row.style.paddingLeft = `${0.375 + (data.parents.length * 0.75)}rem`;
    if (inert) {
      row.setAttribute('data-inert', 'true');
      row.setAttribute('aria-disabled', 'true');
    }

    row.appendChild(markerElement(data.type));

    const label = document.createElement('span');
    label.className = 'neo-alchemist--layer-label overflow-hidden text-ellipsis whitespace-nowrap';
    label.textContent = String(data.data.label ?? '');
    row.appendChild(label);

    if (data.type === 'region') {
      // Every region carries its own add, so the affordance no longer vanishes
      // the moment a region stops being empty. Revealed on hover or selection
      // so a long tree stays calm, but always reachable by keyboard.
      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'neo-alchemist--layer-add ml-auto shrink-0 inline-flex items-center justify-center w-4.5 h-4.5 rounded-sm text-xs leading-none cursor-pointer transition bg-primary-500 text-primary-500-content opacity-0 group-hover:opacity-100 group-aria-selected:opacity-100 focus-visible:opacity-100';
      add.textContent = '+';
      add.setAttribute('title', Drupal.t('Add a component to the end of @target', { '@target': String(data.data.label ?? '') }));
      add.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        actions.library(uuid);
      });
      row.appendChild(add);
    }

    // (data.data.warnings || []).forEach((warning: string) => row.appendChild(badgeBuild(warning, 'warning')));
    // (data.data.alerts || []).forEach((alert: string) => row.appendChild(badgeBuild(alert, 'alert')));

    if (!inert) {
      row.addEventListener('mouseenter', () => overlayShow(uuid, 'hover', true));
      row.addEventListener('mouseleave', () => {
        if (overlayState[uuid] === 'hover') {
          elementBlur(uuid);
        }
      });
      row.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        layerShowOffCanvas(uuid);
      });
    }

    parent.appendChild(row);
    data.children.forEach(childUuid => layersRowBuild(childUuid, parent));
  }

  /**
   * Mirror the canvas selection into the rail.
   */
  function layersSelectionSet(uuid: string | null): void {
    if (!layersTree) {
      return;
    }
    layersTree.querySelectorAll<HTMLElement>('.neo-alchemist--layer').forEach(row => {
      const isCurrent = !!uuid && row.dataset.uuid === uuid;
      row.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
      row.setAttribute('tabindex', isCurrent ? '0' : '-1');
      if (isCurrent) {
        row.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  /**
   * Move the rail's selection with the arrow keys.
   *
   * The canvas hit targets are bare divs with click handlers, so this is the
   * only keyboard route to a node.
   */
  function layersKeydown(e: KeyboardEvent): void {
    const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', ' '];
    if (!layersTree || !keys.includes(e.key)) {
      return;
    }
    const rows: HTMLElement[] = Array.from(layersTree.querySelectorAll<HTMLElement>('.neo-alchemist--layer:not([data-inert])'));
    if (!rows.length) {
      return;
    }
    e.preventDefault();
    const current = rows.findIndex(row => row.dataset.uuid === layerUuid);
    let next = current;
    switch (e.key) {
      case 'ArrowDown':
        next = current < 0 ? 0 : Math.min(current + 1, rows.length - 1);
        break;
      case 'ArrowUp':
        next = current < 0 ? rows.length - 1 : Math.max(current - 1, 0);
        break;
      case 'Home':
        next = 0;
        break;
      case 'End':
        next = rows.length - 1;
        break;
      case 'Enter':
      case ' ':
        if (current >= 0) {
          operationExecute('edit');
        }
        return;
    }
    const uuid = rows[next]?.dataset.uuid;
    if (uuid) {
      layerShowOffCanvas(uuid);
    }
  }

  /**
   * Whether this layout has anything to nest into.
   *
   * A layout with no regions is a flat list the canvas already shows in full,
   * so the panel would only repeat it and stays closed until asked for. One
   * region is enough to earn it, empty or not — that is where nesting starts,
   * and an empty region is exactly the thing that is hard to find on canvas.
   */
  function layersHasRegions(): boolean {
    return Object.values(structureData).some(data => data.type === 'region');
  }

  function layersPanelIsOpen(): boolean {
    return !!layersPanel && layersPanel.style.display !== 'none';
  }

  function layersPanelToggle(open: boolean, silent: boolean = false): void {
    if (!layersPanel || hasSideForm) {
      return;
    }
    layersPanel.style.display = open ? '' : 'none';
    // The toggle stays put either way — it is the one fixed way to reach the
    // tree — and reflects the state rather than disappearing into it.
    layersToggleButton?.classList.toggle('is-active', open);
    layersToggleButton?.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!silent) {
      localStorage.setItem(layersStorageKey, open ? 'open' : 'closed');
    }
    if (open) {
      layersPosition();
    }
  }

  /**
   * Pin the floating panel between the breadcrumb and the bottom bar.
   *
   * Both of those slide in and out with the selection, so their heights are
   * only counted while they are actually up.
   */
  function layersPosition(): void {
    if (!layersPanel || layersPanel.style.display === 'none') {
      return;
    }
    const gap = 16;
    const rect = wrapper.getBoundingClientRect();
    layersPanel.style.top = `${rect.top + panelTopHeight() + gap}px`;
    layersPanel.style.bottom = `${(window.innerHeight - rect.bottom) + panelBottomHeight() + gap}px`;
    layersPanel.style.right = `${(window.innerWidth - rect.right) + gap}px`;
    // Under the breadcrumb and ops bars, which own z-30, so a mid-transition
    // overlap never puts the panel on top of them.
    layersPanel.style.zIndex = '29';
  }

  /**
   * Remove every drawn insertion point.
   */
  function seamsClear(): void {
    seamElements.forEach(seam => seam.remove());
    seamElements = [];
  }

  /**
   * Draw the insertion points of the container currently being worked in.
   *
   * The shade decides what that is. Nothing focused means nothing is dimmed,
   * so the whole page is the workspace and the seams sit between its top-level
   * components. Focusing a region cuts that region out of the shade, so the
   * seams move inside it. Focusing a component cuts out only the component —
   * the rest of its container is dimmed context, and drawing insertion points
   * across dimmed space would invite adding somewhere you are not working. A
   * focused component keeps its own add-before/add-after cluster instead,
   * which sits on its own two edges, inside the cut-out.
   */
  function seamsShow(uuid: string | null): void {
    seamsClear();
    // Only a selected region draws a persistent set. Nothing selected means
    // the page is just being looked at, so it stays clean and the seams become
    // a hover affordance instead (see seamsHoverShow). A selected component is
    // not a container at all — it carries its own add-before/add-after.
    if (!uuid || structureData[uuid]?.type !== 'region') {
      return;
    }
    // Resolved through the same function the toolbar Add uses, so the two can
    // never disagree about where a component would land.
    const container = resolveAddTarget(uuid).uuid;
    const children = seamContainerChildren(container);
    if (!children.length) {
      return;
    }
    sizes.forEach(size => {
      const containerRect = container ? positionData[size]?.[container] : null;
      children.forEach((childUuid, index) => {
        const childRect = positionData[size]?.[childUuid];
        if (!childRect) {
          return;
        }
        // Top-level components have no container rect to span, so they set
        // their own width.
        const bounds = containerRect || childRect;
        if (index === 0) {
          seamBuild(size, container, bounds, childRect.top, childUuid, 'before', false);
        }
        const isLast = index === children.length - 1;
        seamBuild(size, container, bounds, childRect.top + childRect.height, childUuid, 'after', isLast);
      });
    });
  }

  /**
   * Draw the insertion points either side of a hovered top-level component.
   *
   * At page level nothing is selected, so there is no container to draw a
   * persistent set for — and drawing the whole page's worth on load buries the
   * preview under chrome before the author has asked for anything. These
   * follow the pointer instead, and only the two boundaries that touch the
   * component under it, so every boundary is still reachable by hovering the
   * component beside it.
   */
  function seamsHoverShow(uuid: string): void {
    // Selected region: its own persistent seams are already up. Selected
    // component: its cluster covers these two edges. Hybrid: the root is
    // locked, matching actionsToggle().
    if (layerUuid || hybrid) {
      return;
    }
    const data = structureData[uuid];
    if (!data || data.type !== 'component' || data.parents.length) {
      return;
    }
    seamHoverClearCancel();
    seamsClear();
    const children = seamContainerChildren(null);
    const index = children.indexOf(uuid);
    if (index < 0) {
      return;
    }
    sizes.forEach(size => {
      const rect = positionData[size]?.[uuid];
      if (!rect) {
        return;
      }
      seamBuild(size, null, rect, rect.top, uuid, 'before', false);
      seamBuild(size, null, rect, rect.top + rect.height, uuid, 'after', index === children.length - 1);
    });
    // The seam sits outside the component's hit target, so reaching for its
    // button leaves the component and would otherwise take the seam with it.
    seamElements.forEach(seam => {
      const button = seam.querySelector<HTMLElement>('button');
      button?.addEventListener('mouseenter', seamHoverClearCancel);
      button?.addEventListener('mouseleave', seamsHoverHide);
    });
  }

  function seamHoverClearCancel(): void {
    if (seamHoverTimeout !== null) {
      clearTimeout(seamHoverTimeout);
      seamHoverTimeout = null;
    }
  }

  function seamsHoverHide(): void {
    seamHoverClearCancel();
    seamHoverTimeout = setTimeout(() => {
      seamHoverTimeout = null;
      // A selection made in the meantime owns the seams now.
      if (!layerUuid) {
        seamsClear();
      }
    }, 250);
  }

  /**
   * The components a container holds, in order.
   *
   * A null container means the page root, whose children are the nodes with no
   * ancestors at all.
   */
  function seamContainerChildren(container: string | null): string[] {
    const children = container
      ? (structureData[container]?.children || [])
      : Object.keys(structureData).filter(key => structureData[key].parents.length === 0);
    return children.filter(child => structureData[child]?.type === 'component');
  }

  /**
   * Build one insertion point.
   *
   * @param y
   *   The boundary, in the preview iframe's coordinate space.
   * @param isTail
   *   Whether this is the end of the region — the point the toolbar Add uses.
   */
  function seamBuild(
    size: 'desktop' | 'tablet' | 'mobile',
    parentUuid: string | null,
    regionRect: DOMRect,
    y: number,
    siblingUuid: string,
    position: 'before' | 'after',
    isTail: boolean
  ): void {
    const seam = seamBase.cloneNode(true) as HTMLElement;
    seam.classList.remove('neo-alchemist--seam-base');
    seam.classList.add('neo-alchemist--seam');
    seam.setAttribute('data-size', size);
    if (isTail) {
      seam.setAttribute('data-tail', 'true');
    }
    const height = 24;
    const absoluteRect = calculateIframeRect(
      size,
      new DOMRect(regionRect.left, y, regionRect.width, 0),
    );
    seam.style.left = `${absoluteRect.left}px`;
    seam.style.top = `${absoluteRect.top - (height / 2)}px`;
    seam.style.width = `${absoluteRect.width}px`;
    seam.style.height = `${height}px`;
    seam.style.zIndex = '28';
    const button = seam.querySelector<HTMLElement>('button');
    button?.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      seamAdd(parentUuid, siblingUuid, position);
    });
    wrapper.appendChild(seam);
    seamElements.push(seam);
  }

  /**
   * Open the library scoped to an exact insertion point.
   *
   * Distinct from operations.add(), which derives its parent from the focused
   * component. Here the container is known outright, so it is passed
   * explicitly — and omitted entirely for the page root, which has no parent.
   */
  function seamAdd(parentUuid: string | null, siblingUuid: string, position: 'before' | 'after'): void {
    let url = `${drupalSettings.neoAlchemist.baseUrl}/library?${position}=${siblingUuid}`;
    if (parentUuid) {
      url += `&parent=${parentUuid}`;
    }
    Drupal.ajax({
      url: url,
      dialogType: 'modal',
      dialog: baseModalOptions,
    }).execute();
  }

  /**
   * Point at where the toolbar Add would drop a component.
   *
   * Usually that is the tail seam of the container. Two cases have no tail
   * seam to mark: the focused component is the container's last child, so its
   * own add-after covers that boundary and the seam was suppressed; or nothing
   * is focused, so no seams are drawn at all and a single inert one is built
   * just for the preview.
   */
  function seamTargetShow(): void {
    const tails = seamElements.filter(seam => seam.dataset.tail === 'true');
    if (tails.length) {
      tails.forEach(seam => seam.classList.add('is-target'));
      return;
    }

    const target = resolveAddTarget(layerUuid);
    const children = seamContainerChildren(target.uuid);
    if (!children.length) {
      return;
    }
    const lastChild = children[children.length - 1];

    // When the focused component is its container's last child, the boundary
    // the toolbar would append at is the one its own add-after already sits
    // on. Point at that button rather than drawing a seam over it. Only then —
    // for any other component the toolbar appends past it, somewhere else
    // entirely, and marking its add-after would be a lie.
    if (layerUuid === lastChild) {
      const ownAddAfter = Array.from(
        container.querySelectorAll<HTMLElement>('.neo-alchemist--overlay[data-state="focus"] [data-op="add-after"]')
      ).filter(button => button.style.display !== 'none');
      if (ownAddAfter.length) {
        ownAddAfter.forEach(button => button.classList.add(...seamTargetButtonClasses));
        seamTargetButtons = ownAddAfter;
        return;
      }
    }

    sizes.forEach(size => {
      const containerRect = target.uuid ? positionData[size]?.[target.uuid] : null;
      const childRect = positionData[size]?.[lastChild];
      if (!childRect) {
        return;
      }
      seamBuild(size, target.uuid, containerRect || childRect, childRect.top + childRect.height, lastChild, 'after', true);
    });
    seamElements.forEach(seam => seam.classList.add('is-target'));
    seamPreviewOnly = true;
  }

  function seamTargetHide(): void {
    seamTargetButtons.forEach(button => button.classList.remove(...seamTargetButtonClasses));
    seamTargetButtons = [];
    if (seamPreviewOnly) {
      seamsClear();
      seamPreviewOnly = false;
      return;
    }
    seamElements.forEach(seam => seam.classList.remove('is-target'));
  }

  /**
   * Select a node from off-canvas — the layers panel or the breadcrumb.
   *
   * layerInteractSize is normally set by hovering a hit target, which tells
   * layerShow() which preview frame to scroll. A selection made away from the
   * canvas has no pointer to infer that from, so it would keep whatever was
   * set last — in practice the desktop default, yanking the view back to the
   * desktop frame even when the author is working in tablet or mobile. Aim at
   * whichever frame they are actually looking at instead.
   */
  function layerShowOffCanvas(uuid: string | null): void {
    layerInteractSize = centeredSize();
    layerShow(uuid);
  }

  /**
   * The preview frame nearest the middle of the canvas viewport.
   */
  function centeredSize(): 'desktop' | 'tablet' | 'mobile' {
    const wrapperRect = wrapper.getBoundingClientRect();
    const center = wrapperRect.left + (wrapperRect.width / 2);
    let best = layerInteractSize;
    let bestDistance = Infinity;
    sizes.forEach(size => {
      const rect = getIframe(size).getBoundingClientRect();
      // Frames scrolled fully out of view are not what anyone is looking at.
      if (rect.right < wrapperRect.left || rect.left > wrapperRect.right) {
        return;
      }
      const distance = Math.abs((rect.left + (rect.width / 2)) - center);
      if (distance < bestDistance) {
        bestDistance = distance;
        best = size;
      }
    });
    return best;
  }

  /**
   * The region a node lives in — itself if it is one, else its nearest region
   * ancestor. Null when the node sits outside any region.
   *
   * This is the sticky context the ops fall back to, so it has to be resolved
   * by walking the ancestor chain rather than assumed from the node's depth.
   */
  function nearestRegion(uuid: string | null): string | null {
    if (!uuid) {
      return null;
    }
    const data = structureData[uuid];
    if (!data) {
      return null;
    }
    if (data.type === 'region') {
      return uuid;
    }
    for (let i = data.parents.length - 1; i >= 0; i--) {
      if (structureData[data.parents[i]]?.type === 'region') {
        return data.parents[i];
      }
    }
    return null;
  }

  /**
   * The customizable region the toolbar actions apply to for the focused layer.
   *
   * Either the focused region itself, or the custom-region ancestor of a
   * focused component inside it (region-in-region is disallowed, so a focused
   * component has exactly one custom-region ancestor). Null when the focus is
   * outside any customizable region.
   */
  function focusedCustomRegion(uuid: string | null): string | null {
    if (!uuid) return null;
    if (isCustomRegion(uuid)) return uuid;
    const data = structureData[uuid];
    if (!data) return null;
    return data.parents.find(p => isCustomRegion(p)) || null;
  }

  /**
   * Toggle the bottom toolbar Add/Sort in hybrid mode.
   *
   * Root add/sort is locked in hybrid mode, so these only make sense scoped to
   * a customizable region — but, as in non-hybrid, the bar stays up while any
   * layer *inside* that region is focused (a focused component's add/sort still
   * targets its region). Show Add whenever the focus is inside a region, and
   * Sort only when that region has something to reorder. Non-hybrid layouts
   * keep the buttons always visible (untouched).
   */
  function actionsToggle(): void {
    if (!hybrid || !actionButtons) return;
    const region = focusedCustomRegion(layerUuid);
    const childCount = region ? (structureData[region]?.children?.length || 0) : 0;
    actionButtons.forEach(button => {
      const action = button.dataset.action;
      const visible = action === 'sort' ? (!!region && childCount >= 2) : !!region;
      button.style.display = visible ? '' : 'none';
    });
  }

  function layerBack(): void {
    if (!layerUuid) return;
    const data = structureData[layerUuid];
    const lastParent = data.parents[data.parents.length - 1] || null;
    // In hybrid mode the inherited owner is inert, so backing out of a
    // top-level customizable region deselects rather than focusing the owner.
    if (hybrid && (!lastParent || isInheritedComponent(lastParent))) {
      layerShow(null);
      return;
    }
    layerShow(lastParent);
  }

  function hideAll(): void {
    overlayState = {};
    ghostUuids = [];
    Object.keys(overlayElements).forEach(uuid => overlayHideUuid(uuid));
    shadeHideBelow(-1);
    if (layerUuid && structureData[layerUuid]) {
      const data = structureData[layerUuid];
      if (data.events) {
        Object.keys(data.events).forEach(eventId => {
          sizes.forEach(size => {
            const eventTrigger = structureElements[eventId]?.[size];
            if (eventTrigger) {
              eventTrigger.style.display = 'none';
            }
          });
        });
      }
    }
  }

  /**
   * Position all elements according to the position data.
   */
  function elementsPosition() {
    sizes.forEach(size => {
      // The preview iframes load deferred and report their position data one at
      // a time, so a scale transition can finish — firing alchemistManageScaleEnd
      // — before a given size has reported in. Skip it until it has, rather than
      // let Object.entries() throw on undefined.
      const positions = positionData[size];
      if (!positions) {
        return;
      }
      Object.entries(positions).forEach(([uuid, rect]) => {
        const element = structureElements[uuid]?.[size];
        if (element) {
          const absoluteRect = calculateIframeRect(size, rect);
          element.style.left = `${absoluteRect.left}px`;
          element.style.top = `${absoluteRect.top}px`;
          element.style.width = `${absoluteRect.width}px`;
          element.style.height = `${absoluteRect.height}px`;
        }
      });
    });
  }

  /**
   * Toggle visibility of elements (triggers) based on the active layer UUID.
   */
  function elementsToggle(uuid?: string | null): void {
    if (debugElements) {
      return
    }
    let level = 0;
    let parents: string[] = [];
    let children: string[] = [];
    if (uuid) {
      const data = structureData[uuid];
      parents = data.parents;
      children = data.children;
      level = parents.length;
      // We want to show up to level + 1
      level++;
    }

    Object.entries(structureData).forEach(([elementUuid, data]) => {
      const dataLevel = data.parents.length;
      sizes.forEach(size => {
        // Hybrid, nothing focused: only customizable regions are directly
        // interactive; inherited components (and everything else) are inert,
        // so the creator can click straight into a region. The focused branch
        // below already inerts non-child ancestors and hides siblings, which
        // is correct for hybrid too (the inherited owner shows inert; other
        // inherited components hide).
        if (hybrid && !uuid) {
          if (isCustomRegion(elementUuid)) {
            showLayer(elementUuid, size);
          }
          else {
            hideLayer(elementUuid, size);
          }
          return;
        }
        // const element = structureElements[elementUuid][size];
        if (dataLevel <= level) {
          if (uuid) {
            if (children.includes(elementUuid)) {
              showLayer(elementUuid, size);
            }
            else if (uuid === elementUuid || parents.includes(elementUuid)) {
              showLayer(elementUuid, size, false);
            }
            else {
              hideLayer(elementUuid, size);
            }
            return;
          }
          else {
            showLayer(elementUuid, size);
          }
        }
        else {
          hideLayer(elementUuid, size);
        }
      });
    });
  }

  function showLayer(uuid: string, size: 'desktop' | 'tablet' | 'mobile', interact: boolean = true): void {
    const element = structureElements[uuid]?.[size];
    const data = structureData[uuid];
    if (!element) {
      return;
    }
    element.style.display = 'block';
    element.style.pointerEvents = interact ? 'auto' : 'none';
    if (interact) {
      const lastParent = data.parents[data.parents.length - 1];
      if (lastParent) {
        const parentData = structureData[lastParent];
        if (parentData?.events) {
          Object.keys(parentData.events).forEach(eventId => {
            const eventTrigger = structureElements[eventId]?.[size];
            if (eventTrigger) {
              eventTrigger.style.display = 'block';
            }
          });
        }
      }
    }
    else if (data.events) {
      Object.keys(data.events).forEach(eventId => {
        const eventTrigger = structureElements[eventId]?.[size];
        if (eventTrigger) {
          eventTrigger.style.display = 'none';
        }
      });
    }
  }

  function hideLayer(uuid: string, size: 'desktop' | 'tablet' | 'mobile'): void {
    const element = structureElements[uuid]?.[size];
    const data = structureData[uuid];
    if (!element) {
      return;
    }
    element.style.display = 'none';
    element.style.pointerEvents = 'none';
    if (data.events) {
      Object.keys(data.events).forEach(eventId => {
        const eventTrigger = structureElements[eventId]?.[size];
        if (eventTrigger) {
          eventTrigger.style.display = 'none';
        }
      });
    }
  }

  /**
   * The container the toolbar actions apply to, and what to call it.
   *
   * The toolbar acts on a container, not on the selection: selecting a region
   * targets that region, selecting a component targets the region holding it,
   * and selecting nothing targets the page. Resolved in one place so the
   * button label, the insertion preview and the request itself cannot disagree
   * about where a component is going to land.
   */
  function resolveAddTarget(uuid: string | null): { uuid: string | null, label: string } {
    const page = { uuid: null, label: Drupal.t('page') };
    if (!uuid) {
      return page;
    }
    const data = structureData[uuid];
    if (!data) {
      return page;
    }
    const targetUuid = data.type === 'region'
      ? uuid
      : (data.parents[data.parents.length - 1] || null);
    if (!targetUuid || !structureData[targetUuid]) {
      return page;
    }
    return { uuid: targetUuid, label: String(structureData[targetUuid].data.label ?? '') };
  }

  /**
   * Name the toolbar actions after the container they will act on.
   */
  function actionLabelsSet(uuid: string | null): void {
    const target = resolveAddTarget(uuid);
    actionButtons.forEach(button => {
      const label = button.querySelector<HTMLElement>('.neo-alchemist--action-label');
      if (!label) {
        return;
      }
      if (button.dataset.action === 'sort') {
        label.textContent = target.uuid
          ? Drupal.t('Reorder @target', { '@target': target.label })
          : Drupal.t('Reorder page');
        button.setAttribute('title', target.uuid
          ? Drupal.t('Reorder the components in @target', { '@target': target.label })
          : Drupal.t('Reorder the components on the page'));
        return;
      }
      label.textContent = target.uuid
        ? Drupal.t('Add to @target', { '@target': target.label })
        : Drupal.t('Add to page');
      button.setAttribute('title', target.uuid
        ? Drupal.t('Add a component to the end of @target', { '@target': target.label })
        : Drupal.t('Add a component to the end of the page'));
    });
  }

  function actionExecute(
    opKey: keyof Actions
  ): void {
    if (actions[opKey as keyof Actions]) {
      actions[opKey as keyof Actions](resolveAddTarget(layerUuid).uuid);
    }
  }

  const actions: Actions = {
    library: (uuid: string | null): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/library${uuid ? `?parent=${uuid}` : ''}`,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },

    sort: (uuid: string | null): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/sort${uuid ? `?parent=${uuid}` : ''}`,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },
  };

  function operationExecute(
    opKey: keyof Operations
  ): void {
    if (!layerUuid) return;
    const data = structureData[layerUuid];
    const [op, spec] = opKey.includes('-') ? opKey.split('-', 2) : [opKey, undefined];
    if (data.data.ops?.[opKey] && operations[op as keyof Operations]) {
      operations[op as keyof Operations](layerUuid, spec as string);
    }
  }

  /**
   * Operations available for components
   */
  const operations: Operations = {
    edit: (uuid: string): void => {
      const modalOptionsEdit: any = {
        ...baseModalOptions,
        neo: {
          ...baseModalOptions.neo,
          contentPadding: '0px',
        },
      };
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/edit/${uuid}`,
        dialogType: 'modal',
        dialog: modalOptionsEdit,
      }).execute();
    },

    sort: (uuid: string): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/sort?uuid=${uuid}${regionUuid ? `&parent=${regionUuid}` : ''}`,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },

    delete: (uuid: string): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/delete/${uuid}`,
        dialogType: 'modal',
        dialog: {
          ...baseModalOptions,
          width: 'auto',
          height: 'auto',
        },
      }).execute();
    },

    clone: (uuid: string): void => {
      Drupal.ajax({
        url: `${drupalSettings.neoAlchemist.baseUrl}/clone/${uuid}`,
      }).execute();
    },

    add: (uuid: string, position: string): void => {
      let url = `${drupalSettings.neoAlchemist.baseUrl}/library?${position}=${uuid}`;
      if (layerUuid) {
        const data = structureData[layerUuid];
        const parent = data.parents[data.parents.length - 1] || null;
        if (parent) {
          url += `&parent=${parent}`;
        }
      }
      Drupal.ajax({
        url: url,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },

    move: (uuid: string, direction: string): void => {
      let url = `${drupalSettings.neoAlchemist.baseUrl}/move/${uuid}/${direction}`;
      if (layerUuid) {
        const data = structureData[layerUuid];
        const parent = data.parents[data.parents.length - 1] || null;
        if (parent) {
          url += `?parent=${parent}`;
        }
      }
      Drupal.ajax({
        url: url,
      }).execute();
    },
  };

  /**
   * Calculate the relative position and size of a rect within the wrapper.
   */
  function calculateIframeRect(size: 'desktop' | 'tablet' | 'mobile', rect: DOMRect, heightOffset: number = 0): DOMRect {
    const scaleInt = parseFloat(scale);
    const containerRect = getIframe(size).getBoundingClientRect();
    const wrapperRect = wrapper.getBoundingClientRect();
    return new DOMRect(
      wrapper.scrollLeft + containerRect.left + (rect.left * scaleInt) + window.scrollX - wrapperRect.left,
      wrapper.scrollTop + containerRect.top + (rect.top * scaleInt) + window.scrollY - wrapperRect.top - heightOffset,
      rect.width * scaleInt,
      (rect.height * scaleInt) + (heightOffset * 2)
    );
  }

  /**
   * Calculate the absolute position and size of a rect within the wrapper.
   */
  function calculateRect(rect: DOMRect, heightOffset: number = 0): DOMRect {
    const wrapperRect = wrapper.getBoundingClientRect();
    return new DOMRect(
      wrapper.scrollLeft + rect.left + window.scrollX - wrapperRect.left,
      wrapper.scrollTop + rect.top + window.scrollY - wrapperRect.top - heightOffset,
      rect.width,
      rect.height + (heightOffset * 2)
    );
  }

  /**
   * Create clip path for the shade
   */
  function createClipPath(el: HTMLElement, target: HTMLElement, container: HTMLElement, heightOffset: number = 0): void {
    const rect = target.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();
    const top = rect.top - containerRect.top - heightOffset;
    const left = rect.left - containerRect.left;
    const right = left + rect.width;
    const bottom = top + rect.height + (heightOffset * 2);
    el.style.clipPath = `polygon(0% 0%, 0% 100%, ${left}px 100%, ${left}px ${top}px, ${right}px ${top}px, ${right}px ${bottom}px, ${left}px ${bottom}px, ${left}px 100%, 100% 100%, 100% 0%)`;
  }

  function getIframe(size: 'desktop' | 'tablet' | 'mobile'): HTMLIFrameElement {
    return iframes[size];
  }

})(Drupal);
