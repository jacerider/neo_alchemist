/**
 * The component preview's audit badge.
 *
 * Asks the component audit (neo_alchemist.sdc_preview_audit) what it finds
 * in the component as the preview shows it, and again after every preview
 * reload — so a prop edited in the form is re-checked. Shows nothing while
 * the component is clean; otherwise "N issues" in the top bar, opening the
 * list. An issue in the render the preview shows has Show, which outlines the
 * element in the preview; one found only without the component's headings
 * cannot be shown there and says so.
 */
(function (Drupal, drupalSettings): void {

  interface Locator {
    tag: string;
    index: number;
    text: string;
  }

  interface Issue {
    check: string;
    variant: 'examples' | 'without_headings';
    message: string;
    locator: Locator | null;
  }

  const settings: { url?: string } = drupalSettings.neoAlchemist?.sdcAudit ?? {};
  let badge: HTMLElement | null = null;
  let button: HTMLButtonElement | null = null;
  let list: HTMLElement | null = null;
  let timer: number | undefined;
  let run = 0;

  function frames(): HTMLIFrameElement[] {
    return Array.from(document.querySelectorAll<HTMLIFrameElement>('.neo-alchemist-manage iframe'));
  }

  /** The preview frame most in view, which Show outlines in. */
  function visibleFrame(): HTMLIFrameElement | null {
    return frames().find((frame) => frame.getBoundingClientRect().height > 0) ?? null;
  }

  function mount(): boolean {
    const topEnd = document.querySelector<HTMLElement>('.neo-alchemist-manage--top-end');
    if (!topEnd) {
      return false;
    }
    button = document.createElement('button');
    button.type = 'button';
    button.className = 'neo-alchemist--audit-badge';
    button.setAttribute('aria-expanded', 'false');
    list = document.createElement('div');
    list.className = 'neo-alchemist--audit-list';
    list.id = 'neo-alchemist-audit-list';
    list.hidden = true;
    button.setAttribute('aria-controls', list.id);
    badge = document.createElement('div');
    badge.className = 'neo-alchemist--audit';
    badge.hidden = true;
    badge.append(button, list);
    button.addEventListener('click', () => toggle(list?.hidden ?? false));
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && list && !list.hidden) {
        toggle(false);
        button?.focus();
      }
    });
    document.addEventListener('pointerdown', (event) => {
      if (badge && list && !list.hidden && !badge.contains(event.target as Node)) {
        toggle(false);
      }
    }, true);
    // First in the corner, ahead of the scale and viewport controls.
    topEnd.insertBefore(badge, topEnd.firstChild);
    return true;
  }

  function toggle(open: boolean): void {
    if (list && button) {
      list.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  }

  async function refresh(): Promise<void> {
    if (!settings.url || !badge || !button || !list) {
      return;
    }
    run += 1;
    const current = run;
    let issues: Issue[] = [];
    try {
      const response = await fetch(settings.url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!response.ok) {
        return;
      }
      issues = ((await response.json()) as { issues: Issue[] }).issues ?? [];
    }
    catch {
      return;
    }
    if (current !== run) {
      return;
    }
    badge.hidden = issues.length === 0;
    if (!issues.length) {
      toggle(false);
      return;
    }
    button.textContent = Drupal.formatPlural(issues.length, '1 issue', '@count issues');
    button.title = Drupal.t('SEO and accessibility issues the component audit finds. The same as drush neo:alchemist:audit.');
    list.replaceChildren(...issues.map(item));
  }

  function item(issue: Issue): HTMLElement {
    const row = document.createElement('div');
    row.className = 'neo-alchemist--audit-item';
    const message = document.createElement('p');
    message.className = 'neo-alchemist--audit-message';
    message.textContent = issue.message;
    const meta = document.createElement('p');
    meta.className = 'neo-alchemist--audit-meta';
    meta.textContent = issue.variant === 'without_headings'
      ? Drupal.t('Without the component’s headings — leave its heading empty in the form to see it.')
      : Drupal.t('In the preview.');
    row.append(message, meta);
    if (issue.variant === 'examples' && issue.locator) {
      const show = document.createElement('button');
      show.type = 'button';
      show.className = 'btn btn-xs btn-outline neo-alchemist--audit-show';
      show.textContent = Drupal.t('Show');
      const locator = issue.locator;
      show.addEventListener('click', () => reveal(locator));
      row.append(show);
    }
    return row;
  }

  /** The element an issue is about, in the preview: by place, then by text. */
  function find(doc: Document, locator: Locator): HTMLElement | null {
    const root = doc.querySelector<HTMLElement>('[data-neo-sdc-preview="component"]') ?? doc.body;
    const candidates = Array.from(root.querySelectorAll<HTMLElement>(locator.tag));
    const text = (element: HTMLElement) => (element.textContent ?? '').replace(/\s+/g, ' ').trim();
    const byIndex = candidates[locator.index];
    if (byIndex && text(byIndex).startsWith(locator.text)) {
      return byIndex;
    }
    return candidates.find((candidate) => text(candidate).startsWith(locator.text)) ?? byIndex ?? null;
  }

  /**
   * Outlines the element and brings it into view by scrolling the page, not
   * the frame: preview frames are sized to their content.
   */
  function reveal(locator: Locator): void {
    const frame = visibleFrame();
    const doc = frame?.contentDocument;
    const element = doc ? find(doc, locator) : null;
    if (!frame || !element) {
      return;
    }
    toggle(false);
    const previous = element.style.outline;
    const previousOffset = element.style.outlineOffset;
    element.style.outline = '3px solid #e11d48';
    element.style.outlineOffset = '-3px';
    window.setTimeout(() => {
      element.style.outline = previous;
      element.style.outlineOffset = previousOffset;
    }, 2500);
    let scroller: HTMLElement | null = frame.parentElement;
    while (scroller && !(scroller.scrollHeight > scroller.clientHeight && /(auto|scroll)/.test(getComputedStyle(scroller).overflowY))) {
      scroller = scroller.parentElement;
    }
    const top = frame.getBoundingClientRect().top + element.getBoundingClientRect().top;
    const target = top - window.innerHeight / 3;
    if (scroller) {
      scroller.scrollBy({ top: target - scroller.getBoundingClientRect().top });
    }
    else {
      window.scrollBy({ top: target });
    }
  }

  function schedule(): void {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => refresh(), 300);
  }

  function watch(frame: HTMLIFrameElement): void {
    if (frame.dataset.neoAlchemistAudit) {
      return;
    }
    frame.dataset.neoAlchemistAudit = 'true';
    frame.addEventListener('load', schedule);
  }

  Drupal.behaviors.neoAlchemistSdcAudit = {
    attach() {
      if (!settings.url) {
        return;
      }
      if (!badge && !mount()) {
        return;
      }
      frames().forEach(watch);
      schedule();
    },
  };

})(Drupal, drupalSettings);
