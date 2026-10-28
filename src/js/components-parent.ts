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

  /**
   * One editor op as it crosses the seam from the server.
   *
   * The server emits, for each op, a record of this shape into the
   * data-component attribute — replacing the flat map of access booleans it
   * sent before. `url` is generated server-side (base path, language prefix and
   * any alias already applied); the client reads it off the record rather than
   * building the path itself, and reads `verb`/`position` rather than inferring
   * them from the id. `permitted` gates the op — a record is always truthy, so
   * it is never tested for truthiness.
   */
  interface Op {
    id: string;
    permitted: boolean;
    url: string;
    label: string;
    verb: string;
    position: string | null;
  }

  /**
   * The payload the server encodes into the data-component / data-region
   * attribute. Component nodes carry ops/alerts/warnings/inherited; region
   * nodes carry id/custom; both share label. This is the type the seam had as
   * `any` before, which is why a server field the client never read was
   * invisible to both.
   */
  interface ComponentData {
    label?: string;
    alerts?: string[];
    warnings?: string[];
    inherited?: boolean;
    custom?: boolean;
    id?: string;
    ops?: Record<string, Op>;
  }

  // Define interfaces for better type safety
  interface ElementData {
    type: string;
    data: ComponentData;
    parents: string[];
    children: string[];
    events?: Record<string, any>;
  }

  /**
   * The toolbar actions. Each acts on the container the current selection
   * resolves to. The base URL is the action button's own server-generated href;
   * the client appends only the resolved container as a query parameter.
   */
  interface Actions {
    library: (parentUuid: string | null, baseUrl: string) => void;
  }

  /**
   * The per-component operations, keyed by the op's verb. Each takes a fully
   * resolved request URL — read off the op record, with any genuinely
   * client-side parameter already appended — and only chooses how the request
   * is dispatched (which modal, if any). No handler builds a path. Edit has
   * none: selecting a component already opens it in the inspector.
   */
  interface Operations {
    delete: (url: string) => void;
    clone: (url: string) => void;
    add: (url: string) => void;
    move: (url: string) => void;
  }

  // Elements
  const container = document.querySelector('.neo-alchemist-manage') as HTMLElement;
  if (!container) return;
  const wrapper = container.querySelector('.neo-alchemist-manage--wrapper') as HTMLElement;
  if (!wrapper) return;
  // The canvas chrome — outlines, hit targets, shades, seams — is drawn on a
  // layer inside the scaled frame row, in the frames' own (unscaled)
  // coordinates, so the transform that zooms the frames carries it too. Drawn
  // over the canvas in scaled coordinates instead, it could only be measured
  // again once a zoom had finished, and trailed every refit by a beat: select
  // a component, the inspector opens, the frames shrink, the border catches up.
  const scaleBox = container.querySelector<HTMLElement>('.neo-alchemist-manage--scale');
  const chrome = document.createElement('div');
  chrome.className = 'neo-alchemist--chrome';
  (scaleBox || wrapper).appendChild(chrome);
  const iframes = {} as Record<'desktop' | 'tablet' | 'mobile', HTMLIFrameElement>;
  // The inspector: the right dock the selected component's form loads into.
  // Its header carries the selection's ops.
  const dock = container.querySelector('.neo-alchemist--dock') as HTMLElement | null;
  // The ⋯ beside the inspected component's name: its ops, as a menu.
  const dockMore = dock?.querySelector<HTMLElement>('.neo-alchemist--dock-more') ?? null;
  const dockMoreToggle = dockMore?.querySelector<HTMLElement>('.neo-alchemist--dock-more-toggle') ?? null;
  const dockMenu = dockMore?.querySelector<HTMLElement>('.neo-alchemist--dock-menu') ?? null;
  const isMac = /Mac|iPhone|iPad/.test(navigator.userAgent);
  // The session's request-header token, fetched once, for the one op that
  // writes without a dialog's form token: Delete.
  let csrfToken: Promise<string> | null = null;
  function sessionToken(): Promise<string> {
    csrfToken ??= fetch(Drupal.url('session/token'), { credentials: 'same-origin' }).then(response => {
      if (!response.ok) {
        throw new Error('No session token');
      }
      return response.text();
    });
    return csrfToken;
  }
  // The breadcrumb bar over the canvas: where the selection sits. Only this
  // editor writes a trail, so only this editor shows the bar.
  const crumbBar = container.querySelector('.neo-alchemist--crumbs') as HTMLElement | null;
  const crumbTrail = crumbBar?.querySelector('.neo-alchemist--crumbs-trail') as HTMLElement | null;
  if (crumbBar) {
    crumbBar.hidden = false;
    // A menu is placed once, under its toggle; it would not follow the trail.
    crumbTrail?.addEventListener('scroll', () => crumbMenuClose());
  }
  const dockBody = dock?.querySelector('.neo-alchemist--dock-body') as HTMLElement | null;
  const dockPrompt = dock?.querySelector('.neo-alchemist--dock-prompt') as HTMLElement | null;
  // Scoped to this layout. A single global preference leaked a choice made on
  // a nested layout onto every flat one, which is exactly what the depth
  // default exists to avoid.
  const layersStorageKey = `neo-alchemist-layers:${drupalSettings.neoAlchemist.baseUrl}`;
  localStorage.removeItem('neo-alchemist-layers');
  const layersPanel = container.querySelector('.neo-alchemist--layers') as HTMLElement | null;
  const layersTree = container.querySelector('.neo-alchemist--layers-tree') as HTMLElement | null;
  const layersToggleButton = container.querySelector('.neo-alchemist--layers-toggle') as HTMLElement | null;
  const layersCloseButton = container.querySelector('.neo-alchemist--layers-close') as HTMLElement | null;
  const layersTitle = container.querySelector('.neo-alchemist--layers-title') as HTMLElement | null;
  // Editor side panels other modules add (AlchemistPanelInterface). Each shows
  // where Layers does, and only one of them — or Layers — is open at a time.
  // Their content is the panel's own; see panels.ts for the registry.
  const sidePanels: Record<string, HTMLElement> = {};
  container.querySelectorAll<HTMLElement>('.neo-alchemist--sidepanel').forEach(panel => {
    if (panel.dataset.alchemistSidepanel) {
      sidePanels[panel.dataset.alchemistSidepanel] = panel;
    }
  });
  const sidePanelToggles = container.querySelectorAll<HTMLElement>('.neo-alchemist--sidepanel-toggle');
  let sidePanelOpenId: string | null = null;
  // What the left dock shows: 'layers', or a side panel's id (leftPaneSet()).
  let leftPane = 'layers';
  // Whether Layers was showing when a panel opened the left dock, so closing
  // the panel goes back to how the dock was.
  let leftOpenBeforePanel = false;
  // Below this width an open right dock folds Layers to its rail, unless the
  // rail's own button unfolded it (layersPosition()).
  const LAYERS_RAIL_BELOW = 1400;
  let layersRailUnfolded = false;
  // The Layers row being dragged, and the row marked as where it would land.
  let layersDragUuid: string | null = null;
  let layersDropRow: HTMLElement | null = null;
  // A drag on the canvas (canvasDragInit()): declared up here, with the
  // other state, because the editor's init runs before the code below it.
  let canvasDragUuid: string | null = null;
  let canvasDropTarget: LayersDrop | null = null;
  let canvasDragPoint: { x: number; y: number } | null = null;
  let canvasDragScroll: number | null = null;
  let canvasDragShield: HTMLElement | null = null;
  const canvasDropLine = document.createElement('div');
  canvasDropLine.className = 'neo-alchemist--drop-line';
  canvasDropLine.hidden = true;
  const canvasDropRegion = document.createElement('div');
  canvasDropRegion.className = 'neo-alchemist--drop-region';
  canvasDropRegion.hidden = true;
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

  // A field focus asked for along with an edit, claimed by the edit form when
  // it opens (component-parent.ts). Stale after a while: an edit that never
  // opened must not hijack the next one.
  let fieldFocus: { prop?: string; locate?: NeoAlchemistLocate; at: number } | null = null;
  const FIELD_FOCUS_TTL = 20000;

  /**
   * The editor's public calls, for code outside it (a side panel, a badge).
   */
  Drupal.neoAlchemist.editor = {
    focus(target) {
      if (!target || typeof target.uuid !== 'string' || !structureData[target.uuid]) {
        return false;
      }
      // Off the canvas: aim at whichever frame the author is looking at.
      layerShowOffCanvas(target.uuid);
      if (!target.prop && !target.locate) {
        return true;
      }
      fieldFocus = { prop: target.prop, locate: target.locate, at: Date.now() };
      // The selection opened the inspector. Already showing this component's
      // form, it is told to take the focus now; otherwise the form takes it
      // as it loads (component-parent.ts).
      if (inspectorUuid === target.uuid && dockBody?.querySelector('form')) {
        container.dispatchEvent(new CustomEvent('neoAlchemistFieldFocus'));
      }
      return true;
    },
    takeFieldFocus() {
      const pending = fieldFocus;
      fieldFocus = null;
      if (!pending || Date.now() - pending.at > FIELD_FOCUS_TTL) {
        return null;
      }
      return { prop: pending.prop, locate: pending.locate };
    },
  };

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
  /**
   * The frame being interacted with, which alone shows the selection's rail
   * and knobs (components-parent.css): in Compare they would otherwise repeat
   * on every frame.
   */
  function interactSizeSet(size: 'desktop' | 'tablet' | 'mobile'): void {
    layerInteractSize = size;
    container.setAttribute('data-alchemist-interact', size);
  }
  container.setAttribute('data-alchemist-interact', layerInteractSize);
  // A component just moved, which the canvas keeps on screen until the
  // previews settle. See revealMoved().
  let revealUuid: string | null = null;
  let revealUntil = 0;
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
  // Whether a frame has started or finished is Drupal.neoAlchemist.frames'
  // call (component-parent.ts), the same one its stagger makes.
  //
  // Only frames that have started count. The canvas need not be using every
  // size, and a frame waiting its turn — or never started at all — must not
  // hold back the outlines of the ones that are there. A frame that finishes
  // after the canvas is drawn joins it for its own size: see frameJoin().
  const frames = Drupal.neoAlchemist.frames as NeoAlchemistFrames;
  const iframesLoaded = new Set<string>();
  // The sizes the canvas is drawn for, or about to be: their positions have
  // been asked for, in a round or as a join. ready() builds these only.
  const framesDrawn = new Set<NeoAlchemistFrameSize>();
  // Joined, and their positions not in yet.
  const framesJoining = new Set<NeoAlchemistFrameSize>();
  // Frames whose document is new since they were last drawn — loaded, not
  // updated in place. Only these need the event history played back into them
  // (an opened tab, a toggled accordion): a document updated in place kept
  // that state, and playing a toggle into it again would undo it.
  const framesFresh = new Set<NeoAlchemistFrameSize>();
  // The sizes the current position round waits on.
  let positionRound: NeoAlchemistFrameSize[] = [];
  // Frames the sweep below released rather than the browser. Their report was
  // made while the document was still fetching, so the geometry it produced is
  // provisional and gets asked for again once the document does finish.
  const iframesForced = new Set<string>();
  // When each frame was first seen parsed but unfinished, which is what tells a
  // frame taking its time from one that has stopped making progress.
  const iframeStalledSince: Record<string, number> = {};
  // How long a frame may go on fetching before the editor stops waiting on it.
  // staggerIframeLoads() in component-parent.ts spends the same patience on the
  // same problem.
  const iframeLoadTimeout = 10000;
  const iframeSweepInterval = 1000;

  /**
   * A frame's document state, or '' when there is not one to read.
   *
   * The distinction the frame's 'ready' state cannot draw: `complete` means
   * the load event fired, which waits on every subresource, while
   * `interactive` means the DOM is built and nothing more. Interactive is the
   * point a frame has geometry worth measuring and a listener in it to answer,
   * and a frame that sits there is a frame waiting on something that is not
   * coming.
   */
  function iframeState(size: NeoAlchemistFrameSize, iframe: HTMLIFrameElement): string {
    if (frames.state(size, container) === 'unloaded') {
      return '';
    }
    try {
      if (!iframe.contentWindow || iframe.contentWindow.location.href === 'about:blank') {
        return '';
      }
      return iframe.contentDocument?.readyState || '';
    }
    catch (e) {
      return '';
    }
  }

  /**
   * The frames a round waits on: the active ones that have started and are
   * not stale. A stale frame has not been sent the edit, so it is not about
   * to report; it joins once it has caught up.
   */
  function framesStarted(): NeoAlchemistFrameSize[] {
    return frames.active(container).filter(size => !['unloaded', 'stale'].includes(frames.state(size, container)));
  }

  function requestPositionData(round: NeoAlchemistFrameSize[]): void {
    positionRound = round;
    framesDrawn.clear();
    framesJoining.clear();
    round.forEach(size => {
      framesDrawn.add(size);
      iframes[size]?.contentWindow?.postMessage({
        type: 'getPositionData',
      }, "*");
    });
    // Draw with whatever has answered by the deadline. Declared below, so this
    // reads the binding rather than closing over an initial value.
    armPositionWatchdog();
  }

  /**
   * Spends a frame's one report, whoever decided the frame was done.
   */
  function reportFrame(size: NeoAlchemistFrameSize, iframe: HTMLIFrameElement): void {
    delete iframeStalledSince[size];
    if (iframesLoaded.has(size)) {
      return;
    }

    if (size === 'desktop' && iframe.contentWindow) {
      // Request structure data from desktop iframe.
      iframe.contentWindow.postMessage({
        type: 'getStructureData',
      }, "*");
    }

    // A frame finishing for the first time on a canvas already drawn without
    // it. Opening a round for it would tear the others down and wait on
    // frames that have nothing new to say, so it joins instead. A frame that
    // was drawn and reports again has taken an edit, and opens the round the
    // others are about to report into.
    if (framesDrawn.size && !iframesLoaded.size && !framesDrawn.has(size)) {
      frameJoin(size, iframe);
      return;
    }
    framesJoining.delete(size);
    iframesLoaded.add(size);

    const started = framesStarted();
    if (started.every(startedSize => iframesLoaded.has(startedSize))) {
      roundClose(started);
      return;
    }
    // Two edits close together can split one frame's reports across two
    // rounds: its second lands after the first round closed and opens one the
    // others, already reported, will not come back to. Every drawn frame keeps
    // a live document now, so asking all of them after a moment is safe.
    if (!roundTimeout) {
      roundTimeout = setTimeout(() => {
        roundTimeout = null;
        if (iframesLoaded.size) {
          // A frame still loading is left out; it joins once it reports.
          roundClose(framesStarted().filter(startedSize => frames.state(startedSize, container) === 'ready'));
        }
      }, roundPatience);
    }
  }

  // How long a round gathers reports before asking every frame anyway.
  const roundPatience = 2000;
  let roundTimeout: ReturnType<typeof setTimeout> | null = null;

  function roundClose(round: NeoAlchemistFrameSize[]): void {
    if (roundTimeout) {
      clearTimeout(roundTimeout);
      roundTimeout = null;
    }
    iframesLoaded.clear();
    // All frames reported.
    requestPositionData(round);
  }

  /**
   * Adds a frame to a canvas drawn without it.
   *
   * Only its own positions are asked for, and frameJoined() builds its size
   * alone when they arrive, so the frames already drawn are not touched.
   */
  function frameJoin(size: NeoAlchemistFrameSize, iframe: HTMLIFrameElement): void {
    framesDrawn.add(size);
    framesJoining.add(size);
    iframe.contentWindow?.postMessage({
      type: 'getPositionData',
    }, "*");
  }

  function iframeLoaded(size: NeoAlchemistFrameSize, iframe: HTMLIFrameElement): void {
    if (!iframe.contentWindow || frames.state(size, container) !== 'ready' || !frames.active(container).includes(size)) {
      return;
    }
    // A frame the sweep had given up on has now finished for real. Its
    // report is already spent and the handshake will not come round again, so
    // ask every drawn frame for geometry directly: that is what the report
    // would have done, and it corrects positions that were measured mid-load.
    framesFresh.add(size);
    if (iframesForced.delete(size)) {
      requestPositionData(sizes.filter(drawnSize => framesDrawn.has(drawnSize)));
      return;
    }
    reportFrame(size, iframe);
  }

  /**
   * Releases a frame that has stopped making progress.
   *
   * A frame that never finishes must not hold the editor hostage. A preview
   * pulls whatever the page it renders pulls: web fonts from a third party, a
   * hero image, a video, an embed. Any one of them stalling keeps the load
   * event from firing, and the count in reportFrame() then never completes,
   * so getPositionData is never sent. That left the editor with no outlines, no
   * hit targets and nothing to click, with nothing to recover it however long
   * you waited. Three previews all pulling the same stalled web font is how one
   * slow third party took the whole page down.
   *
   * Releasing gets the editor working on the geometry there is, and
   * iframeLoaded() corrects it if the document ever does finish.
   *
   * A sweep rather than a timer armed per load, because a preview can reload
   * itself (fallbackReload() in component-child.ts, when an edit cannot be
   * applied in place), which leaves the parent no navigation to arm against. Reading the state is cheap
   * and every round is covered by the same few lines.
   */
  function sweepStalledIframes(): void {
    const active = frames.active(container);
    (Object.entries(iframes) as Array<[NeoAlchemistFrameSize, HTMLIFrameElement]>).forEach(([size, iframe]) => {
      if (!active.includes(size)) {
        return;
      }
      const state = iframeState(size, iframe);
      if (state === '' || state === 'loading') {
        // Early rather than stalled: a deferred frame waiting its turn in
        // staggerIframeLoads(), or a document still arriving. A frame that has
        // begun a new document is also a frame whose last release is spent.
        delete iframeStalledSince[size];
        iframesForced.delete(size);
        return;
      }
      if (state === 'complete' || iframesLoaded.has(size) || iframesForced.has(size)) {
        // Finished, already reported, or already released once.
        delete iframeStalledSince[size];
        return;
      }
      const since = iframeStalledSince[size];
      if (since === undefined) {
        iframeStalledSince[size] = Date.now();
        return;
      }
      if (Date.now() - since < iframeLoadTimeout) {
        return;
      }
      delete iframeStalledSince[size];
      iframesForced.add(size);
      framesFresh.add(size);
      reportFrame(size, iframe);
    });
  }

  (Object.entries(iframes) as Array<[NeoAlchemistFrameSize, HTMLIFrameElement]>).forEach(([size, iframe]) => {
    iframe.addEventListener('load', () => iframeLoaded(size, iframe));
    // Catch up on a frame that finished before this script ran. Its load event
    // is gone and is not coming back.
    iframeLoaded(size, iframe);
  });
  setInterval(sweepStalledIframes, iframeSweepInterval);

  // A zoom moves nothing on the chrome layer — the transform carries it — but
  // its counter-scale has to follow the zoom as it eases.
  container.addEventListener('alchemistManageScale', invTrack);
  container.addEventListener('alchemistManageScaleEnd', () => {
    invSync();
    railsPlace();
    elementsPosition();
    layerShow(layerUuid, true, true);
  });

  // The canvas changed which sizes it shows (component-parent.ts). Every frame
  // may have moved, and a frame already loaded that it uses again has no load
  // event coming to bring it into the outlines, so it joins from here.
  container.addEventListener('alchemistManageView', () => {
    const visible = frames.visible(container);
    if (!visible.includes(layerInteractSize)) {
      interactSizeSet(visible[0] ?? 'desktop');
    }
    frames.active(container).forEach(size => {
      const iframe = iframes[size];
      if (iframe && !framesDrawn.has(size)) {
        iframeLoaded(size, iframe);
      }
    });
    elementsPosition();
    if (layerUuid) {
      layerShow(layerUuid, true, true, false);
    }
    else {
      seamsShow(null);
    }
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
  //
  // Asking is not hearing back, so this half carries the same watchdog as the
  // loads above. A preview whose own scripts are still blocked has nothing
  // listening in it to answer, and one silent frame used to mean ready() never
  // ran at all: no outlines, no hit targets, nothing to click, for the two
  // frames that did answer as much as for the one that did not. What has
  // answered by the deadline is what the canvas is drawn from, and a frame that
  // answers later is placed into the canvas already standing.
  const iframesFinished = new Set<string>();
  let positionWatchdog: ReturnType<typeof setTimeout> | null = null;

  function finishPositions(): void {
    if (positionWatchdog) {
      clearTimeout(positionWatchdog);
      positionWatchdog = null;
    }
    iframesFinished.clear();
    ready();
  }

  function armPositionWatchdog(): void {
    if (positionWatchdog) {
      clearTimeout(positionWatchdog);
    }
    positionWatchdog = setTimeout(() => {
      positionWatchdog = null;
      if (!iframesFinished.size) {
        // Not one frame answered, so there are no rects to place anything by
        // and ready() would do nothing but clear the canvas. Leave what is
        // there and let a late answer above bring it up to date.
        return;
      }
      finishPositions();
    }, iframeLoadTimeout);
  }

  const onChild:any = {
    // A frame took an edit in place (refreshPreview() in component-child.ts):
    // the layout equivalent of finishing a load, minus the new document. It
    // reports as a loaded frame would, which asks it for structure (desktop)
    // and positions again and rebuilds the outlines from the answers. The
    // edit form's canvas sends the same message from its own frames, which
    // can sit in a modal over this one, so only this canvas's id counts.
    previewRefreshed: function (data: any) {
      const size = data.size as NeoAlchemistFrameSize;
      const iframe = iframes[size];
      if (data.id !== container.id || !iframe || frames.state(size, container) !== 'ready' || !frames.active(container).includes(size)) {
        return;
      }
      reportFrame(size, iframe);
    },
    structureData: function (data: any) {
      structureData = data.data;
    },
    regionAdd: function (data: any) {
      // The empty-region placeholder was clicked in a preview iframe. Open the
      // library scoped to that region (uuid--shape) regardless of focus state.
      const baseUrl = libraryBaseUrl();
      if (data.uuid && baseUrl !== null) {
        actions.library(data.uuid, baseUrl);
      }
    },
    positionData: function (data: any) {
      const size = data.size as 'desktop' | 'tablet' | 'mobile';
      positionData[size] = data.data;
      if (framesJoining.delete(size)) {
        frameJoined(size);
        return;
      }
      if (!positionWatchdog && !iframesFinished.size) {
        // A frame answering after its round was drawn without it. Place what it
        // has just reported rather than opening a fresh round, which would tear
        // the canvas down and build it again for one late viewport.
        elementsPosition();
        return;
      }
      iframesFinished.add(size);
      if (positionRound.every(roundSize => iframesFinished.has(roundSize))) {
        finishPositions();
      }
    },
    positionUpdateData: function (data: any) {
      const size = data.size as 'desktop' | 'tablet' | 'mobile';
      // Unasked, so it can come from a frame the canvas is not drawing, or one
      // still showing a render from before the last edit. Taking it would
      // place live outlines by a document that is out of date; the frame is
      // asked again when it joins.
      if (!framesDrawn.has(size) || frames.state(size, container) === 'stale') {
        return;
      }
      positionData[size] = data.data;
      elementsPosition();
      // Redraw the layer where it now is, but do not chase it with the
      // viewport: this fires whenever a preview reports fresh positions, which
      // after a refresh happens twice — once before the rebuilt elements have
      // real positions, sending the canvas to the top, and again once they do,
      // bringing it back. The author never asked to move.
      layerShow(layerUuid, true, true, false);
      revealMoved();
    },
    onEvent: function (data: any) {
      const eventType = data.eventType as string;
      const eventUuid = data.uuid as string;
      const eventData = getEventData(eventUuid);
      // The tree this event belongs to is gone — the previews change under a
      // save, and the events they report can name a node the parent has not
      // heard of yet. getEventData() returns null for those, and reading
      // .type/.action/.group off it threw, taking the whole handler with it:
      // the canvas stopped responding until fresh structure data rebuilt it,
      // which is the moment where nothing appears to be selected. Nothing here
      // is meaningful without the definition, so let it go by.
      if (!eventData) {
        return;
      }

      sizes.forEach(size => {
        // A frame not drawn yet catches up from the event history on joining.
        if (size !== data.size && framesDrawn.has(size)) {
          const iframe = iframes[size];
          if (!iframe?.contentWindow) {
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

    // The inspector's close: deselecting, as Escape at the top level does.
    // "‹ Parent" in the inspector's band: the step up the breadcrumb takes.
    dock?.querySelector<HTMLElement>('.neo-alchemist--dock-parent')?.addEventListener('click', (e) => {
      e.preventDefault();
      const button = e.currentTarget as HTMLElement;
      if (button.getAttribute('aria-disabled') === 'true') {
        return;
      }
      layerShowOffCanvas(button.dataset.target || null);
    });
    dock?.querySelector('.neo-alchemist--dock-close')?.addEventListener('click', (e) => {
      e.preventDefault();
      layerShow(null);
    });

    // Action button handling
    actionButtons.forEach(actionButton => {
      actionButton.addEventListener('click', (e) => {
        e.preventDefault();
        const actionKey = actionButton.dataset.action as keyof Actions;
        if (actionKey) {
          actionExecute(actionKey, actionButton);
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
    layersDragInit();
    canvasDragInit();
    layersToggleButton?.addEventListener('click', (e) => {
      e.preventDefault();
      // Showing a panel, the toggle brings Layers forward rather than hiding
      // the dock.
      layersPanelToggle(!layersPanelIsOpen() || leftPane !== 'layers');
    });
    layersCloseButton?.addEventListener('click', (e) => {
      e.preventDefault();
      // Closing a panel closes the dock, without changing what is remembered
      // about Layers.
      if (leftPane !== 'layers') {
        leftOpenBeforePanel = false;
        sidePanelToggle(leftPane, false);
        return;
      }
      layersPanelToggle(false);
    });
    if (hasSideForm) {
      // No panel in the edit view, so nothing for the toggle to act on.
      layersToggleButton?.style.setProperty('display', 'none');
    }

    // Editor side panels.
    sidePanelToggles.forEach(toggle => {
      if (hasSideForm) {
        toggle.style.setProperty('display', 'none');
        return;
      }
      toggle.addEventListener('click', (e) => {
        e.preventDefault();
        // A button heading a panel's badge is the panel's, not the badge's:
        // the badge's own handler (panels.ts) would toggle the panel again.
        e.stopPropagation();
        const id = toggle.dataset.alchemistSidepanel;
        if (id) {
          sidePanelToggle(id, sidePanelOpenId !== id);
        }
      });
    });
    layersPanel?.querySelector('.neo-alchemist--layers-rail')?.addEventListener('click', (e) => {
      e.preventDefault();
      layersRailUnfolded = true;
      layersPosition();
    });
    window.addEventListener('resize', () => {
      layersPosition();
      headerFit();
    });
    headerFit();
    // A write re-renders the status line and the save group (Save and ⋯
    // come and go with the draft), which changes what the band needs.
    const bandObserver = new MutationObserver(() => headerFit());
    container.querySelectorAll('.neo-alchemist-manage--top-status, .neo-alchemist-manage--top-save').forEach(part => {
      bandObserver.observe(part, { childList: true });
    });

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
      if (moreMenusClose(true)) {
        return;
      }
      if (openCrumbMenu) {
        crumbMenuClose();
        return;
      }
      if (document.body.classList.contains('has-neo-modal')) {
        return;
      }
      // Escape in the inspector is the form's (a CKEditor balloon, a field's
      // own undo of a highlight), not a way out of the selection.
      if (dock && e.target instanceof Node && dock.contains(e.target)) {
        return;
      }
      layerBack();
    });
    document.addEventListener('click', (e) => {
      if (openCrumbMenu && e.target instanceof Node && !openCrumbMenu.parentElement?.contains(e.target)) {
        crumbMenuClose();
      }
    });
    // The header's ⋯ menu. Delegated from the document: a write re-renders
    // the save group it lives in. Its toggle flips it; any other click — one
    // of its items, whose dialog is opening, or anywhere else — closes it.
    // Captured: an item is a use-ajax link, whose handler stops the click
    // before it would bubble here, leaving the menu open behind its dialog.
    document.addEventListener('click', (e) => {
      const toggle = e.target instanceof Element ? e.target.closest<HTMLElement>('.neo-alchemist--more-toggle') : null;
      const root = toggle?.closest<HTMLElement>('.neo-alchemist--more');
      const menu = root?.querySelector<HTMLElement>('.neo-alchemist--more-menu');
      if (!toggle || !menu) {
        moreMenusClose();
        return;
      }
      e.preventDefault();
      const open = menu.hidden;
      moreMenusClose();
      if (open) {
        menu.hidden = false;
        toggle.classList.add('is-active');
        toggle.setAttribute('aria-expanded', 'true');
        menu.querySelector<HTMLElement>('a, button')?.focus();
      }
    }, true);

    // Undo and Redo. Delegated: a write re-renders the buttons. Inside a text
    // field the keys stay the field's own text undo; so they do while a
    // dialog is open, whose form is the thing being typed into.
    document.addEventListener('click', (e) => {
      const button = e.target instanceof Element ? e.target.closest<HTMLElement>('.neo-alchemist--history') : null;
      if (button?.dataset.history) {
        e.preventDefault();
        historyRun(button.dataset.history);
      }
    });
    document.addEventListener('keydown', (e) => {
      if (!(e.metaKey || e.ctrlKey) || e.altKey) {
        return;
      }
      const key = e.key.toLowerCase();
      const direction = key === 'z' ? (e.shiftKey ? 'redo' : 'undo') : (key === 'y' && e.ctrlKey && !e.shiftKey ? 'redo' : null);
      if (!direction || isTextEntry(e.target) || document.body.classList.contains('has-neo-modal')) {
        return;
      }
      e.preventDefault();
      historyRun(direction);
    });

    // The selection's shortcuts, read off the rail's buttons (their op's
    // `keys`), so the inventory names them and nothing here does. Typing
    // keeps them, as it keeps ⌘Z; so does a key a Layers row already took.
    document.addEventListener('keydown', (e) => {
      if (!layerUuid || e.defaultPrevented || isTextEntry(e.target) || document.body.classList.contains('has-neo-modal')) {
        return;
      }
      const combo = [
        e.metaKey || e.ctrlKey ? 'Mod' : '',
        e.altKey ? 'Alt' : '',
        e.shiftKey ? 'Shift' : '',
        e.key.length === 1 ? e.key.toLowerCase() : e.key,
      ].filter(Boolean).join('+');
      const button = Array.from(container.querySelectorAll<HTMLElement>('.neo-alchemist--rail [data-op-keys]'))
        .find(candidate => (candidate.dataset.opKeys ?? '').split(' ').includes(combo));
      if (!button?.dataset.op) {
        return;
      }
      e.preventDefault();
      operationExecute(button.dataset.op);
    });

    // The inspector's ⋯: an item runs its op (opButtonsBind()) and closes the
    // menu; Esc or a press anywhere else closes it. Capture, as for the band's
    // ⋯: a use-ajax link stops the click before it bubbles.
    dockMoreToggle?.addEventListener('click', (e) => {
      e.preventDefault();
      dockMenuSet(!!dockMenu?.hidden);
    });
    dockMenu?.querySelectorAll<HTMLElement>('[data-op-keys]').forEach(item => {
      item.dataset.opKeysLabel = opKeysLabel(item.dataset.opKeys ?? '');
    });
    document.addEventListener('click', (e) => {
      if (!dockMenu || dockMenu.hidden) {
        return;
      }
      const target = e.target instanceof Element ? e.target : null;
      if (target?.closest('.neo-alchemist--dock-menu [data-op]') || !dockMore?.contains(target)) {
        dockMenuSet(false);
      }
    }, true);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && dockMenu && !dockMenu.hidden) {
        dockMenuSet(false);
        dockMoreToggle?.focus();
      }
    });

    // Deferred: the dialog hands focus back to its opener as it closes, and
    // that focus is what reopens the opener's tooltip.
    document.addEventListener('dialog:afterclose', () => setTimeout(opTooltipsHide, 50));

    // A pan or wheel is the author choosing where to look, which ends any
    // follow-the-move still pending.
    wrapper.addEventListener('wheel', () => { revealUuid = null; }, { passive: true });
    wrapper.addEventListener('mousedown', () => { revealUuid = null; });

    // Watch for component focus event
    const focusCallback = (event: CustomEvent<any>) => {
      const detail = event.detail as { uuid: string; reveal?: boolean };
      if (detail.reveal) {
        // Undo and redo can land anywhere on the page: take the canvas there,
        // the inspected component included.
        revealUuid = detail.uuid;
        revealUntil = Date.now() + 3000;
      }
      // Sent after a component's form saved: the inspector's Apply, or a
      // component added from the library, which the selection moves to.
      if (detail.uuid === inspectorUuid) {
        inspectorApplied(detail.uuid);
        return;
      }
      if (inspectorDirty) {
        return;
      }
      layerUuid = detail.uuid;
    }
    container.addEventListener('alchemistManageComponentFocus', focusCallback as EventListener);
    container.addEventListener('alchemistManageApplyRefused', ((event: CustomEvent<{ uuid: string }>) => {
      if (event.detail.uuid === inspectorUuid) {
        inspectorApplyRefused();
      }
    }) as EventListener);

    inspectorInit();
    layersPosition();
  }

  /**
   * Bind the operation buttons, including any newly built overlay ops.
   *
   * Safe to call repeatedly: already-bound buttons are skipped, and overlay
   * clones start unbound because the prototype is never marked.
   */
  function opButtonsBind(): void {
    // The whole editor: the outline's ops are on the canvas, the inspector's
    // in its header.
    opButtons = container.querySelectorAll('.neo-alchemist--op') as NodeListOf<HTMLElement>;
    opButtons.forEach(opButton => {
      if (opButton.dataset.opBound) {
        return;
      }
      opButton.dataset.opBound = 'true';
      opButton.addEventListener('click', (e) => {
        e.preventDefault();
        const opKey = opButton.dataset.op;
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
    chrome.appendChild(shade);
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
    // Fades only. Geometry changes at once: an outline that eased to a new
    // rect visibly chased the component it marks.
    overlay.style.transition = `opacity ${transitionSpeed}ms ease-in-out`;
    overlayLabel.style.opacity = '0';
    overlayActions.forEach(action => action.style.opacity = '0');
    chrome.appendChild(overlay);
    // The op buttons carry #tooltip, which is inert markup until a behaviour
    // turns it into a tippy instance. This outline is a clone of a prototype
    // that initialize() pulled out of the DOM before behaviours ever ran, so
    // nothing has processed it and nothing else will — every copy of the +/move
    // cluster would sit there permanently untooltipped. A rail button's
    // tooltip names its shortcut too, as this keyboard writes it.
    Drupal.attachBehaviors(overlay, drupalSettings);
    overlay.querySelectorAll<HTMLElement>('[data-op-keys]').forEach(button => {
      const tip = (button as HTMLElement & { _tippy?: { props: { content: unknown }; setContent: (content: string) => void } })._tippy;
      tip?.setContent(`${String(tip.props.content)} (${opKeysLabel(button.dataset.opKeys ?? '')})`);
    });
    return overlay;
  }

  /**
   * We have all the elements and positions, we are ready to go.
   *
   * This will be called once the drawn frames have sent position data. It is
   * called again after every edit, once the frames have taken it. A frame that joins
   * later is built by frameJoined() instead.
   */
  let readyCount = 0;

  function ready(): void {
    invSync();
    // Reset overlay state
    overlayState = {};
    // Clear existing elements. Outlines are rebuilt per uuid below, so stale
    // ones for nodes that no longer exist go with them.
    elementsRemove('.neo-alchemist--element, .neo-alchemist--event, .neo-alchemist--overlay');
    Object.keys(structureElements).forEach(key => delete structureElements[key]);
    Object.keys(overlayElements).forEach(key => delete overlayElements[key]);
    Object.keys(overlayTimeouts).forEach(key => clearTimeout(overlayTimeouts[key] || undefined));
    overlayTimeouts = {};
    ghostUuids = [];

    // Build structure elements. A size still joining is built when its
    // positions arrive.
    sizes.forEach(size => {
      if (framesDrawn.has(size) && !framesJoining.has(size)) {
        buildSize(size);
      }
    });

    // Pick up the ops on the outlines just built.
    opButtonsBind();

    elementsPosition();
    elementsToggle();
    // Hide the root Add in hybrid mode until a region is focused. When a
    // layer is restored below, layerShow() re-runs these.
    actionsToggle();
    actionLabelsSet(layerUuid);
    // Seams are positioned from the rects that were just replaced. With
    // nothing focused this draws the page's own insertion points; layerShow()
    // redraws them for the restored layer below.
    seamPreviewOnly = false;
    seamsShow(layerUuid);
    layersBuild();
    // The trail's menus list what the tree holds, so they follow every write.
    titleSet(layerUuid);
    // An explicit choice wins, but only on the layout it was made on;
    // otherwise whether this tree has regions decides. Once: a layout write
    // re-runs this, and must not undo what was chosen since.
    if (!readyCount) {
      const layersChoice = localStorage.getItem(layersStorageKey);
      layersPanelToggle(layersChoice ? layersChoice === 'open' : layersHasRegions(), true);
    }
    // Every ready() after the first follows a layout write (the previews
    // refresh on its AJAX response), so the draft moved on under any side panel.
    readyCount++;
    if (readyCount > 1) {
      Drupal.neoAlchemist?.panels?.layoutChanged();
    }

    if (layerUuid) {
      eventsReplay(sizes.filter(size => framesDrawn.has(size) && framesFresh.has(size)));
      layerShow(layerUuid, true, true, false);
      revealMoved();
    }
    framesDrawn.forEach(size => framesFresh.delete(size));
  }

  /**
   * Draws a frame that joined the canvas, leaving the others as they are.
   */
  function frameJoined(size: NeoAlchemistFrameSize): void {
    if (!readyCount) {
      // Nothing is drawn yet; ready() takes this size along with the rest.
      return;
    }
    buildSize(size);
    opButtonsBind();
    elementsPosition();
    const fresh = framesFresh.delete(size);
    if (layerUuid) {
      if (fresh) {
        eventsReplay([size]);
      }
      // Forced, so the new size's outlines and shades come up beside the
      // others, which are redrawn where they already are.
      layerShow(layerUuid, true, true, false);
      return;
    }
    elementsToggle();
    seamsShow(null);
  }

  /**
   * Removes canvas elements along with what their behaviours hold.
   */
  function elementsRemove(selector: string): void {
    container.querySelectorAll<HTMLElement>(selector).forEach(element => {
      // Behaviours attached to an outline own state that outlives the node —
      // a tooltip is a tippy instance held in a module-level list, not markup.
      // Dropping the element without this runs ready() as a slow leak: every
      // save would strand another set, and they are walked on every attach.
      Drupal.detachBehaviors(element, drupalSettings, 'unload');
      element.remove();
    });
  }

  /**
   * Build the hit targets, event triggers and outlines for one size.
   *
   * Whatever the size had goes first, so a frame drawn twice — joining while
   * a round was still in flight — never leaves two sets behind.
   */
  function buildSize(size: NeoAlchemistFrameSize): void {
    elementsRemove(`.neo-alchemist--element[data-size="${size}"], .neo-alchemist--event[data-size="${size}"], .neo-alchemist--overlay[data-size="${size}"]`);
    const elementBase = container.querySelector('.neo-alchemist--element-base') as HTMLElement;
    const eventBase = container.querySelector('.neo-alchemist--event-base') as HTMLElement;
    Object.entries(structureData).forEach(([uuid, data]) => {
      structureElements[uuid] = structureElements[uuid] || {} as Record<'desktop' | 'tablet' | 'mobile', HTMLElement>;
      overlayElements[uuid] = overlayElements[uuid] || {} as Record<'desktop' | 'tablet' | 'mobile', HTMLElement>;
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
        interactSizeSet(size);
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
      chrome.appendChild(element);
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
            interactSizeSet(size);
            clearTimeout(passthroughTimeout || undefined);
            container.classList.add('neo-alchemist--passthrough');
          });
          chrome.appendChild(eventTrigger);
          structureElements[eventId] = structureElements[eventId] || {} as Record<'desktop' | 'tablet' | 'mobile', HTMLElement>;
          structureElements[eventId][size] = eventTrigger;
        });
      }
    });
  }

  /**
   * Plays the remembered events (an opened tab, a toggled accordion) into
   * frames, so they show the state the others do.
   */
  function eventsReplay(targets: NeoAlchemistFrameSize[]): void {
    Object.values(eventHistory).forEach(eventInfo => {
      eventInfo.events.forEach(eventType => {
        targets.forEach(size => {
          iframes[size]?.contentWindow?.postMessage({
            type: 'doEvent',
            size: size,
            uuid: eventInfo.uuid,
            eventType: eventType,
          });
        });
      });
    });
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
    railsPlace();
    const wrapperRect = wrapper.getBoundingClientRect();
    // Clamp to the canvas's own top edge, which the breadcrumb bar sits above.
    const clampTop = wrapperRect.top;
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
        // Read by the chip's transform after its counter-scale, so these are
        // screen pixels (components-parent.css).
        label.style.setProperty('--clamp-x', `${shiftX}px`);
        label.style.setProperty('--clamp-y', `${shiftY}px`);
      });
    });
  }

  function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
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
    // The chip is dressed by the outline's state and node type
    // (components-parent.css): white beside a hover, a black tab joined to a
    // selection, a region's a shade greyer.
    let html = `<span class="neo-alchemist--chip">${escapeHtml(String(data.data.label ?? ''))}</span>`;
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
    //
    // A selected component's outline lets the pointer through too: selecting
    // is editing, so a click inside it belongs to the preview, which answers
    // with the field that element came from (component-child.ts).
    if (data.type === 'region' && data.children.length) {
      overlay.style.pointerEvents = 'auto';
    }
    setTimeout(() => {
      shadeShowLevel(uuid, size);
      railsPlace();
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
        overlayLabel.style.removeProperty('--clamp-x');
        overlayLabel.style.removeProperty('--clamp-y');
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
   * hunting for the node on the canvas again. With nothing selected the trail
   * is the page alone, whose menu lists the top level.
   *
   * One line: a long trail scrolls sideways, kept at its end so the current
   * crumb is the one in view.
   */
  function titleSet(uuid: string | null): void {
    crumbMenuClose();
    if (!crumbTrail) {
      return;
    }
    crumbTrail.innerHTML = '';
    const current = uuid && structureData[uuid] ? uuid : null;
    const trail: Array<string | null> = current ? [null, ...structureData[current].parents, current] : [null];
    trail.forEach((crumbUuid, index) => {
      if (index) {
        const sep = document.createElement('div');
        sep.className = 'flex-none opacity-50';
        sep.textContent = '›';
        sep.setAttribute('aria-hidden', 'true');
        crumbTrail.appendChild(sep);
      }
      crumbTrail.appendChild(crumbBuild(crumbUuid, crumbUuid === current));
    });
    crumbTrail.scrollLeft = crumbTrail.scrollWidth;
    dockBandSet(current);
  }

  /**
   * Says where the selection sits in the inspector's band.
   *
   * "‹ Parent" selects the parent the breadcrumb shows — a component, a
   * region, or the page, which clears the selection — and the position counts
   * the selection among that parent's children: "2 of 3 in List S5", "1 of 2
   * in Sidebar", "2 of 4 on Careers". An inherited parent in a hybrid layout
   * is shown but not offered, as in the breadcrumb.
   */
  function dockBandSet(uuid: string | null): void {
    const parentButton = container.querySelector<HTMLElement>('.neo-alchemist--dock-parent');
    const position = container.querySelector<HTMLElement>('.neo-alchemist--dock-position');
    if (!parentButton || !position) {
      return;
    }
    const data = uuid ? structureData[uuid] : null;
    parentButton.hidden = !data;
    position.hidden = !data;
    if (!uuid || !data) {
      return;
    }
    const parent = data.parents.length ? data.parents[data.parents.length - 1] : null;
    const parentLabel = parent ? String(structureData[parent]?.data?.label ?? '') : Drupal.t('Page');
    const label = parentButton.querySelector<HTMLElement>('.neo-alchemist--dock-parent-label');
    if (label) {
      label.textContent = parentLabel;
    }
    parentButton.dataset.target = parent ?? '';
    parentButton.title = parent ? Drupal.t('Select @label', { '@label': parentLabel }) : Drupal.t('Back to the whole page');
    if (parent && hybrid && isInheritedComponent(parent)) {
      parentButton.setAttribute('aria-disabled', 'true');
    }
    else {
      parentButton.removeAttribute('aria-disabled');
    }
    const siblings = parent
      ? (structureData[parent]?.children || [])
      : Object.keys(structureData).filter(key => structureData[key].parents.length === 0);
    const args = {
      '@index': String(siblings.indexOf(uuid) + 1),
      '@count': String(siblings.length),
    };
    position.textContent = parent
      ? Drupal.t('@index of @count in @parent', { ...args, '@parent': parentLabel })
      : (drupalSettings.neoAlchemist?.pageLabel
        ? Drupal.t('@index of @count on @page', { ...args, '@page': drupalSettings.neoAlchemist.pageLabel })
        : Drupal.t('@index of @count on the page', args));
  }

  /**
   * Empties the band's name and chips while the inspector has no form for
   * them: a form on its way, or none to load.
   */
  function dockBandClear(): void {
    container.querySelector('.neo-alchemist--dock-identity')?.replaceChildren();
    container.querySelector('.neo-alchemist--dock-chips')?.replaceChildren();
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
    // On the band's dark second row: ancestors are quiet chips, the current
    // one the primary pill, so the selection reads at the end of the trail.
    crumb.className = `neo-alchemist--crumb flex flex-none items-center whitespace-nowrap px-1.5 rounded-full ${isCurrent ? 'bg-primary-500 text-primary-500-content' : 'text-base-800-content/80 hover:bg-base-800-content/10 hover:text-base-800-content'}`;
    if (isCurrent) {
      crumb.setAttribute('aria-current', 'location');
    }

    const link = document.createElement((isCurrent || inert) ? 'div' : 'a');
    link.className = `neo-alchemist--crumb-link flex items-center h-6 px-1 gap-1.5 text-inherit ${isCurrent ? 'font-semibold' : 'font-normal'}`;
    if (link instanceof HTMLAnchorElement) {
      link.href = '#';
      link.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        layerShowOffCanvas(crumbUuid);
      });
    }
    if (data) {
      // No type marker in the trail. A crumb answers "where am I", and the
      // kind of each ancestor is not part of that answer.
      // A long name stops at 12rem; the whole of it is in the title.
      const text = document.createElement('span');
      text.className = 'truncate max-w-48';
      text.textContent = String(data.data.label ?? '');
      text.setAttribute('title', text.textContent);
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
      // Named only when it is the whole trail: further along, the icon says it.
      if (isCurrent) {
        const text = document.createElement('span');
        text.textContent = Drupal.t('Page');
        link.appendChild(text);
      }
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
    badge.className = `badge flex-none rounded-sm bg-${kind}-500 text-${kind}-500-content`;
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
    toggle.className = 'neo-alchemist--crumb-toggle inline-flex items-center justify-center w-4.5 h-4.5 rounded-full text-xs leading-none cursor-pointer transition hover:bg-black/15';
    toggle.setAttribute('aria-haspopup', 'true');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('title', Drupal.t('Go to a child'));
    toggle.textContent = '▾';

    const menu = document.createElement('div');
    // Fixed, placed under the toggle on open: the trail scrolls sideways, and a
    // scroller clips anything absolute inside it.
    menu.className = 'neo-alchemist--crumb-menu fixed z-50 min-w-48 max-h-72 overflow-y-auto p-1 rounded-md shadow-lg border bg-base-0 text-base-0-content';
    menu.hidden = true;
    menu.setAttribute('role', 'menu');

    children.forEach(childUuid => {
      const child = structureData[childUuid];
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'neo-alchemist--crumb-menu-item flex items-center gap-1.5 w-full px-2 py-1 rounded-sm text-sm text-left cursor-pointer hover:bg-base-100 focus-visible:bg-base-100';
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
        const rect = toggle.getBoundingClientRect();
        menu.style.top = `${rect.bottom + 6}px`;
        menu.style.left = `${rect.left}px`;
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

  /**
   * Close any open ⋯ menu in the header.
   *
   * @param refocus
   *   Return focus to the menu's toggle (Escape), so the keyboard is not left
   *   on an item that just disappeared.
   *
   * @return
   *   Whether a menu was open.
   */
  function moreMenusClose(refocus: boolean = false): boolean {
    let closed = false;
    container.querySelectorAll<HTMLElement>('.neo-alchemist--more-menu:not([hidden])').forEach(menu => {
      menu.hidden = true;
      const toggle = menu.closest('.neo-alchemist--more')?.querySelector<HTMLElement>('.neo-alchemist--more-toggle');
      toggle?.classList.remove('is-active');
      toggle?.setAttribute('aria-expanded', 'false');
      if (refocus) {
        toggle?.focus();
      }
      closed = true;
    });
    return closed;
  }

  /**
   * Take down any op tooltip that is up.
   *
   * A tooltip opens on hover or focus and closes on leave or blur, and an op
   * can lose its reason to be pointed at without either: the selection moves
   * on and hides it, or the dialog it opened closes and hands focus back to
   * it. Both left the tooltip on screen with nothing under the pointer.
   */
  function opTooltipsHide(): void {
    opButtons?.forEach(button => (button as any)._tippy?.hide());
  }

  /**
   * The inspector: the selected component's form, docked on the right.
   *
   * Selecting a component opens it here; there is no separate edit step. The
   * form previews into this canvas as it is typed (the frames re-render with
   * `inspect`, see InstanceComponentForm::ajaxRefresh()), but nothing reaches
   * the shared draft until Apply. Changes not yet applied are the one thing
   * the durable draft does not hold, so while there are any, moving the
   * selection, a structural op on the component, or leaving the page asks
   * first — inline, in the dock, never in a dialog.
   */
  // The component whose form the dock holds (or is loading), else null.
  let inspectorUuid: string | null = null;
  // Changes typed into that form and not yet applied.
  let inspectorDirty = false;
  // What to do once the prompt is answered with Apply or Discard.
  let inspectorPending: (() => void) | null = null;
  // Set while an Apply is in flight, to run once it has landed.
  let inspectorAfterApply: (() => void) | null = null;
  // Whether the form's Apply was pressed and has not landed yet. Undo, redo
  // and a move send the same "saved" focus for the inspected component, and
  // only an Apply closes the inspector.
  let inspectorApplying = false;
  // Increments per form load, so a slow response for a component the
  // selection has since left is dropped rather than shown.
  let inspectorLoadToken = 0;

  function inspectorInit(): void {
    if (!dock || !dockBody) {
      return;
    }
    // What any render of this canvas is for: the component the inspector has
    // open, marked so its fields answer clicks (component-ajax.ts asks).
    Drupal.neoAlchemist = Drupal.neoAlchemist || {};
    Drupal.neoAlchemist.inspecting = (): Record<string, string> => (inspectorUuid && structureData[inspectorUuid]?.type === 'component')
      ? { inspect: inspectorUuid }
      : {};
    // Anything typed, picked or reordered in the form is unapplied until
    // Apply. component-ajax-form.ts also reports edits it sends for preview
    // that raise no DOM event of their own (CKEditor, an autocomplete pick).
    const markDirty = (e: Event) => {
      const target = e.target as HTMLElement | null;
      // The tab the form reports to the server is bookkeeping, not an edit.
      if (target?.matches?.('[data-neo-alchemist-active-tab]')) {
        return;
      }
      if (inspectorUuid && dockBody.querySelector('form')) {
        inspectorDirty = true;
        // Typing again: an Apply that came back with errors is over. Not on
        // `change`: pressing Apply blurs the field just edited, and its
        // `change` lands after the press.
        if (e.type === 'input') {
          inspectorApplying = false;
        }
      }
    };
    dockBody.addEventListener('input', markDirty);
    // On mousedown, as Drupal sends a submit's AJAX (a key press on the
    // button triggers one too), and in the capture phase so it is set before
    // that runs. Not on click: the AJAX disables the button first, so there
    // is no click. The prompt's Apply needs none of it: it runs what it was
    // waiting for.
    dockBody.addEventListener('mousedown', (e) => {
      if ((e.target as HTMLElement).closest('.neo-alchemist--form-footer [data-drupal-selector="edit-submit"]')) {
        inspectorApplying = true;
      }
    }, true);
    dockBody.addEventListener('change', markDirty);
    dockBody.addEventListener('neoAlchemistFormEdited', markDirty);

    // Cancel: throw the unapplied changes away. With none, it closes the
    // inspector instead, as there is nothing else for it to undo.
    dockBody.addEventListener('click', (e) => {
      const cancel = (e.target as HTMLElement).closest('.neo-alchemist--inspector-cancel');
      if (!cancel) {
        return;
      }
      e.preventDefault();
      if (!inspectorDirty) {
        layerShow(null);
        return;
      }
      inspectorDiscard();
      if (inspectorUuid) {
        inspectorLoad(inspectorUuid);
      }
    });

    // Unapplied changes are not in the draft, so they would be lost.
    window.addEventListener('beforeunload', (e) => {
      if (inspectorDirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    });
  }

  /**
   * Follow the selection: open the dock on it, or close the dock.
   */
  function inspectorSync(uuid: string | null): void {
    if (!dock || !dockBody) {
      return;
    }
    if (!uuid || !structureData[uuid]) {
      inspectorClose();
      return;
    }
    if (uuid === inspectorUuid) {
      dockVisibilitySync();
      return;
    }
    const data = structureData[uuid];
    inspectorUuid = uuid;
    dockVisibilitySync();
    if (data.type !== 'component') {
      // A region has no form of its own: the breadcrumb bar still says where
      // it is, and the body says what to do with it.
      inspectorEmpty(Drupal.t('Select a component in @region to edit it, or use the + between components to add one.', { '@region': String(data.data.label ?? '') }));
      return;
    }
    inspectorLoad(uuid);
  }

  function inspectorEmpty(text: string): void {
    if (!dockBody) {
      return;
    }
    Drupal.detachBehaviors(dockBody, drupalSettings, 'unload');
    dockBody.innerHTML = '';
    dockBandClear();
    // No form to name it, so the band names it itself: a region, or a
    // component this editor may not open.
    const label = inspectorUuid ? String(structureData[inspectorUuid]?.data?.label ?? '') : '';
    if (label) {
      const name = document.createElement('span');
      name.className = 'neo-alchemist--form-identity-name font-bold';
      name.textContent = label;
      container.querySelector('.neo-alchemist--dock-identity')?.replaceChildren(name);
    }
    const note = document.createElement('p');
    note.className = 'py-6 text-sm text-base-0-content/75';
    note.textContent = text;
    dockBody.appendChild(note);
    inspectorDirty = false;
  }

  /**
   * Load a component's form into the dock.
   */
  function inspectorLoad(uuid: string): void {
    if (!dockBody) {
      return;
    }
    inspectorUuid = uuid;
    inspectorDirty = false;
    inspectorApplying = false;
    const op = structureData[uuid]?.data?.ops?.edit;
    if (!op?.permitted) {
      inspectorEmpty(Drupal.t('This component cannot be edited here.'));
      return;
    }
    const token = ++inspectorLoadToken;
    Drupal.detachBehaviors(dockBody, drupalSettings, 'unload');
    dockBody.innerHTML = '';
    dockBandClear();
    dockBody.setAttribute('aria-busy', 'true');
    const ajax: any = Drupal.ajax({
      url: appendParams(op.url, { dock: '1' }),
      wrapper: dockBody.id,
      method: 'html',
      progress: { type: 'none' },
    } as any);
    const success = ajax.success;
    ajax.success = function (this: any, response: any, status: string) {
      if (token !== inspectorLoadToken) {
        return;
      }
      dockBody.removeAttribute('aria-busy');
      return success.call(this, response, status);
    };
    ajax.execute();
    // The frames render with this component's prop markers and map, so a
    // click in the preview finds its field and a field finds its element.
    inspectorRefreshFrames(uuid);
  }

  function inspectorClose(): void {
    if (!dock || !dockBody) {
      return;
    }
    const had = inspectorUuid;
    inspectorLoadToken++;
    inspectorUuid = null;
    inspectorDirty = false;
    inspectorPromptHide();
    if (had) {
      Drupal.detachBehaviors(dockBody, drupalSettings, 'unload');
      dockBody.innerHTML = '';
    }
    dockVisibilitySync();
  }

  /**
   * Re-render the canvas, for the inspected component when given one.
   */
  function inspectorRefreshFrames(uuid: string | null): void {
    const refresh = Drupal.neoAlchemist?.refreshFrames;
    const frames = Object.values(iframes).filter(Boolean) as HTMLIFrameElement[];
    if (refresh && frames.length) {
      refresh(frames, uuid ? { inspect: uuid } : {});
    }
  }

  /**
   * Throw away the unapplied changes: the canvas goes back to the draft.
   */
  function inspectorDiscard(): void {
    inspectorDirty = false;
    inspectorApplying = false;
    inspectorRefreshFrames(inspectorUuid && structureData[inspectorUuid]?.type === 'component' ? inspectorUuid : null);
  }

  /**
   * Submit the inspector's form, then run `then` once it has applied.
   */
  function inspectorApply(then: (() => void) | null = null): void {
    // By its selector: the footer also holds the preview's Refresh, a submit
    // too and ahead of Apply, so "the first submit" pressed Refresh.
    const submit = dockBody?.querySelector<HTMLElement>('.neo-alchemist--form-footer [data-drupal-selector="edit-submit"]');
    if (!submit) {
      return;
    }
    inspectorAfterApply = then;
    // The submit is AJAX-bound to mousedown, as Drupal binds every submit.
    submit.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
  }

  /**
   * The Apply landed: the form is clean, and anything waiting on it runs.
   */
  function inspectorApplied(uuid: string): void {
    if (uuid !== inspectorUuid) {
      return;
    }
    const applied = inspectorApplying;
    inspectorApplying = false;
    inspectorDirty = false;
    inspectorPromptHide();
    const then = inspectorAfterApply;
    inspectorAfterApply = null;
    if (then) {
      then();
      return;
    }
    // Applied: the edit is done, so the inspector closes and the component
    // is let go.
    if (applied) {
      layerShow(null);
      return;
    }
    // Saved some other way (undo, redo, a move): a fresh form, built from
    // what was just written.
    inspectorLoad(uuid);
  }

  /**
   * The Apply was refused: the form is back, with its message.
   *
   * The changes are still unapplied, so the inspector stays on them. What the
   * prompt was waiting on is dropped — it would otherwise fire on the next
   * unrelated save — and the message, rendered at the top of the form, is
   * brought into view: the form is usually scrolled to the field just edited.
   */
  function inspectorApplyRefused(): void {
    inspectorApplying = false;
    inspectorAfterApply = null;
    inspectorPromptHide();
    const message = dockBody?.querySelector<HTMLElement>('[data-drupal-messages], .messages--error, .form-item--error-message');
    message?.scrollIntoView({ block: 'nearest' });
    Drupal.neoAlchemist?.toast?.(Drupal.t('Changes to @label were not applied.', { '@label': inspectorLabel() }), 'error');
  }

  /**
   * Whether an action has to wait for the prompt. When it does, the prompt is
   * shown and the action runs once it is answered.
   */
  function inspectorGuard(label: string, action: () => void): boolean {
    if (!inspectorDirty || !dockPrompt) {
      return false;
    }
    inspectorPending = action;
    dockPrompt.innerHTML = '';
    const text = document.createElement('p');
    text.className = 'mb-2';
    text.textContent = Drupal.t('Apply changes to @label?', { '@label': label });
    const buttons = document.createElement('div');
    buttons.className = 'flex gap-2';
    const apply = document.createElement('button');
    apply.type = 'button';
    apply.className = 'btn btn-xs btn-primary';
    apply.textContent = Drupal.t('Apply');
    apply.addEventListener('click', () => {
      const pending = inspectorPending;
      inspectorPending = null;
      inspectorApply(() => {
        inspectorPromptHide();
        pending?.();
      });
    });
    const discard = document.createElement('button');
    discard.type = 'button';
    discard.className = 'btn btn-xs btn-outline';
    discard.textContent = Drupal.t('Discard');
    discard.addEventListener('click', () => {
      const pending = inspectorPending;
      inspectorPending = null;
      inspectorPromptHide();
      inspectorDiscard();
      if (pending) {
        pending();
      }
      // Discarding without moving on: the same component, reloaded clean. Not
      // when what was waiting already reloaded it (or is about to: an op's
      // response does), but always without one — the form still holds the
      // discarded values.
      else if (inspectorUuid && structureData[inspectorUuid]?.type === 'component') {
        inspectorLoad(inspectorUuid);
      }
    });
    buttons.append(apply, discard);
    dockPrompt.append(text, buttons);
    dockPrompt.hidden = false;
    apply.focus();
    return true;
  }

  function inspectorPromptHide(): void {
    if (dockPrompt) {
      dockPrompt.hidden = true;
      dockPrompt.innerHTML = '';
    }
    inspectorPending = null;
  }

  /**
   * The label the prompt names: the component with the unapplied changes.
   */
  function inspectorLabel(): string {
    return String((inspectorUuid && structureData[inspectorUuid]?.data?.label) || Drupal.t('this component'));
  }

  /**
   * Keyboard into the inspector: its first field (Enter in Layers).
   */
  function inspectorFocus(): void {
    // The first field on screen: the inactive tab panes are in the DOM too,
    // and a hidden control takes no focus. The per-field Default / Hide chips
    // are controls of the legend, not the field.
    const field = Array.from(dockBody?.querySelectorAll<HTMLElement>('.neo-alchemist--form-body :is(input:not([type="hidden"]):not([disabled]), select, textarea, [contenteditable="true"])') ?? [])
      .find(el => !el.closest('[hidden], .form--inline-min') && el.getClientRects().length > 0);
    if (field) {
      field.focus();
      return;
    }
    dock?.setAttribute('tabindex', '-1');
    dock?.focus();
  }

  function opsSet(uuid: string | null): void {
    if (!opButtons) return;
    opTooltipsHide();

    // Find enabled buttons
    const enabledButtons: HTMLElement[] = [];
    if (uuid) {
      const data = structureData[uuid];
      const ops = data?.data?.ops;
      if (ops) {
        for (const [opKey, op] of Object.entries(ops)) {
          // Read `permitted` off the record, not the record's truthiness: an op
          // is now an object and every object is truthy, so testing the value
          // itself would offer every op regardless of permission — move up on
          // the first component included.
          if (!op.permitted) {
            continue;
          }
          // Every copy of the op, not just the first found. The move ops live
          // in the inspector's header as well as on the outline, and stopping
          // at the first match would leave the other copy hidden.
          dock?.querySelectorAll<HTMLElement>(`[data-op="${opKey}"]`).forEach(button => enabledButtons.push(button));
          sizes.forEach(size => {
            const overlayButton = overlayElements[uuid]?.[size]?.querySelector<HTMLElement>(`[data-op="${opKey}"]`);
            if (overlayButton) {
              enabledButtons.push(overlayButton);
            }
          });
        }
      }
    }

    const enabledSet = new Set(enabledButtons);
    opButtons.forEach(button => {
      button.style.display = enabledSet.has(button) ? '' : 'none';
    });
    // A rule between moves and verbs only where both sides kept a button; the
    // ⋯ only when its menu has an item.
    container.querySelectorAll<HTMLElement>('.neo-alchemist--rail-divider, .neo-alchemist--dock-menu-divider').forEach(divider => {
      const shown = (from: Element | null, step: 'previousElementSibling' | 'nextElementSibling'): boolean => {
        for (let node = from; node; node = node[step]) {
          if (node instanceof HTMLElement && node.dataset.op && node.style.display !== 'none') {
            return true;
          }
        }
        return false;
      };
      divider.style.display = shown(divider.previousElementSibling, 'previousElementSibling') && shown(divider.nextElementSibling, 'nextElementSibling') ? '' : 'none';
    });
    // The grip drags with the move op's route, so it goes where moving does.
    const ops = uuid ? structureData[uuid]?.data?.ops : null;
    const canMove = !!(ops?.['move-up']?.permitted || ops?.['move-down']?.permitted);
    container.querySelectorAll<HTMLElement>('.neo-alchemist--rail-grip').forEach(grip => {
      grip.style.display = canMove ? '' : 'none';
    });
    dockMenuSet(false);
    if (dockMore) {
      dockMore.hidden = !dockMenu?.querySelector('[data-op]:not([style*="display: none"])');
    }
  }

  /**
   * An op's shortcut as this keyboard writes it: the first of its keys.
   */
  function opKeysLabel(keys: string): string {
    const names: Record<string, string> = {
      Mod: isMac ? '⌘' : 'Ctrl',
      Alt: isMac ? '⌥' : 'Alt',
      Shift: isMac ? '⇧' : 'Shift',
      ArrowUp: '↑',
      ArrowDown: '↓',
      Delete: isMac ? '⌫' : 'Delete',
      Backspace: '⌫',
    };
    return (keys.split(' ')[0] ?? '').split('+').map(part => names[part] ?? part.toUpperCase()).join(isMac ? '' : '+');
  }

  /**
   * Opens or closes the inspector's ⋯ menu of the selection's ops.
   */
  function dockMenuSet(open: boolean): void {
    if (!dockMenu || !dockMoreToggle || dockMenu.hidden === !open) {
      return;
    }
    dockMenu.hidden = !open;
    dockMoreToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      dockMenu.querySelector<HTMLElement>('[data-op]:not([style*="display: none"])')?.focus();
    }
  }

  /**
   * Brings a selection's rail just inside its left edge where the canvas
   * leaves no room for it outside.
   */
  function railsPlace(): void {
    const edge = wrapper.getBoundingClientRect().left;
    container.querySelectorAll<HTMLElement>('.neo-alchemist--overlay[data-state="focus"]').forEach(overlay => {
      const rail = overlay.querySelector<HTMLElement>('.neo-alchemist--rail');
      if (!rail) {
        return;
      }
      const room = overlay.getBoundingClientRect().left - edge;
      const inside = room < rail.getBoundingClientRect().width + 8;
      overlay.classList.toggle('is-rail-inside', inside);
      // Inside the part of the component on screen, when its edge is not.
      overlay.style.setProperty('--rail-x', `${inside ? Math.max(0, -room) : 0}px`);
    });
  }

  // let layerTimeout: ReturnType<typeof setTimeout> | null = null;
  /**
   * @param scroll
   *   Whether to bring the layer into view. False when restoring a selection
   *   the author already had — after a preview refresh the canvas has not
   *   moved, so scrolling to a layer they are already looking at is at best
   *   redundant. It is also wrong twice over: ready() runs before the rebuilt
   *   elements have real positions, so the first scroll goes to the top of the
   *   canvas and a second one lands it back where it started.
   */
  function layerShow(uuid: string | null = null, force: boolean = false, instant: boolean = false, scroll: boolean = true): void {
    if (!uuid && !layerUuid) {
      return;
    }
    // Moving off a component with unapplied changes asks first.
    if (uuid !== layerUuid && inspectorGuard(inspectorLabel(), () => layerShow(uuid, force, instant, scroll))) {
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

    if (uuid && scroll) {
      sizes.forEach(size => {
        const element = structureElements[uuid as string]?.[size];
        if (size === layerInteractSize && element) {
          // `stay`: selecting a section by clicking inside it must not scroll
          // the canvas to the section's top — see scrollElementIntoView().
          Drupal.behaviors.neoAlchemistComponentParent.scrollElementIntoView(element, wrapper, 100, 'smooth', 'stay');
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
    inspectorSync(uuid);
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
    // Every edit rebuilds the rows; a keyboard user in the tree stays there.
    const hadFocus = layersTree.contains(document.activeElement);
    layersTree.innerHTML = '';
    const roots = Object.keys(structureData).filter(uuid => structureData[uuid].parents.length === 0);
    if (!roots.length) {
      layersPanelToggle(false, true);
      return;
    }
    roots.forEach(uuid => layersRowBuild(uuid, layersTree as HTMLElement));
    layersSelectionSet(layerUuid);
    if (hadFocus) {
      layersTree.querySelector<HTMLElement>('.neo-alchemist--layer[tabindex="0"]')?.focus({ preventScroll: true });
    }
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
        : ' cursor-pointer hover:bg-base-100 aria-selected:font-semibold aria-selected:bg-primary-500/15 aria-selected:shadow-[inset_2px_0_0_rgb(var(--color-primary-500))]')
      + ' focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-500';
    row.setAttribute('role', 'treeitem');
    row.setAttribute('data-uuid', uuid);
    // A component can be dragged to a new place; a region is where things are
    // dropped, never moved itself.
    if (!inert && data.type === 'component') {
      row.draggable = true;
    }
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
        const baseUrl = libraryBaseUrl();
        if (baseUrl !== null) {
          actions.library(uuid, baseUrl);
        }
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
    const rows = Array.from(layersTree.querySelectorAll<HTMLElement>('.neo-alchemist--layer'));
    // A roving tree: exactly one row is a tab stop — the selected one, else
    // the first that can be selected — so Tab lands on the tree once and the
    // arrows do the rest.
    const stop = rows.find(row => !!uuid && row.dataset.uuid === uuid)
      ?? rows.find(row => !row.dataset.inert);
    rows.forEach(row => {
      const isCurrent = !!uuid && row.dataset.uuid === uuid;
      row.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
      row.setAttribute('tabindex', row === stop ? '0' : '-1');
      if (isCurrent) {
        row.scrollIntoView({ block: 'nearest' });
      }
    });
    // Keyboard focus follows the selection while it is in the tree, wherever
    // the selection was changed from.
    if (stop && layersTree.contains(document.activeElement) && document.activeElement !== stop
      && document.activeElement?.classList.contains('neo-alchemist--layer')) {
      stop.focus({ preventScroll: true });
    }
  }

  /**
   * Move the rail's selection with the arrow keys.
   *
   * The canvas hit targets are bare divs with click handlers, so this is the
   * only keyboard route to a node.
   */
  function layersKeydown(e: KeyboardEvent): void {
    // Alt+↑/↓ moves the focused component among its siblings: the keyboard
    // half of dragging a row.
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')
      && e.target instanceof HTMLElement && e.target.classList.contains('neo-alchemist--layer')) {
      e.preventDefault();
      const uuid = e.target.dataset.uuid;
      if (!uuid || structureData[uuid]?.type !== 'component') {
        return;
      }
      if (uuid !== layerUuid) {
        layerShowOffCanvas(uuid);
      }
      if (layerUuid === uuid) {
        operationExecute(e.key === 'ArrowUp' ? 'move-up' : 'move-down');
      }
      return;
    }
    const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', ' '];
    // Only a row's own keys: a region row's + button is a button, and Enter on
    // it must add, not edit.
    if (!layersTree || !keys.includes(e.key) || !(e.target instanceof HTMLElement) || !e.target.classList.contains('neo-alchemist--layer')) {
      return;
    }
    const rows: HTMLElement[] = Array.from(layersTree.querySelectorAll<HTMLElement>('.neo-alchemist--layer:not([data-inert])'));
    if (!rows.length) {
      return;
    }
    e.preventDefault();
    const current = rows.findIndex(row => row.dataset.uuid === layerUuid);
    // With nothing selected, the first arrow selects the row that has focus.
    const focused = rows.indexOf(e.target);
    let next = current;
    switch (e.key) {
      case 'ArrowDown':
        next = current < 0 ? Math.max(focused, 0) : Math.min(current + 1, rows.length - 1);
        break;
      case 'ArrowUp':
        next = current < 0 ? Math.max(focused, 0) : Math.max(current - 1, 0);
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
      rows[next].focus({ preventScroll: true });
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
  /**
   * Layers drag-and-drop: drop a component before or after another, or into
   * a region (at its end).
   *
   * One drop is one draft write, through the move route's `to` step
   * (InstanceComponentMoveController), so it is one step to undo. The tree
   * refuses a drop into the dragged component itself or under it, and hybrid
   * layouts only take drops into their customizable regions; both are checked
   * here too, so a refused drop never shows as allowed.
   */
  interface LayersDrop {
    // The region it lands in (`ownerUuid--shapeId`), or null for the page.
    container: string | null;
    sibling: string | null;
    position: 'before' | 'after' | null;
  }

  function layersDropAt(row: HTMLElement, clientY: number): LayersDrop | null {
    const rect = row.getBoundingClientRect();
    return dropFor(layersDragUuid, row.dataset.uuid, clientY < rect.top + (rect.height / 2), !!row.dataset.inert);
  }

  /**
   * Where a drag of one node onto another would land, or null if nowhere.
   *
   * The one rule for Layers and the canvas alike: before or after a component
   * whose container is a region (or the page), into a region at its end;
   * never into the dragged node or under it, only into customizable regions in
   * a hybrid layout, and never back where it already is.
   *
   * @param first
   *   Whether the pointer is on the first half of the target (its top, or its
   *   left where siblings sit side by side): before rather than after.
   */
  function dropFor(dragged: string | null, uuid: string | undefined, first: boolean, inert: boolean = false): LayersDrop | null {
    const data = uuid ? structureData[uuid] : null;
    if (!dragged || !uuid || !data || uuid === dragged || data.parents.includes(dragged) || inert) {
      return null;
    }
    if (data.type === 'region') {
      if (hybrid && !isCustomRegion(uuid)) {
        return null;
      }
      return { container: uuid, sibling: null, position: null };
    }
    const container = data.parents[data.parents.length - 1] || null;
    if (container && structureData[container]?.type !== 'region') {
      return null;
    }
    if (hybrid && !(container && isCustomRegion(container))) {
      return null;
    }
    const position = first ? 'before' : 'after';
    // Dropping a component back where it already is writes nothing.
    const siblings = container ? (structureData[container]?.children ?? []) : Object.keys(structureData).filter(key => !structureData[key].parents.length);
    const from = structureData[dragged]?.parents.slice(-1)[0] || null;
    if (from === container) {
      const order = siblings.filter(key => key !== dragged);
      const at = order.indexOf(uuid) + (position === 'after' ? 1 : 0);
      if (siblings.indexOf(dragged) === at) {
        return null;
      }
    }
    return { container, sibling: uuid, position };
  }

  function layersDropMark(row: HTMLElement | null, drop: LayersDrop | null): void {
    if (layersDropRow && layersDropRow !== row) {
      layersDropRow.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-into');
    }
    layersDropRow = row;
    if (!row) {
      return;
    }
    row.classList.toggle('is-drop-before', drop?.position === 'before');
    row.classList.toggle('is-drop-after', drop?.position === 'after');
    row.classList.toggle('is-drop-into', !!drop && drop.position === null);
  }

  function layersDrop(dragged: string, drop: LayersDrop): void {
    const ops = structureData[dragged]?.data?.ops;
    const base = ops?.['move-down']?.url || ops?.['move-up']?.url;
    if (!base) {
      return;
    }
    // The op records carry the step's URL; a drop is the same route with the
    // `to` step in place of a direction.
    const url = new URL(base, window.location.origin);
    url.pathname = url.pathname.replace(/\/(up|down)$/, '/to');
    const params: Record<string, string | null> = {
      parent: structureData[dragged]?.parents.slice(-1)[0] || null,
      target: drop.container ?? '',
      before: drop.position === 'before' ? drop.sibling : null,
      after: drop.position === 'after' ? drop.sibling : null,
    };
    const run = () => {
      revealUuid = dragged;
      revealUntil = Date.now() + 3000;
      Drupal.ajax({
        progress: ajaxProgress(),
        url: appendParams(url.pathname + url.search, params),
      }).execute();
    };
    // A drop moves the selection onto what was dropped, which would take the
    // inspector off changes that are not applied yet.
    if (inspectorGuard(inspectorLabel(), run)) {
      return;
    }
    run();
  }

  /**
   * Dragging the selection on the canvas, from its rail's grip.
   *
   * The drop is the same as a Layers drop (dropFor(), then layersDrop(): one
   * request to the move route's `to` step). Targets are found from the rects
   * the frame being interacted with reported, deepest first, so a component
   * inside a region offers before/after it and the region's empty space offers
   * the region. A shield over the preview keeps the pointer's moves from the
   * frames; near the canvas's top or bottom edge the canvas scrolls.
   */
  function canvasDragInit(): void {
    canvasDropLine.innerHTML = `<span>${escapeHtml(Drupal.t('Move here'))}</span>`;
    chrome.append(canvasDropLine, canvasDropRegion);
    chrome.addEventListener('pointerdown', (e) => {
      const grip = e.target instanceof Element ? e.target.closest<HTMLElement>('.neo-alchemist--rail-grip') : null;
      if (!grip || !layerUuid || e.button !== 0) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      canvasDragStart(layerUuid);
    });
  }

  function canvasDragStart(uuid: string): void {
    canvasDragUuid = uuid;
    canvasDropTarget = null;
    opTooltipsHide();
    container.classList.add('is-canvas-dragging');
    canvasDragShield = document.createElement('div');
    canvasDragShield.className = 'neo-alchemist--drag-shield';
    (wrapper.closest('.neo-alchemist-manage--preview') ?? wrapper).appendChild(canvasDragShield);
    window.addEventListener('pointermove', canvasDragMove);
    window.addEventListener('pointerup', canvasDragEnd);
    window.addEventListener('pointercancel', canvasDragCancel);
    document.addEventListener('keydown', canvasDragKey, true);
  }

  function canvasDragMove(e: PointerEvent): void {
    canvasDragPoint = { x: e.clientX, y: e.clientY };
    canvasDropFind();
    // Near an edge the canvas scrolls, faster the nearer, and keeps finding
    // the drop under the still pointer as the page moves beneath it.
    const edge = wrapper.getBoundingClientRect();
    const reach = 60;
    const speed = e.clientY < edge.top + reach
      ? -Math.ceil((edge.top + reach - e.clientY) / 4)
      : (e.clientY > edge.bottom - reach ? Math.ceil((e.clientY - (edge.bottom - reach)) / 4) : 0);
    if (speed && canvasDragScroll === null) {
      const step = () => {
        const current = canvasDragPoint ? wrapper.getBoundingClientRect() : null;
        if (!current || !canvasDragUuid || !canvasDragPoint) {
          canvasDragScroll = null;
          return;
        }
        const y = canvasDragPoint.y;
        const delta = y < current.top + reach ? -Math.ceil((current.top + reach - y) / 4) : (y > current.bottom - reach ? Math.ceil((y - (current.bottom - reach)) / 4) : 0);
        if (!delta) {
          canvasDragScroll = null;
          return;
        }
        wrapper.scrollTop += delta;
        canvasDropFind();
        canvasDragScroll = requestAnimationFrame(step);
      };
      canvasDragScroll = requestAnimationFrame(step);
    }
  }

  /**
   * The drop under the pointer, marked on the canvas.
   */
  function canvasDropFind(): void {
    const size = layerInteractSize;
    const positions = positionData[size];
    const point = canvasDragPoint;
    canvasDropTarget = null;
    canvasDropLine.hidden = true;
    canvasDropRegion.hidden = true;
    if (!positions || !point || !canvasDragUuid || !getIframe(size)) {
      return;
    }
    // The pointer in the frame's own pixels, which are the layer's.
    const frame = calculateRect(getIframe(size).getBoundingClientRect());
    const at = calculateRect(new DOMRect(point.x, point.y, 0, 0));
    const x = at.left - frame.left;
    const y = at.top - frame.top;
    const under = Object.keys(positions)
      .filter(uuid => {
        const rect = positions[uuid];
        return structureData[uuid] && x >= rect.left && x <= rect.left + rect.width && y >= rect.top && y <= rect.top + rect.height;
      })
      .sort((a, b) => structureData[b].parents.length - structureData[a].parents.length);
    for (const uuid of under) {
      const rect = positions[uuid];
      const container = structureData[uuid].parents.slice(-1)[0] || null;
      const siblings = container ? (structureData[container]?.children ?? []) : Object.keys(structureData).filter(key => !structureData[key].parents.length);
      // Side by side (a grid's cards) when a sibling shares this one's top.
      const sideways = siblings.some(other => other !== uuid && positions[other] && Math.abs(positions[other].top - rect.top) < 4);
      const first = sideways ? x < rect.left + (rect.width / 2) : y < rect.top + (rect.height / 2);
      const drop = dropFor(canvasDragUuid, uuid, first, hybrid && isInheritedComponent(uuid));
      if (!drop) {
        continue;
      }
      canvasDropTarget = drop;
      const box = calculateIframeRect(size, rect);
      if (drop.position === null) {
        Object.assign(canvasDropRegion.style, { left: `${box.left}px`, top: `${box.top}px`, width: `${box.width}px`, height: `${box.height}px` });
        canvasDropRegion.hidden = false;
        return;
      }
      const thick = 'calc(3px * var(--inv))';
      canvasDropLine.classList.toggle('is-vertical', sideways);
      if (sideways) {
        const left = drop.position === 'before' ? box.left : box.left + box.width;
        Object.assign(canvasDropLine.style, { left: `${left}px`, top: `${box.top}px`, width: thick, height: `${box.height}px`, transform: 'translateX(-50%)' });
      }
      else {
        const top = drop.position === 'before' ? box.top : box.top + box.height;
        Object.assign(canvasDropLine.style, { left: `${box.left}px`, top: `${top}px`, width: `${box.width}px`, height: thick, transform: 'translateY(-50%)' });
      }
      canvasDropLine.hidden = false;
      return;
    }
  }

  function canvasDragEnd(): void {
    const dragged = canvasDragUuid;
    const drop = canvasDropTarget;
    canvasDragCancel();
    if (dragged && drop) {
      layersDrop(dragged, drop);
    }
  }

  function canvasDragCancel(): void {
    canvasDragUuid = null;
    canvasDropTarget = null;
    canvasDragPoint = null;
    if (canvasDragScroll !== null) {
      cancelAnimationFrame(canvasDragScroll);
      canvasDragScroll = null;
    }
    canvasDropLine.hidden = true;
    canvasDropRegion.hidden = true;
    canvasDragShield?.remove();
    canvasDragShield = null;
    container.classList.remove('is-canvas-dragging');
    window.removeEventListener('pointermove', canvasDragMove);
    window.removeEventListener('pointerup', canvasDragEnd);
    window.removeEventListener('pointercancel', canvasDragCancel);
    document.removeEventListener('keydown', canvasDragKey, true);
  }

  function canvasDragKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      canvasDragCancel();
    }
  }

  function layersDragInit(): void {
    if (!layersTree) {
      return;
    }
    layersTree.addEventListener('dragstart', (e) => {
      const row = (e.target as HTMLElement).closest<HTMLElement>('.neo-alchemist--layer');
      if (!row?.draggable || !row.dataset.uuid) {
        return;
      }
      layersDragUuid = row.dataset.uuid;
      row.classList.add('is-dragging');
      e.dataTransfer?.setData('text/plain', layersDragUuid);
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
      }
    });
    layersTree.addEventListener('dragover', (e) => {
      const row = (e.target as HTMLElement).closest<HTMLElement>('.neo-alchemist--layer');
      const drop = row ? layersDropAt(row, e.clientY) : null;
      layersDropMark(row, drop);
      if (drop) {
        e.preventDefault();
        if (e.dataTransfer) {
          e.dataTransfer.dropEffect = 'move';
        }
      }
    });
    layersTree.addEventListener('dragleave', (e) => {
      if (!layersTree.contains(e.relatedTarget as Node)) {
        layersDropMark(null, null);
      }
    });
    layersTree.addEventListener('drop', (e) => {
      const row = (e.target as HTMLElement).closest<HTMLElement>('.neo-alchemist--layer');
      const dragged = layersDragUuid;
      const drop = row ? layersDropAt(row, e.clientY) : null;
      layersDropMark(null, null);
      if (!dragged || !drop) {
        return;
      }
      e.preventDefault();
      layersDrop(dragged, drop);
    });
    layersTree.addEventListener('dragend', () => {
      layersTree.querySelectorAll('.is-dragging').forEach(row => row.classList.remove('is-dragging'));
      layersDropMark(null, null);
      layersDragUuid = null;
    });
  }

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
    if (open && leftPane !== 'layers') {
      leftPaneSet('layers');
    }
    // The toggle stays put either way — it is the one fixed way to reach the
    // tree — and reflects the state rather than disappearing into it.
    layersToggleButton?.classList.toggle('is-active', open && leftPane === 'layers');
    layersToggleButton?.setAttribute('aria-expanded', open && leftPane === 'layers' ? 'true' : 'false');
    if (!silent) {
      localStorage.setItem(layersStorageKey, open ? 'open' : 'closed');
    }
    layersRailUnfolded = false;
    layersPosition();
  }

  /**
   * Fold Layers to an icon rail while the window is narrow and the inspector
   * is open, and unfold it when the inspector closes. Not a choice about
   * Layers, so nothing is remembered; the rail's own button unfolds it until
   * then. A panel the editor opened in the left dock is never folded away.
   */
  function layersPosition(): void {
    if (!layersPanel) {
      return;
    }
    const dockOpen = !!dock && !dock.hidden;
    if (!dockOpen) {
      layersRailUnfolded = false;
    }
    const rail = leftPane === 'layers' && dockOpen && !layersRailUnfolded && window.innerWidth < LAYERS_RAIL_BELOW;
    layersPanel.classList.toggle('is-rail', rail);
    const railButton = layersPanel.querySelector<HTMLElement>('.neo-alchemist--layers-rail');
    if (railButton) {
      railButton.hidden = !rail;
    }
  }

  /**
   * Compact the band, one step at a time, while it has no room: first the
   * panel pills drop their names to their icons (the name stays in each
   * tooltip), then + Add says just "Add", then the badges drop whatever they
   * mark `.neo-alchemist--compact-hide` (neo_seo: the score bars, keeping the
   * numbers). Measured rather than set at a width, because what the band
   * holds varies by layout, by which modules add panels and by the draft.
   * Measured with nothing allowed to shrink or wrap (`.is-measuring`, never
   * painted): short of room, the band squeezes its buttons onto two lines
   * rather than overflowing.
   *
   * The centre is centred while each side has room for the wider of the two;
   * short of that, before anything loses its name, the centre gives up being
   * centred and takes the room the narrower side leaves (`.is-header-
   * offcentre`).
   */
  function headerFit(): void {
    const top = container.querySelector<HTMLElement>('.neo-alchemist-manage--top');
    if (!top) {
      return;
    }
    const steps = ['is-header-compact', 'is-header-compact-2', 'is-header-compact-3'];
    container.classList.remove(...steps, 'is-header-offcentre');
    top.classList.add('is-measuring');
    const style = getComputedStyle(top);
    const room = top.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)
      - (parseFloat(style.columnGap) || 0) * (top.children.length - 1);
    const width = (selector: string) => top.querySelector<HTMLElement>(`:scope > ${selector}`)?.getBoundingClientRect().width ?? 0;
    for (let step = 0; ; step++) {
      const sides = [width('.neo-alchemist-manage--top-start'), width('.neo-alchemist-manage--top-trail')];
      const centre = width('.neo-alchemist-manage--top-center');
      if (2 * Math.max(...sides) + centre <= room) {
        break;
      }
      if (sides[0] + sides[1] + centre <= room || step === steps.length) {
        container.classList.add('is-header-offcentre');
        break;
      }
      container.classList.add(steps[step]);
    }
    top.classList.remove('is-measuring');
  }

  /**
   * Show one thing in the left dock: Layers, or a side panel.
   *
   * The left dock holds what belongs to the whole page, so nothing here
   * touches the inspector or the selection. It is titled for what it shows,
   * and a panel widens it to the panel's width. A panel's client is mounted on
   * its first open, not at page load, so a panel nobody opens costs nothing;
   * switching away keeps it mounted, so it comes back as it was left.
   */
  function leftPaneSet(pane: string): void {
    if (!layersPanel) {
      return;
    }
    if (pane !== 'layers' && !sidePanels[pane]) {
      pane = 'layers';
    }
    leftPane = pane;
    sidePanelOpenId = pane === 'layers' ? null : pane;
    layersPanel.querySelectorAll<HTMLElement>('[data-left-pane]').forEach(element => {
      element.hidden = element.dataset.leftPane !== pane;
    });
    layersPanel.style.width = pane === 'layers' ? '' : (sidePanels[pane].dataset.width || '24rem');
    const title = pane === 'layers' ? Drupal.t('Layers') : (sidePanels[pane].dataset.label || '');
    if (layersTitle) {
      layersTitle.textContent = title;
    }
    layersPanel.setAttribute('aria-label', title);
    const layersShowing = pane === 'layers' && layersPanel.style.display !== 'none';
    layersToggleButton?.classList.toggle('is-active', layersShowing);
    layersToggleButton?.setAttribute('aria-expanded', layersShowing ? 'true' : 'false');
    sidePanelToggles.forEach(toggle => {
      const show = toggle.dataset.alchemistSidepanel === pane;
      toggle.classList.toggle('is-active', show);
      toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
    });
    if (pane !== 'layers') {
      const body = sidePanels[pane].querySelector<HTMLElement>('.neo-alchemist--sidepanel-body');
      if (body) {
        Drupal.neoAlchemist?.panels?.mount(pane, body);
      }
    }
    layersPosition();
  }

  /**
   * The inspector's dock is open exactly while there is a selection.
   */
  function dockVisibilitySync(): void {
    if (!dock) {
      return;
    }
    dock.hidden = !inspectorUuid;
    if (dock.hidden) {
      dock.classList.remove('is-expanded');
    }
    layersPosition();
  }

  /**
   * Open a side panel in the left dock, or close it.
   *
   * Opening shows the dock if it was closed; closing goes back to Layers if
   * Layers was showing before, else closes the dock.
   *
   * @param id
   *   The panel to act on, or null for whichever is open.
   * @param open
   *   Whether to open it.
   */
  function sidePanelToggle(id: string | null, open: boolean): void {
    if (hasSideForm || !layersPanel) {
      return;
    }
    if (open && id) {
      if (leftPane === 'layers') {
        leftOpenBeforePanel = layersPanelIsOpen();
      }
      layersPanel.style.display = '';
      leftPaneSet(id);
      return;
    }
    if (id && leftPane !== id) {
      return;
    }
    leftPaneSet('layers');
    if (!leftOpenBeforePanel) {
      layersPanel.style.display = 'none';
      layersToggleButton?.classList.remove('is-active');
      layersToggleButton?.setAttribute('aria-expanded', 'false');
    }
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
    const absoluteRect = calculateIframeRect(
      size,
      new DOMRect(regionRect.left, y, regionRect.width, 0),
    );
    // At the line; its height, and the centring on it, are screen pixels
    // (components-parent.css).
    seam.style.left = `${absoluteRect.left}px`;
    seam.style.top = `${absoluteRect.top}px`;
    seam.style.width = `${absoluteRect.width}px`;
    seam.style.zIndex = '28';
    const button = seam.querySelector<HTMLElement>('button');
    button?.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      seamAdd(parentUuid, siblingUuid, position);
    });
    chrome.appendChild(seam);
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
    // The sibling is always a component (seamContainerChildren filters to
    // components), so it carries the add ops. Find the one for this side by its
    // verb and position, read off the records — the client does not reconstruct
    // the op id. That op's URL is the library route already scoped to this
    // sibling and side, generated by the server, so a non-standard base path is
    // right by construction. The container is known only here, so it rides as a
    // client-side parameter.
    const addOp = Object.values(structureData[siblingUuid]?.data?.ops ?? {})
      .find(op => op.verb === 'add' && op.position === position);
    if (!addOp) {
      return;
    }
    Drupal.ajax({
      progress: ajaxProgress(),
      url: appendParams(addOp.url, { parent: parentUuid }),
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
    interactSizeSet(centeredSize());
    layerShow(uuid);
  }

  /**
   * The preview frame nearest the middle of the canvas viewport.
   */
  function centeredSize(): 'desktop' | 'tablet' | 'mobile' {
    const wrapperRect = wrapper.getBoundingClientRect();
    const center = wrapperRect.left + (wrapperRect.width / 2);
    const visible = frames.visible(container);
    const selectable = (size: NeoAlchemistFrameSize) => framesDrawn.has(size) && visible.includes(size) && !!iframes[size];
    let best: NeoAlchemistFrameSize = selectable(layerInteractSize) ? layerInteractSize : (visible[0] ?? 'desktop');
    let bestDistance = Infinity;
    sizes.forEach(size => {
      // A frame with no outlines, or hidden, has nothing to select in.
      if (!selectable(size)) {
        return;
      }
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
   * Toggle the header's Add in hybrid mode.
   *
   * Root add is locked in hybrid mode, so it only makes sense scoped to a
   * customizable region — but it stays up while any layer *inside* that region
   * is focused (a focused component's add still targets its region). Non-hybrid
   * layouts keep it always visible (untouched).
   */
  function actionsToggle(): void {
    if (!hybrid || !actionButtons) return;
    const region = focusedCustomRegion(layerUuid);
    actionButtons.forEach(button => {
      button.style.display = region ? '' : 'none';
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
      // The label stays "Add component": the band has no room for a sentence.
      // Where it adds is said in the tooltip (component-parent.ts reads this
      // as it opens), and shown on the canvas on hover.
      button.setAttribute('data-alchemist-tip', target.uuid
        ? Drupal.t('Add a component to the end of @target', { '@target': target.label })
        : Drupal.t('Add a component to the end of the page'));
    });
  }

  /**
   * The progress setting every operation's request needs.
   *
   * These requests are built by hand with no triggering element, and core
   * disables the indicator outright for exactly that case —
   * `if (!settings.progress && !element) { settings.progress = false; }` in
   * Drupal.ajax(). So the canvas opted out of neo_loader's site-wide loader by
   * omission and sat silent for the ~half second an edit form takes; worst on
   * the double-click shortcut, where there was no button press to acknowledge
   * the click either. `throbber` is the same key neo_modal's own link handler
   * asks for, and neo_loader redirects it to its fullscreen loader.
   *
   * A fresh object per call: Drupal.Ajax shallow-copies these settings onto
   * itself and then writes progress.element, so one shared literal would be
   * scribbled on by every in-flight request at once.
   */
  function ajaxProgress(): { type: string } {
    return { type: 'throbber' };
  }

  /**
   * Append client-side query parameters to a server-generated URL.
   *
   * The base URL is always one the server produced — an op record's URL or an
   * action link's href — so the path, base path, language prefix and any alias
   * are already applied. This only sets query parameters the server could not
   * know in advance (the container, the region, the focused component), using
   * the URL API so no path or query grammar is assembled by hand. A null or
   * missing value is skipped rather than written as the string "null".
   */
  function appendParams(baseUrl: string, params: Record<string, string | null>): string {
    const url = new URL(baseUrl, window.location.origin);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined) {
        url.searchParams.set(key, value);
      }
    });
    return url.pathname + url.search + url.hash;
  }

  /**
   * The field's bare library URL, for openers that have no button to read.
   *
   * The toolbar's Add link is the one server-rendered element carrying it, so
   * entry points that name their own container — an empty region's placeholder,
   * a layer row's add — borrow its href rather than assembling a path. It is
   * absent when the author cannot create, and those openers then do nothing,
   * which is the same answer the server would give.
   */
  function libraryBaseUrl(): string | null {
    const button = container.querySelector<HTMLElement>('.neo-alchemist--action[data-action="library"]');
    return button?.getAttribute('href') ?? null;
  }

  function actionExecute(
    opKey: keyof Actions,
    button: HTMLElement
  ): void {
    const action = actions[opKey];
    if (!action) {
      return;
    }
    // The action button is a server-rendered link; its href is the field's
    // bare library/sort URL, already path-processed. The client adds only the
    // resolved container as a query parameter.
    const baseUrl = button.getAttribute('href');
    if (baseUrl === null) {
      return;
    }
    action(resolveAddTarget(layerUuid).uuid, baseUrl);
  }

  const actions: Actions = {
    library: (parentUuid: string | null, baseUrl: string): void => {
      Drupal.ajax({
        progress: ajaxProgress(),
        url: appendParams(baseUrl, { parent: parentUuid }),
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },
  };

  function operationExecute(
    opId: string
  ): void {
    if (!layerUuid) return;
    const op = structureData[layerUuid]?.data?.ops?.[opId];
    // Gate on `permitted`, not the record's truthiness — a record is always
    // truthy, so testing the value itself would run a forbidden op.
    if (!op?.permitted) {
      return;
    }
    // Editing is the inspector, which the selection already opened.
    if (op.verb === 'edit') {
      inspectorFocus();
      return;
    }
    // An op that changes the tree waits on unapplied changes to this
    // component, which it would otherwise build on without them.
    if (inspectorGuard(inspectorLabel(), () => operationExecute(opId))) {
      return;
    }
    // Dispatch by the op's verb, read off the record — the client no longer
    // decomposes the id by splitting it on a hyphen. The handler only chooses
    // how the request is dispatched; the URL comes from the record.
    const handler = operations[op.verb as keyof Operations];
    if (handler) {
      if (op.verb === 'move') {
        revealUuid = layerUuid;
        revealUntil = Date.now() + 3000;
      }
      handler(operationRequestUrl(op));
    }
  }

  /**
   * Undo or redo the current user's last step.
   *
   * The server names the step and hands back the component it acted on, which
   * its focus command selects and, asked to reveal, revealMoved() keeps on
   * screen. Unapplied
   * inspector changes are asked about first, as for any structural op.
   */
  function historyRun(direction: string): void {
    const button = container.querySelector<HTMLElement>(`.neo-alchemist--history[data-history="${direction}"]`);
    if (!button?.dataset.url) {
      return;
    }
    if (button.getAttribute('aria-disabled') === 'true') {
      if (button.dataset.reason) {
        Drupal.neoAlchemist?.toast?.(button.dataset.reason, 'status');
      }
      return;
    }
    if (inspectorGuard(inspectorLabel(), () => historyRun(direction))) {
      return;
    }
    Drupal.ajax({
      progress: ajaxProgress(),
      url: button.dataset.url,
    }).execute();
  }

  /**
   * Whether a key press belongs to a text field rather than the canvas.
   */
  function isTextEntry(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
      return false;
    }
    if (target.isContentEditable) {
      return true;
    }
    if (target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement) {
      return true;
    }
    return target instanceof HTMLInputElement
      && !['button', 'checkbox', 'radio', 'submit', 'reset', 'range', 'color', 'file', 'image'].includes(target.type);
  }

  /**
   * Keep a component that was just moved on screen.
   *
   * A move rebuilds the canvas around the selection without scrolling — right
   * for an edit, where the author has not moved, but a move carries the
   * component away from where they were looking. The previews report
   * positions more than once while they settle (see positionUpdateData), so
   * this follows each report for a few seconds, instantly: a smooth scroll
   * would chase a target that is still moving.
   */
  function revealMoved(): void {
    if (!revealUuid || revealUuid !== layerUuid || Date.now() > revealUntil) {
      revealUuid = null;
      return;
    }
    const element = structureElements[revealUuid]?.[layerInteractSize];
    if (element) {
      Drupal.behaviors.neoAlchemistComponentParent.scrollElementIntoView(element, wrapper, 100, 'instant', 'stay');
    }
  }

  /**
   * The URL an op's request goes to.
   *
   * The op record carries the URL the server generated for this component — for
   * an add it already names the before/after sibling, for a move the direction.
   * Where a parameter is genuinely client-side and the server could not know it
   * in advance — the container a component sits in — it is appended here as a
   * query parameter. The path itself is never built on the client.
   */
  function operationRequestUrl(op: Op): string {
    switch (op.verb) {
      case 'add':
      case 'move':
        return appendParams(op.url, { parent: focusedParentUuid() });
      default:
        return op.url;
    }
  }

  /**
   * The container the focused component sits in, or null at the page root.
   */
  function focusedParentUuid(): string | null {
    if (!layerUuid) {
      return null;
    }
    const parents = structureData[layerUuid]?.parents ?? [];
    return parents[parents.length - 1] || null;
  }

  /**
   * Operations available for components. Keyed by verb; each takes the resolved
   * request URL and only decides how the request is dispatched.
   */
  const operations: Operations = {
    // At once, with the step it made one Undo away: the editor's history is
    // the safety net, not a dialog to click through. The request carries the
    // session's request-header token, which the controller checks before
    // deleting; without one (it could not be fetched) the confirm form opens
    // as before.
    delete: (url: string): void => {
      const label = String(structureData[layerUuid ?? '']?.data?.label ?? '');
      sessionToken().then(token => {
        const request = Drupal.ajax({
          progress: ajaxProgress(),
          url: `${url}${url.includes('?') ? '&' : '?'}immediate=1&token=${encodeURIComponent(token)}`,
        }).execute() as unknown as PromiseLike<unknown> | undefined;
        Promise.resolve(request).then(() => {
          Drupal.neoAlchemist?.toast?.(Drupal.t('Deleted @label', { '@label': label }), 'status', {
            label: Drupal.t('Undo'),
            run: () => historyRun('undo'),
          });
        }, () => {});
      }, () => {
        Drupal.ajax({
          progress: ajaxProgress(),
          url: url,
          dialogType: 'modal',
          dialog: {
            ...baseModalOptions,
            width: 'auto',
            height: 'auto',
          },
        }).execute();
      });
    },

    clone: (url: string): void => {
      Drupal.ajax({
        progress: ajaxProgress(),
        url: url,
      }).execute();
    },

    add: (url: string): void => {
      Drupal.ajax({
        progress: ajaxProgress(),
        url: url,
        dialogType: 'modal',
        dialog: baseModalOptions,
      }).execute();
    },

    move: (url: string): void => {
      Drupal.ajax({
        progress: ajaxProgress(),
        url: url,
      }).execute();
    },
  };

  /**
   * The scale the chrome layer is drawn at on screen, mid-zoom included.
   *
   * Off the rendered box, not the scale announced: a zoom eases over a fifth
   * of a second, and anything measured during it is measured at the scale on
   * screen at that moment.
   */
  function layerScale(): number {
    const natural = scaleBox?.offsetWidth || 0;
    const rendered = scaleBox?.getBoundingClientRect().width || 0;
    return natural && rendered ? rendered / natural : 1;
  }

  /**
   * Keeps the chrome's counter-scale in step with the scale on screen.
   *
   * --inv is what the chrome's strokes and chips multiply by to keep their
   * screen size inside the scaled layer (components-parent.css).
   */
  function invSync(): void {
    chrome.style.setProperty('--inv', String(Math.round((1 / layerScale()) * 10000) / 10000));
  }

  /**
   * Runs invSync() every frame while a zoom eases. Set once to the target, a
   * chip would swell and shrink again as the frames eased towards it.
   */
  let invFrame: number | null = null;
  let invUntil = 0;
  let invSettle: ReturnType<typeof setTimeout> | undefined;
  function invTrack(): void {
    invUntil = performance.now() + 600;
    // Once more after the zoom, whatever happened to the frames in between:
    // an interrupted transition sends no transitionend, and a hidden tab runs
    // no animation frames.
    clearTimeout(invSettle);
    invSettle = setTimeout(() => {
      invSync();
      railsPlace();
    }, 600);
    if (invFrame !== null) {
      return;
    }
    const tick = () => {
      invSync();
      invFrame = performance.now() < invUntil ? requestAnimationFrame(tick) : null;
    };
    tick();
  }

  /**
   * A rect on screen, in the chrome layer's coordinates.
   */
  function calculateRect(rect: DOMRect, heightOffset: number = 0): DOMRect {
    const origin = chrome.getBoundingClientRect();
    const layer = layerScale();
    return new DOMRect(
      (rect.left - origin.left) / layer,
      ((rect.top - origin.top) / layer) - heightOffset,
      rect.width / layer,
      (rect.height / layer) + (heightOffset * 2)
    );
  }

  /**
   * A rect a frame reported, in the chrome layer's coordinates.
   *
   * The frame's rects are in its own unscaled pixels, which are the layer's:
   * only the frame's offset in the row has to be found.
   */
  function calculateIframeRect(size: 'desktop' | 'tablet' | 'mobile', rect: DOMRect, heightOffset: number = 0): DOMRect {
    const frame = calculateRect(getIframe(size).getBoundingClientRect());
    return new DOMRect(
      frame.left + rect.left,
      frame.top + rect.top - heightOffset,
      rect.width,
      rect.height + (heightOffset * 2)
    );
  }

  /**
   * Create clip path for the shade
   */
  function createClipPath(el: HTMLElement, target: HTMLElement, container: HTMLElement, heightOffset: number = 0): void {
    // In the layer's pixels, which are the shade's own.
    const rect = calculateRect(target.getBoundingClientRect());
    const containerRect = calculateRect(container.getBoundingClientRect());
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
