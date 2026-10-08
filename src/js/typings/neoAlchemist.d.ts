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
  /** Called after every open and close. */
  onToggle(callback: (open: boolean) => void): void;
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
    };
  }
}
