/**
 * Neo Swap — fetch-and-swap result updates for designed filter UIs.
 *
 * Components whose filters are views_filter props render option links, chip
 * links and GET mini-forms whose URLs are real, shareable filter states. This
 * behavior upgrades those interactions in place: fetch the destination URL (a
 * normal full page render), extract the same component's subtree by its
 * data-neo-uuid, swap it, push the URL into history. JS-off — or any fetch
 * problem — falls back to exactly the navigation that would have happened
 * anyway; the server output is byte-identical either way.
 *
 * Opt-in per component:
 *  - root element: data-neo-uuid="{{ neoUuid }}" (Alchemist provides the
 *    value) plus data-neo-swap;
 *  - component.yml: libraryOverrides: dependencies: [neo_alchemist/swap].
 *
 * Interception is automatic from there. A same-origin link whose PATH equals
 * the current path is by definition a filter/chip/pager interaction — it only
 * changes the query string — while card links lead elsewhere and fall through
 * untouched. GET forms whose action resolves to the current path (the
 * checkbox/search mini-forms) are intercepted the same way. Opt any control
 * out with data-no-swap.
 *
 * After a swap, Drupal behaviors are detached from the old subtree and
 * attached to the new one (component JS re-initializes), and Alpine picks the
 * new tree up through its own MutationObserver. Focus is restored to the
 * same-named control — a visitor typing in the search box keeps their caret.
 * An element marked data-neo-swap-announce (the "12 results" counter) feeds a
 * polite live region so the update is announced to screen readers.
 */
(function (Drupal, once, drupalSettings) {

  const BOUNDARY = '[data-neo-swap][data-neo-uuid]';

  let controller: AbortController | null = null;
  let liveRegion: HTMLElement | null = null;
  let historyInstalled = false;

  /**
   * The polite live region announcing swaps, created on first use.
   */
  const getLiveRegion = (): HTMLElement => {
    if (!liveRegion) {
      liveRegion = document.createElement('div');
      liveRegion.setAttribute('aria-live', 'polite');
      liveRegion.setAttribute('class', 'visually-hidden');
      document.body.appendChild(liveRegion);
    }
    return liveRegion;
  };

  const getUuid = (boundary: Element): string =>
    boundary.getAttribute('data-neo-uuid') || '';

  /**
   * Ensures the current history entry carries swap state before the first
   * push, so Back to the landing state also swaps instead of doing nothing.
   */
  const installHistory = (uuid: string): void => {
    if (!historyInstalled) {
      historyInstalled = true;
      history.replaceState({ neoSwap: uuid }, '', location.href);
    }
  };

  /**
   * Fetches a filter-state URL and swaps the boundary's subtree in place.
   */
  const swap = (boundary: HTMLElement, url: string, push: boolean): void => {
    const uuid = getUuid(boundary);
    if (!uuid) {
      window.location.assign(url);
      return;
    }
    controller?.abort();
    controller = new AbortController();

    boundary.setAttribute('aria-busy', 'true');
    boundary.classList.add('neo-swap-loading');

    // Focus restoration: a visitor typing in the search box keeps their place.
    const active = document.activeElement as HTMLElement | null;
    const focusName =
      active && boundary.contains(active) ? active.getAttribute('name') : null;

    fetch(url, {
      signal: controller.signal,
      credentials: 'same-origin',
      headers: { Accept: 'text/html' },
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
        return response.text().then((html) => ({ html, finalUrl: response.url || url }));
      })
      .then(({ html, finalUrl }) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const fresh = doc.querySelector<HTMLElement>(
          `[data-neo-uuid="${CSS.escape(uuid)}"]`
        );
        if (!fresh) {
          throw new Error('Swap boundary missing from response');
        }

        Drupal.detachBehaviors(boundary, drupalSettings, 'unload');
        boundary.replaceWith(fresh);
        Drupal.attachBehaviors(fresh, drupalSettings);

        if (push) {
          installHistory(uuid);
          history.pushState({ neoSwap: uuid }, '', finalUrl);
        }

        // Everything below is decoration on an already-successful swap. It
        // must never reach the outer catch — falling back to a hard
        // navigation over a focus or announce hiccup would reload a page
        // that is already correct.
        try {
          if (focusName) {
            // :not([type="hidden"]) matters: sibling mini-forms carry each
            // other's values as hidden inputs with the SAME name, and those
            // can precede the visible control in DOM order.
            const target = fresh.querySelector<HTMLElement>(
              `[name="${CSS.escape(focusName)}"]:not([type="hidden"])`
            );
            if (target) {
              target.focus();
              if (
                target instanceof HTMLInputElement &&
                ['text', 'search', 'url', 'tel', 'password'].includes(target.type)
              ) {
                const end = target.value.length;
                target.setSelectionRange(end, end);
              }
            }
          }

          // Announce the update; the component may mark its results counter.
          const announce =
            fresh.querySelector('[data-neo-swap-announce]')?.textContent?.trim() ||
            Drupal.t('Results updated');
          const region = getLiveRegion();
          region.textContent = '';
          requestAnimationFrame(() => {
            region.textContent = announce;
          });

          // A pager click far down the page must not leave the visitor
          // staring at nothing; otherwise never touch scroll.
          if (fresh.getBoundingClientRect().top < 0) {
            fresh.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
        catch {
          // Cosmetics only; the swap stands.
        }
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return;
        }
        // Any failure degrades to the navigation that would have happened
        // without this behavior. Never a dead UI.
        window.location.assign(url);
      });
  };

  /**
   * A link is swappable when it can only be a filter-state change: same
   * origin, same path — only the query differs. Card links lead elsewhere.
   */
  const swappableLink = (link: HTMLAnchorElement): URL | null => {
    const href = link.getAttribute('href') || '';
    if (!href || href.startsWith('#') || link.target === '_blank') {
      return null;
    }
    if (link.closest('[data-no-swap]')) {
      return null;
    }
    let url: URL;
    try {
      url = new URL(link.href, location.href);
    }
    catch {
      return null;
    }
    if (url.origin !== location.origin || url.pathname !== location.pathname) {
      return null;
    }
    return url;
  };

  const onClick = (event: MouseEvent): void => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    const link = (event.target as Element).closest?.('a[href]') as HTMLAnchorElement | null;
    if (!link) {
      return;
    }
    const boundary = link.closest(BOUNDARY) as HTMLElement | null;
    if (!boundary) {
      return;
    }
    const url = swappableLink(link);
    if (!url) {
      return;
    }
    event.preventDefault();
    swap(boundary, url.href, true);
  };

  const onSubmit = (event: SubmitEvent): void => {
    if (event.defaultPrevented) {
      return;
    }
    const form = event.target as HTMLFormElement;
    const boundary = form.closest?.(BOUNDARY) as HTMLElement | null;
    if (!boundary || form.method.toLowerCase() !== 'get' || form.closest('[data-no-swap]')) {
      return;
    }
    let url: URL;
    try {
      url = new URL(form.action || location.href, location.href);
    }
    catch {
      return;
    }
    if (url.origin !== location.origin || url.pathname !== location.pathname) {
      return;
    }
    const params = new URLSearchParams();
    new FormData(form).forEach((value, name) => {
      if (typeof value === 'string') {
        params.append(name, value);
      }
    });
    url.search = params.toString();
    event.preventDefault();
    swap(boundary, url.href, true);
  };

  const onPopState = (event: PopStateEvent): void => {
    if (!event.state || !(event.state as { neoSwap?: string }).neoSwap) {
      return;
    }
    const uuid = (event.state as { neoSwap: string }).neoSwap;
    const boundary =
      document.querySelector<HTMLElement>(`[data-neo-swap][data-neo-uuid="${CSS.escape(uuid)}"]`) ||
      document.querySelector<HTMLElement>(BOUNDARY);
    if (boundary) {
      swap(boundary, location.href, false);
    }
    else {
      window.location.reload();
    }
  };

  Drupal.behaviors.neoSwap = {
    attach(context: HTMLElement) {
      // One set of delegated listeners for the whole page: they survive swaps
      // (the replaced subtree needs no rebinding) and cover every boundary.
      once('neo-swap', 'html', context).forEach(() => {
        document.addEventListener('click', onClick);
        document.addEventListener('submit', onSubmit);
        window.addEventListener('popstate', onPopState);
      });
    },
  };

})(Drupal, once, drupalSettings);
