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
interface NeoAlchemistDropdown {
  /** The dropdown's body: the badge fills it. */
  readonly element: HTMLElement;
  /** Opens it and moves focus in: to `focus` when given, else its first control. */
  open(focus?: HTMLElement | null): void;
  /** Closes it; focus returns to the badge button that opened it. */
  close(): void;
  isOpen(): boolean;
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
   * Selects the component on the canvas. With `prop` or `locate` it also
   * opens the component's edit form and focuses that field (the form stays
   * open on the component when nothing is found). False, doing nothing, for a
   * uuid the canvas does not show.
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
}

declare namespace drupal {

  export interface IDrupalStatic {
    neoAlchemist?: {
      iframes?: Record<'desktop' | 'tablet' | 'mobile', HTMLIFrameElement | null>
      panels?: NeoAlchemistPanelRegistry;
      editor?: NeoAlchemistEditorApi;
    };
  }
}
