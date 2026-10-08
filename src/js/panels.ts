/**
 * The client half of the editor side panel API.
 *
 * A module that adds an AlchemistPanel plugin registers its client here:
 *
 *   Drupal.neoAlchemist.panels.register('my_panel', {
 *     mount(element, api) { ... },
 *     unmount() { ... },
 *   });
 *
 * mount() runs once, the first time the panel is opened, with the panel's body
 * element (where the plugin's build() rendered) and an api onto the panel's key
 * in the shared layout draft. Registration order does not matter: a panel
 * opened before its script registers is mounted when it does.
 *
 * badge() runs at registration — editor load — when the plugin rendered a
 * badge in the top bar, with the same api and a dropdown anchored under the
 * badge (see dropdownCreate()). onDraftChange() listeners hear every change
 * whether or not their panel was ever opened.
 *
 * The draft is versioned as a whole, so this file carries one version for every
 * panel on the page, refreshes it after each write and after the previews
 * reload following a layout write, and sends it with each set() — a stale
 * write comes back 409 and its message is shown in the panel.
 */
(function (Drupal, drupalSettings) {

  interface PanelSettings {
    label: string;
    url: string;
  }

  const settings: Record<string, PanelSettings> = drupalSettings.neoAlchemist?.panels || {};
  const definitions: Record<string, NeoAlchemistPanelDefinition> = {};
  const mounted: Record<string, HTMLElement> = {};
  const pending: Record<string, HTMLElement> = {};
  const listeners = new Set<(change: NeoAlchemistPanelDraftChange) => void>();
  let version: number | null = null;
  let csrfToken: Promise<string> | null = null;

  function token(): Promise<string> {
    csrfToken ??= fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
      .then(response => response.text());
    return csrfToken;
  }

  /**
   * The panel's message strip — where a refused write is explained.
   */
  function messageShow(id: string, html: string | null): void {
    const message = mounted[id]?.closest('.neo-alchemist--sidepanel')
      ?.querySelector<HTMLElement>('.neo-alchemist--sidepanel-message');
    if (!message) {
      return;
    }
    message.innerHTML = html ?? '';
    message.hidden = html === null;
  }

  /**
   * Swap in the re-rendered top-start toolbar, so Save/Revert follow hasDraft.
   */
  function operationsReplace(html: string): void {
    const target = document.querySelector<HTMLElement>('.neo-alchemist-manage .neo-alchemist-manage--top-start');
    if (!target) {
      return;
    }
    Drupal.detachBehaviors(target, drupalSettings, 'unload');
    target.innerHTML = html;
    Drupal.attachBehaviors(target, drupalSettings);
  }

  function notify(change: NeoAlchemistPanelDraftChange): void {
    listeners.forEach(listener => {
      try {
        listener(change);
      }
      catch (e) {
        // One panel's listener must not stop the others hearing the change.
        console.error(e);
      }
    });
  }

  async function read(id: string): Promise<NeoAlchemistPanelState> {
    const response = await fetch(settings[id].url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
      throw new Error(`The ${settings[id].label} panel could not be read (${response.status}).`);
    }
    const state = await response.json() as NeoAlchemistPanelState;
    version = state.version;
    return state;
  }

  async function write(id: string, data: unknown): Promise<{ version: number; hasDraft: boolean }> {
    if (version === null) {
      await read(id);
    }
    const response = await fetch(settings[id].url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': await token(),
      },
      body: JSON.stringify({ data: data === undefined ? null : data, version }),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      // A conflict keeps the version this session loaded: the message asks for
      // a reload, and quietly adopting the newer version would let the next
      // write overwrite whatever the colleague did.
      const message = body.message || Drupal.t('The @label panel could not be saved (@status).', {
        '@label': settings[id].label,
        '@status': String(response.status),
      });
      messageShow(id, message);
      const error = new Error(message.replace(/<[^>]*>/g, '')) as Error & { status?: number };
      error.status = response.status;
      throw error;
    }
    version = body.version;
    messageShow(id, null);
    if (typeof body.operations === 'string') {
      operationsReplace(body.operations);
    }
    const result = { version: body.version as number, hasDraft: !!body.hasDraft };
    notify({ reason: 'panel', panelId: id, ...result });
    return result;
  }

  function api(id: string): NeoAlchemistPanelApi {
    return {
      get: () => read(id),
      set: (data: unknown) => write(id, data),
      onDraftChange(callback) {
        listeners.add(callback);
        return () => listeners.delete(callback);
      },
    };
  }

  /**
   * The dropdown under a badge: every <button> in the badge toggles it, Esc
   * and a click anywhere else (a preview frame included) close it, and focus
   * moves in on open and back to the opening button on close. Clicking a
   * different button of the badge while it is open keeps it open and reports
   * that button to onToggle() — a badge with two parts shows the part clicked.
   */
  function dropdownCreate(badge: HTMLElement, id: string): NeoAlchemistDropdown {
    const element = document.createElement('div');
    element.id = `neo-alchemist-dropdown-${id}`;
    element.className = 'neo-alchemist--dropdown absolute right-0 top-full mt-2 w-96 max-w-[calc(100vw-2rem)] max-h-[70vh] overflow-y-auto rounded-lg shadow-lg border bg-base-0 text-base-0-content text-sm z-50';
    element.setAttribute('role', 'dialog');
    element.setAttribute('aria-label', settings[id]?.label ?? id);
    element.hidden = true;
    badge.append(element);
    const callbacks: Array<(open: boolean, trigger: HTMLElement | null) => void> = [];
    let opener: HTMLElement | null = null;
    // The badge button a click is opening the dropdown from, if any.
    let clicked: HTMLElement | null = null;

    const triggers = () => Array.from(badge.querySelectorAll<HTMLElement>('button'))
      .filter(button => !element.contains(button));
    const sync = (open: boolean) => {
      triggers().forEach(button => {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.setAttribute('aria-controls', element.id);
      });
      callbacks.forEach(callback => {
        try {
          callback(open, opener);
        }
        catch (e) {
          console.error(e);
        }
      });
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        dropdown.close();
      }
    };
    const onPointer = (event: PointerEvent) => {
      if (!badge.contains(event.target as Node)) {
        dropdown.close();
      }
    };
    // A click into a preview frame never reaches this document; it blurs it.
    const onBlur = () => {
      window.setTimeout(() => {
        if (document.activeElement instanceof HTMLIFrameElement) {
          dropdown.close();
        }
      });
    };

    const dropdown: NeoAlchemistDropdown = {
      element,
      open(focus) {
        if (!element.hidden) {
          (focus ?? null)?.focus();
          return;
        }
        opener = clicked
          ?? (document.activeElement instanceof HTMLElement && badge.contains(document.activeElement)
            ? document.activeElement
            : triggers()[0] ?? null);
        clicked = null;
        element.hidden = false;
        document.addEventListener('keydown', onKey, true);
        document.addEventListener('pointerdown', onPointer, true);
        window.addEventListener('blur', onBlur);
        sync(true);
        const target = focus ?? element.querySelector<HTMLElement>('input, select, textarea, button, a[href], [tabindex]:not([tabindex="-1"])');
        target?.focus();
      },
      close() {
        if (element.hidden) {
          return;
        }
        const returnFocus = element.contains(document.activeElement);
        element.hidden = true;
        document.removeEventListener('keydown', onKey, true);
        document.removeEventListener('pointerdown', onPointer, true);
        window.removeEventListener('blur', onBlur);
        sync(false);
        if (returnFocus) {
          opener?.focus();
        }
      },
      isOpen: () => !element.hidden,
      setTrigger(button) {
        // The badge says which of its buttons the open dropdown now belongs
        // to, so clicking that one closes it and the others switch to theirs.
        if (badge.contains(button)) {
          opener = button;
        }
      },
      onToggle(callback) {
        callbacks.push(callback);
      },
    };

    badge.addEventListener('click', (event) => {
      const button = (event.target as Element).closest('button');
      if (!button || element.contains(button)) {
        return;
      }
      event.preventDefault();
      if (!dropdown.isOpen()) {
        clicked = button;
        dropdown.open();
      }
      else if (button === opener) {
        dropdown.close();
      }
      else {
        // Another button of the same badge: the dropdown stays open and
        // follows it, so a badge can show that button's side of it.
        opener = button;
        sync(true);
      }
    });
    sync(false);
    return dropdown;
  }

  const badged: Record<string, true> = {};

  /**
   * Runs a panel's badge() once, when both it and its badge element exist.
   */
  function badgeNow(id: string): void {
    const definition = definitions[id];
    const badge = document.querySelector<HTMLElement>(`[data-alchemist-badge="${id}"]`);
    if (badged[id] || !definition?.badge || !badge || !settings[id]) {
      return;
    }
    badged[id] = true;
    try {
      definition.badge(badge, api(id), dropdownCreate(badge, id));
    }
    catch (e) {
      console.error(e);
    }
  }

  function mountNow(id: string, element: HTMLElement): void {
    mounted[id] = element;
    delete pending[id];
    try {
      definitions[id].mount(element, api(id));
    }
    catch (e) {
      console.error(e);
    }
  }

  const registry: NeoAlchemistPanelRegistry = {
    register(id, definition) {
      definitions[id] = definition;
      if (pending[id]) {
        mountNow(id, pending[id]);
      }
      if (definition.badge) {
        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', () => badgeNow(id), { once: true });
        }
        else {
          badgeNow(id);
        }
      }
    },

    mount(id, element) {
      if (mounted[id] || !settings[id]) {
        return;
      }
      if (definitions[id]) {
        mountNow(id, element);
      }
      else {
        pending[id] = element;
      }
    },

    layoutChanged() {
      // Listeners hear the change whether or not their panel was ever opened:
      // a badge keeps its top bar summary current from editor load.
      const id = Object.keys(mounted)[0] ?? (listeners.size ? Object.keys(settings)[0] : undefined);
      if (!id) {
        // Nothing listens or holds a version; the first get() or set() reads one.
        version = null;
        return;
      }
      read(id)
        .then(state => notify({ reason: 'layout', version: state.version, hasDraft: state.hasDraft }))
        .catch(e => console.error(e));
    },
  };

  window.addEventListener('pagehide', () => {
    Object.keys(mounted).forEach(id => {
      try {
        definitions[id]?.unmount?.();
      }
      catch (e) {
        console.error(e);
      }
    });
  });

  Drupal.neoAlchemist = Drupal.neoAlchemist || {};
  Drupal.neoAlchemist.panels = registry;

})(Drupal, drupalSettings);
