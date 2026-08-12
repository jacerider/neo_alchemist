/**
 * A Miller-column browser for picking an entity field to source a prop from.
 *
 * The matcher's flat list is the cartesian view of a tree that is small at
 * every node — on a mid-sized site roughly 56 rows at the target entity, ~37
 * one reference hop in, ~30 two hops in. Flattening it is what made it
 * unusable, not its size, so this walks the tree instead: one column per hop,
 * fields on the left of each column and the references you can descend into
 * below them.
 *
 * Search is still there, and takes over the panel while you type. Browsing is
 * the default because it answers the question search cannot: what is even
 * available, and which fields are doorways into another entity.
 */
(function (Drupal, once) {

  interface Leaf { value: string; label: string; tier?: number; }
  interface Ref { path: string; segment: string; label: string; target: string; }
  interface Crumb { path: string; label: string; entity: string; }
  interface Pane { path: string; entity: string; crumbs: Crumb[]; leaves: Leaf[]; refs: Ref[]; }
  interface Hit { value: string; label: string; path: string; }

  const t = (s: string) => Drupal.t(s);

  // neo_modal's option type demands every one of its ~160 defaults; the API
  // itself merges over them, so reach for it untyped rather than restating it.
  const modal = () => (Drupal as any).neoModal;

  /**
   * Builds and drives one modal session.
   */
  class FieldBrowser {
    private input: HTMLInputElement;
    private trigger: HTMLElement;
    private browseUrl: string;
    private searchUrl: string;
    private root: HTMLElement;
    private crumbBar: HTMLElement;
    private columns: HTMLElement;
    private status: HTMLElement;
    private useButton: HTMLButtonElement;
    private selected: { value: string; label: string; path: string } | null = null;
    private searchTimer: number | undefined;
    private extra: Record<string, string> = {};

    constructor(input: HTMLInputElement, trigger: HTMLElement) {
      this.input = input;
      this.trigger = trigger;
      this.browseUrl = input.dataset.neoFieldBrowse || '';
      this.searchUrl = input.dataset.neoFieldSearch || '';
      try {
        this.extra = JSON.parse(input.dataset.neoFieldExtra || '{}');
      }
      catch (e) {
        this.extra = {};
      }
      this.root = document.createElement('div');
      this.crumbBar = document.createElement('div');
      this.columns = document.createElement('div');
      this.status = document.createElement('div');
      this.useButton = document.createElement('button');
    }

    open(): void {
      if (!this.root.childElementCount) {
        this.build();
      }
      this.reset();
      // NeoModal takes an HTML string, or a callback returning an element —
      // but only consults the callback when `trigger` is set, and its
      // raw-element branch assigns the content and then discards it. The
      // callback form is the one that keeps a live element, which this needs:
      // the panel rebuilds itself from fetches after the modal has opened.
      modal().open({
        title: t('Choose a field'),
        trigger: this.trigger,
        content: () => this.root,
        width: '64rem',
        headerInContent: true,
        contentScroll: false,
      });
      this.loadPane('');
    }

    /**
     * Returns the panel to its opening state.
     *
     * The panel is built once per input and reopened, rather than rebuilt: the
     * modal moves the element rather than cloning it, and a fresh panel per
     * open left the previous one orphaned in the document — where its columns
     * still answered document-wide selectors.
     */
    private reset(): void {
      const search = this.root.querySelector('.neo-field-browser--search') as HTMLInputElement | null;
      if (search) {
        search.value = '';
      }
      this.columns.innerHTML = '';
      this.crumbBar.innerHTML = '';
      this.selected = null;
      this.status.textContent = t('No field selected');
      this.useButton.disabled = true;
      const current = this.root.querySelector(`.neo-field-browser--extra[data-value="${CSS.escape(this.input.value)}"]`);
      if (current) {
        this.markSelected(current as HTMLElement, this.extra[this.input.value], t('Special'));
      }
    }

    private build(): void {
      this.root.className = 'neo-field-browser flex flex-col gap-3';

      const search = document.createElement('input');
      search.type = 'search';
      search.className = 'neo-field-browser--search form-item-bg form-item-border form-item-border-radius w-full py-2 px-3';
      search.placeholder = t('Search every field, or browse below…');
      search.addEventListener('input', () => {
        window.clearTimeout(this.searchTimer);
        const query = search.value.trim();
        // Debounced so a fast typist does not queue a request per keystroke;
        // the endpoint re-ranks the whole match list on each call.
        this.searchTimer = window.setTimeout(() => {
          if (query === '') {
            this.loadPane('');
          }
          else {
            this.runSearch(query);
          }
        }, 200);
      });
      this.root.append(search);

      this.crumbBar.className = 'neo-field-browser--crumbs flex items-center gap-1 text-sm opacity-70 min-h-6 flex-wrap';
      this.root.append(this.crumbBar);

      // Synthetic choices are not part of the entity tree, so they sit above
      // it rather than inside a column — a provider offering "Use Default" or
      // "Render with field formatter" is offering something the tree cannot
      // express, and burying it in a column would imply otherwise.
      const extras = document.createElement('div');
      extras.className = 'neo-field-browser--extras flex flex-wrap gap-2';
      Object.entries(this.extra).forEach(([value, label]) => {
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'neo-field-browser--extra form-item-border form-item-border-radius px-3 py-1 text-sm';
        row.dataset.value = value;
        row.textContent = label;
        row.addEventListener('click', () => this.markSelected(row, label, t('Special')));
        row.addEventListener('dblclick', () => {
          this.markSelected(row, label, t('Special'));
          this.commit();
        });
        extras.append(row);
      });
      if (extras.childElementCount) {
        this.root.append(extras);
      }

      this.columns.className = 'neo-field-browser--columns flex gap-2 overflow-x-auto items-stretch min-h-80 max-h-[60vh]';
      this.root.append(this.columns);

      const footer = document.createElement('div');
      footer.className = 'neo-field-browser--footer flex items-center justify-between gap-3 border-t pt-3';
      this.status.className = 'text-sm opacity-70 truncate';
      this.status.textContent = t('No field selected');
      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'btn btn-sm';
      cancel.textContent = t('Cancel');
      cancel.addEventListener('click', () => modal().close());
      this.useButton.type = 'button';
      this.useButton.className = 'btn btn-sm btn-primary';
      this.useButton.textContent = t('Use field');
      this.useButton.disabled = true;
      this.useButton.addEventListener('click', () => this.commit());
      const actions = document.createElement('div');
      actions.className = 'flex items-center gap-2 shrink-0';
      actions.append(cancel, this.useButton);
      footer.append(this.status, actions);
      this.root.append(footer);
    }

    /**
     * Fetches and renders the pane for a reference path.
     */
    private loadPane(path: string, keepThrough?: number): void {
      const url = this.browseUrl + (this.browseUrl.includes('?') ? '&' : '?') + 'path=' + encodeURIComponent(path);
      fetch(url, { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then((pane: Pane) => {
          if (keepThrough === undefined) {
            this.columns.innerHTML = '';
          }
          else {
            // Descending: drop the panes to the right of the one clicked, the
            // way a column browser collapses a branch you have navigated away
            // from.
            while (this.columns.children.length > keepThrough + 1) {
              this.columns.lastElementChild?.remove();
            }
          }
          this.renderCrumbs(pane.crumbs);
          this.columns.append(this.renderPane(pane));
          this.columns.scrollLeft = this.columns.scrollWidth;
        })
        .catch(() => this.fail());
    }

    private renderCrumbs(crumbs: Crumb[]): void {
      this.crumbBar.innerHTML = '';
      crumbs.forEach((crumb, i) => {
        if (i > 0) {
          const sep = document.createElement('span');
          sep.textContent = '›';
          sep.className = 'opacity-50';
          this.crumbBar.append(sep);
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'hover:underline';
        btn.textContent = crumb.label + (crumb.entity ? ` (${crumb.entity})` : '');
        btn.addEventListener('click', () => this.loadPane(crumb.path, i > 0 ? i - 1 : undefined));
        this.crumbBar.append(btn);
      });
    }

    private renderPane(pane: Pane): HTMLElement {
      const col = document.createElement('div');
      col.className = 'neo-field-browser--column form-item-border form-item-border-radius flex flex-col shrink-0 w-72 overflow-hidden';
      col.dataset.path = pane.path;

      const head = document.createElement('div');
      head.className = 'shrink-0 px-3 py-2 text-xs uppercase tracking-wide opacity-60 border-b';
      head.textContent = pane.entity;
      col.append(head);

      // The fields scroll; the references stay pinned to the bottom of the
      // column. Letting them flow after 48 fields buries the one thing a
      // column browser exists to show — that this entity has doorways into
      // others — under a scroll nobody knows to perform.
      const leaves = document.createElement('div');
      leaves.className = 'neo-field-browser--leaves grow overflow-y-auto min-h-0';
      // Fields are ranked content → system plumbing → link templates, and
      // alphabetical within each. Run together that is three A-Z sequences in
      // a row, which reads as no order at all. Mark where each begins; the
      // first needs no heading, the column header already names the entity.
      let tier: number | undefined;
      pane.leaves.forEach(leaf => {
        const leafTier = leaf.tier ?? 0;
        if (tier !== undefined && leafTier !== tier) {
          leaves.append(sectionHeading(TIER_LABELS[leafTier] ?? ''));
        }
        tier = leafTier;
        leaves.append(this.leafRow(leaf, pane));
      });
      col.append(leaves);

      if (pane.refs.length) {
        const refs = document.createElement('div');
        refs.className = 'neo-field-browser--refs shrink-0 border-t overflow-y-auto max-h-44';
        const sep = sectionHeading(t('Follow a reference'));
        // Sticky, so it must be genuinely opaque: `form-item-bg` is the real
        // utility (`bg-form-item-base` resolved to an undefined --color-* var),
        // the mt-1 margin is a strip the background never covers, and z-10
        // keeps the rows scrolling under it rather than over.
        sep.classList.remove('mt-1');
        sep.classList.add('sticky', 'top-0', 'z-10', 'form-item-bg');
        refs.append(sep);
        pane.refs.forEach(ref => refs.append(this.refRow(ref, col)));
        col.append(refs);
      }

      if (!pane.leaves.length && !pane.refs.length) {
        const none = document.createElement('div');
        none.className = 'px-3 py-4 text-sm opacity-60';
        none.textContent = t('Nothing here can supply this property.');
        col.append(none);
      }
      return col;
    }

    private leafRow(leaf: Leaf, pane: Pane): HTMLElement {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'neo-field-browser--leaf block w-full text-left px-3 py-2 text-sm hover:bg-primary-500 hover:text-primary-500-content cursor-pointer';
      row.textContent = leaf.label;
      row.dataset.value = leaf.value;
      if (leaf.value === this.input.value) {
        this.markSelected(row, leaf.label, pane.entity);
      }
      row.addEventListener('click', () => this.markSelected(row, leaf.label, pane.entity));
      row.addEventListener('dblclick', () => {
        this.markSelected(row, leaf.label, pane.entity);
        this.commit();
      });
      return row;
    }

    private refRow(ref: Ref, col: HTMLElement): HTMLElement {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'neo-field-browser--ref flex items-center justify-between gap-2 w-full text-left px-3 py-2 text-sm hover:bg-primary-500 hover:text-primary-500-content';
      const text = document.createElement('span');
      text.className = 'truncate';
      text.innerHTML = `<span>${escapeHtml(ref.label)}</span> <span class="opacity-60">→ ${escapeHtml(ref.target)}</span>`;
      const chev = document.createElement('span');
      chev.className = 'opacity-60 shrink-0';
      chev.textContent = '›';
      row.append(text, chev);
      row.addEventListener('click', () => {
        col.querySelectorAll('.neo-field-browser--ref').forEach(el => el.classList.remove('is-open', 'bg-base-500/10'));
        row.classList.add('is-open', 'bg-base-500/10');
        const index = [...this.columns.children].indexOf(col);
        this.loadPane(ref.path, index);
      });
      return row;
    }

    private runSearch(query: string): void {
      const url = this.searchUrl + (this.searchUrl.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);
      fetch(url, { credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then((hits: Hit[]) => {
          this.crumbBar.innerHTML = '';
          const crumb = document.createElement('span');
          crumb.textContent = Drupal.formatPlural(hits.length, '1 match', '@count matches');
          this.crumbBar.append(crumb);
          this.columns.innerHTML = '';
          const col = document.createElement('div');
          col.className = 'neo-field-browser--column form-item-border form-item-border-radius overflow-y-auto w-full';
          if (!hits.length) {
            const none = document.createElement('div');
            none.className = 'px-3 py-4 text-sm opacity-60';
            none.textContent = t('No fields match');
            col.append(none);
          }
          hits.forEach(hit => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'block w-full text-left px-3 py-2 text-sm hover:bg-primary-500 hover:text-primary-500-content';
            row.innerHTML = `<div class="truncate">${escapeHtml(hit.label)}</div><div class="text-xs opacity-60 truncate">${escapeHtml(hit.path)}</div>`;
            row.addEventListener('click', () => this.markSelected(row, hit.label, hit.path));
            row.addEventListener('dblclick', () => {
              this.markSelected(row, hit.label, hit.path);
              this.commit();
            });
            row.dataset.value = hit.value;
            col.append(row);
          });
          this.columns.append(col);
        })
        .catch(() => this.fail());
    }

    private markSelected(row: HTMLElement, label: string, path: string): void {
      this.root.querySelectorAll('[data-value]').forEach(el => el.classList.remove('is-selected', 'bg-primary-500', 'text-primary-500-content'));
      row.classList.add('is-selected', 'bg-primary-500', 'text-primary-500-content');
      this.selected = { value: row.dataset.value || '', label, path };
      this.status.textContent = `${label} · ${path}`;
      this.useButton.disabled = false;
    }

    private commit(): void {
      if (!this.selected) {
        return;
      }
      this.input.value = this.selected.value;
      this.input.dataset.neoFieldLabel = this.selected.label;
      this.input.dataset.neoFieldPath = this.selected.path;
      delete this.input.dataset.neoFieldMissing;
      renderTrigger(this.input, this.trigger);
      // Let Drupal's #ajax (and anything else listening) see the change.
      this.input.dispatchEvent(new Event('change', { bubbles: true }));
      modal().close();
    }

    private fail(): void {
      this.columns.innerHTML = '';
      const err = document.createElement('div');
      err.className = 'px-3 py-4 text-sm text-alert-500';
      err.textContent = t('Could not load fields. Reload the page and try again.');
      this.columns.append(err);
    }
  }

  const TIER_LABELS: Record<number, string> = {
    1: t('System'),
    2: t('Link templates'),
  };

  /**
   * A divider naming the group of rows that follows it.
   *
   * The dimming sits on an inner span, not the element: element-level opacity
   * dims the background too, and the refs heading is sticky — a 60%-opaque
   * background lets the rows scrolling beneath show through the label.
   */
  function sectionHeading(label: string): HTMLElement {
    const el = document.createElement('div');
    el.className = 'neo-field-browser--heading px-3 py-2 mt-1 border-t text-xs uppercase tracking-wide';
    const text = document.createElement('span');
    text.className = 'opacity-60';
    text.textContent = label;
    el.append(text);
    return el;
  }

  function escapeHtml(value: string): string {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
  }

  /**
   * Paints the inline control from the input's current value.
   */
  function renderTrigger(input: HTMLInputElement, trigger: HTMLElement): void {
    const label = input.dataset.neoFieldLabel || '';
    const path = input.dataset.neoFieldPath || '';
    const missing = input.dataset.neoFieldMissing === 'true';
    if (!input.value) {
      trigger.innerHTML = `<span class="opacity-60">${escapeHtml(input.dataset.neoFieldEmpty || Drupal.t('Choose a field…'))}</span>`;
      return;
    }
    trigger.innerHTML =
      `<span class="block truncate">${escapeHtml(label)}` +
      (missing ? ` <span class="text-alert-500">(${Drupal.t('missing')})</span>` : '') +
      `</span><span class="block text-xs opacity-60 truncate">${escapeHtml(path)}</span>`;
  }

  Drupal.behaviors.neoFieldBrowser = {
    attach: (context: HTMLElement | Document) => {
      once('neo-field-browser', 'input.neo-field-select', context as HTMLElement).forEach(el => {
        const input = el as HTMLInputElement;
        input.type = 'hidden';

        const wrapper = document.createElement('div');
        wrapper.className = 'neo-field-select--control flex items-stretch gap-1';

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'neo-field-select--trigger form-item-bg form-item-border form-item-border-radius grow text-left py-2 px-3 min-w-0';
        let browser: FieldBrowser | null = null;
        trigger.addEventListener('click', () => {
          browser = browser || new FieldBrowser(input, trigger);
          browser.open();
        });

        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'neo-field-select--clear btn btn-secondary px-3 shrink-0';
        clear.title = Drupal.t('Clear');
        clear.textContent = '⨯';
        clear.addEventListener('click', () => {
          input.value = '';
          delete input.dataset.neoFieldLabel;
          delete input.dataset.neoFieldPath;
          delete input.dataset.neoFieldMissing;
          renderTrigger(input, trigger);
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        input.parentNode?.insertBefore(wrapper, input);
        wrapper.append(trigger, clear);
        wrapper.append(input);
        renderTrigger(input, trigger);
      });
    },
  };

})(Drupal, once);
