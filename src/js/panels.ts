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
      const id = Object.keys(mounted)[0];
      if (!id) {
        // Nothing mounted holds a version; the first get() or set() reads one.
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
