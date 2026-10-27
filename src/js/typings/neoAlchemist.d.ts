/**
 * What an editor side panel's draft key holds, and where the draft stands.
 */
interface NeoAlchemistPanelState {
  data: unknown;
  version: number;
  hasDraft: boolean;
}

/**
 * Passed to onDraftChange() listeners.
 *
 * `panel` — a panel wrote its key (`panelId` names which; it may be another
 * panel). `layout` — the previews refreshed after a layout write, so the draft
 * moved on under the panel. Either way `version` is already current for the
 * next set(); call get() for data.
 */
interface NeoAlchemistPanelDraftChange {
  reason: 'panel' | 'layout';
  panelId?: string;
  version: number;
  hasDraft: boolean;
}

/**
 * The handle a panel's mount() receives onto its key of the shared draft.
 */
interface NeoAlchemistPanelApi {
  /** Reads the panel's drafted data (NULL when it has none). */
  get(): Promise<NeoAlchemistPanelState>;
  /**
   * Writes the panel's drafted data; NULL removes it. Rejects when a colleague
   * moved the draft on (the message is shown in the panel too).
   */
  set(data: unknown): Promise<{ version: number; hasDraft: boolean }>;
  /** Subscribes to draft changes; returns an unsubscribe function. */
  onDraftChange(callback: (change: NeoAlchemistPanelDraftChange) => void): () => void;
}

/**
 * The dropdown the editor anchors under a panel's top bar badge.
 */
/** A badge dropdown's width: 384px (the default), 520px or 760px. */
type NeoAlchemistDropdownSize = 'md' | 'lg' | 'xl';

interface NeoAlchemistDropdown {
  /** The dropdown's body: the badge fills it. */
  readonly element: HTMLElement;
  /** Opens it and moves focus in: to `focus` when given, else its first visible field, else itself. */
  open(focus?: HTMLElement | null): void;
  /** Closes it; focus returns to the badge button that opened it. */
  close(): void;
  isOpen(): boolean;
  /**
   * Sets its width, here or while open. It is centred under the badge and
   * capped at the room it has: the window, less any side panel floating there.
   */
  setSize(size: NeoAlchemistDropdownSize): void;
  /**
   * Called after every open and close, and when another button of the badge
   * is clicked while open (it stays open), with the button that opened it.
   */
  onToggle(callback: (open: boolean, trigger: HTMLElement | null) => void): void;
}

/**
 * Something in a component's preview to find the field for: an element of
 * `tag` (any when omitted) whose text contains `text`, whose `src` ends with
 * `src`, or whose `href` is `href`. The component's edit preview resolves it
 * through the same prop map its click-to-field uses.
 */
interface NeoAlchemistLocate {
  tag?: string;
  text?: string;
  src?: string;
  href?: string;
}

/**
 * What Drupal.neoAlchemist.editor.focus() goes to.
 */
interface NeoAlchemistFocusTarget {
  /** The component instance (its data-component-uuid on the canvas). */
  uuid: string;
  /** A shape id (data-neo-prop) to focus in the component's edit form. */
  prop?: string;
  /** Or: what to find in the component's preview, to focus its field. */
  locate?: NeoAlchemistLocate;
}

/**
 * The layout editor's public calls.
 */
interface NeoAlchemistEditorApi {
  /**
   * Selects the component on the canvas, which opens it in the inspector.
   * With `prop` or `locate` it also focuses that field (the form stays open
   * on the component when nothing is found). False, doing nothing, for a uuid
   * the canvas does not show.
   */
  focus(target: NeoAlchemistFocusTarget): boolean;
  /** Edit-form internal: claims a focus request made in the last moments. */
  takeFieldFocus?(): Pick<NeoAlchemistFocusTarget, 'prop' | 'locate'> | null;
}

/**
 * What a module registers for its panel.
 */
interface NeoAlchemistPanelDefinition {
  /** Called once, on the panel's first open, with its body element. */
  mount(element: HTMLElement, api: NeoAlchemistPanelApi): void;
  /** Called when the editor page goes away. */
  unmount?(): void;
  /**
   * Called at editor load when the panel's plugin rendered a badge(), with the
   * badge element, the same draft api as mount(), and the badge's dropdown.
   */
  badge?(element: HTMLElement, api: NeoAlchemistPanelApi, dropdown: NeoAlchemistDropdown): void;
}

interface NeoAlchemistPanelRegistry {
  /** Registers a panel's client half under its AlchemistPanel plugin id. */
  register(id: string, definition: NeoAlchemistPanelDefinition): void;
  /** Editor-internal: mounts a panel into its body element. */
  mount(id: string, element: HTMLElement): void;
  /** Editor-internal: the previews refreshed after a layout write. */
  layoutChanged(): void;
  /**
   * A top bar dropdown under a badge that is not a panel's, placed and
   * behaving as a panel badge's does. The id names its element; the label is
   * its accessible name.
   */
  dropdown(badge: HTMLElement, id: string, label: string): NeoAlchemistDropdown;
}

/**
 * A preview frame's size.
 */
type NeoAlchemistFrameSize = 'desktop' | 'tablet' | 'mobile';

/**
 * Where a preview frame is. See frameState() in component-parent.ts.
 */
type NeoAlchemistFrameState = 'unloaded' | 'loading' | 'ready' | 'stale';

/**
 * A canvas's preview frames, as both editors see them.
 *
 * Every call takes the canvas (`.neo-alchemist-manage`) to answer for, and
 * falls back to the page's first one. A page can hold two: the component edit
 * form opens in a modal over the layout editor with a canvas of its own.
 */
interface NeoAlchemistFrames {
  /** The frame for a size, or null when the canvas has none. */
  get(size: NeoAlchemistFrameSize, editor?: Element | null): HTMLIFrameElement | null;
  /** Where that frame is. */
  state(size: NeoAlchemistFrameSize, editor?: Element | null): NeoAlchemistFrameState;
  /** The sizes the canvas is using: the frames it loads, waits on and outlines. */
  active(editor?: Element | null): NeoAlchemistFrameSize[];
  /**
   * The sizes on screen: all of them in Compare, the shown one in Single. The
   * canvas dispatches `alchemistManageView` on itself once this has changed
   * and the frames have moved.
   */
  visible(editor?: Element | null): NeoAlchemistFrameSize[];
}

declare namespace drupal {

  export interface IDrupalStatic {
    neoAlchemist?: {
      iframes?: Record<'desktop' | 'tablet' | 'mobile', HTMLIFrameElement | null>
      frames?: NeoAlchemistFrames;
      /** Brings stale frames up to the current render (component-ajax.ts). */
      refreshStale?: (iframes: HTMLIFrameElement[]) => void;
      /** Re-renders frames in place, with optional query arguments (component-ajax.ts). */
      refreshFrames?: (iframes: HTMLIFrameElement[], query?: Record<string, string>) => void;
      panels?: NeoAlchemistPanelRegistry;
      editor?: NeoAlchemistEditorApi;
      /** The query a render for the inspector's component needs, else {} (components-parent.ts). */
      inspecting?: () => Record<string, string>;
      /** A short message at the top of the editor that fades by itself. */
      toast?: (text: string, type?: 'status' | 'error') => void;
    };
  }
}
