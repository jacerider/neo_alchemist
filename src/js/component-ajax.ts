(function (Drupal) {

  // Supersedes an in-flight render when a newer edit arrives, so a burst of
  // typing costs one render rather than a queue of them.
  let controller: AbortController | null = null;
  // The same for bringing stale frames up to date (refreshStale()). Kept apart
  // so showing a stale frame never cancels an edit's render, while an edit —
  // which renders the newer state — supersedes it.
  let staleController: AbortController | null = null;

  /**
   * Reload every frame, spaced out.
   *
   * Only reached when the render itself failed, so all three frames are about
   * to rebuild at once — which is the same-URL asset stampede described below.
   * Spacing them keeps that property on the one path that still reloads.
   */
  const reloadAll = (iframes: HTMLIFrameElement[]): void => {
    iframes.forEach((iframe, index) => {
      window.setTimeout(() => {
        try {
          iframe.contentWindow?.location.reload();
        }
        catch (e) {
          // Nothing further to try for a frame we cannot reach.
        }
      }, index * 400);
    });
  };

  /**
   * Render the component once and hand the result to the given frames.
   *
   * The three previews differ by their `size` query argument, but the server's
   * output differs only by the `data-size` attribute it stamps on the wrapper
   * — verified by diffing the three responses, which are otherwise identical.
   * So rendering per frame was doing the same work three times for one edit.
   * One render, distributed, is a third of the server cost per keystroke
   * batch, and each frame restamps its own size on the way in.
   *
   * The URL comes from a frame's own document rather than its src attribute
   * where possible, so a frame that has navigated since (a fallback reload)
   * still refreshes from where it actually is.
   */
  const deliver = (iframes: HTMLIFrameElement[], signal: AbortSignal): void => {
    let url = '';
    try {
      url = iframes[0].contentWindow?.location.href || iframes[0].src;
    }
    catch (e) {
      url = iframes[0].src;
    }
    if (!url) {
      reloadAll(iframes);
      return;
    }

    fetch(url, { credentials: 'same-origin', signal: signal })
      .then(response => {
        if (!response.ok) {
          throw new Error(String(response.status));
        }
        return response.text();
      })
      .then(html => {
        iframes.forEach(iframe => {
          try {
            iframe.contentWindow?.postMessage({ type: 'previewRefresh', html: html }, window.location.origin);
          }
          catch (e) {
            iframe.contentWindow?.location.reload();
          }
        });
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return;
        }
        reloadAll(iframes);
      });
  };

  /**
   * Refresh the frames the canvas is using after an edit.
   *
   * Only the active frames (Drupal.neoAlchemist.frames, component-parent.ts)
   * are refreshed: a frame the canvas is not showing would cost a render and
   * a rebuild nobody sees, on every edit. Each other frame that has loaded is
   * marked stale instead (data-stale), and is brought up to date by
   * refreshStale() when it is shown again. The frame clears the mark itself
   * once it shows the current render — see markFresh() in component-child.ts.
   */
  const refreshFrames = (iframes: HTMLIFrameElement[]): void => {
    const frames = Drupal.neoAlchemist?.frames;
    const active = frames?.active();
    const targets = iframes.filter(iframe => !active || active.includes(iframe.dataset.size as NeoAlchemistFrameSize));
    iframes.forEach(iframe => {
      if (targets.includes(iframe) || !frames || frames.state(iframe.dataset.size as NeoAlchemistFrameSize) === 'unloaded') {
        return;
      }
      iframe.dataset.stale = '';
    });
    if (!targets.length) {
      return;
    }
    staleController?.abort();
    controller?.abort();
    controller = new AbortController();
    deliver(targets, controller.signal);
  };

  /**
   * Bring stale frames up to the current render, as an edit would have.
   */
  const refreshStale = (iframes: HTMLIFrameElement[]): void => {
    if (!iframes.length) {
      return;
    }
    staleController?.abort();
    staleController = new AbortController();
    deliver(iframes, staleController.signal);
  };

  Drupal.neoAlchemist = Drupal.neoAlchemist || {};
  Drupal.neoAlchemist.refreshStale = refreshStale;

  if (Drupal.AjaxCommands) {
    /**
     * Asks each preview frame to re-render its component in place.
     *
     * This used to reload the frames instead, one at a time, each waiting on
     * the previous frame's load event. Serialising them was necessary because
     * three documents rebuilding at once re-request the same shared URLs — the
     * ~25 Drupal core scripts, and under a running `vite watch` the dev
     * server's `/@vite/client` plus every CSS-as-JS module it serves. Firefox
     * services same-URL parallel requests off a cache entry that is still
     * being written and intermittently hands one of them an empty body: a 200
     * with no network error, so the module "loads" and exports nothing, and
     * every CSS module importing from it dies on a missing 'createHotContext'.
     *
     * A refresh renders once here and swaps the component subtree into each
     * frame, so no asset is requested at all and there is nothing to
     * serialise. Frames fall back to a reload individually if the swap cannot
     * be done safely, and reloadAll() staggers them when the render itself
     * failed — which is what keeps the property above on those paths.
     *
     * @see refreshPreview() in component-child.ts
     */
    Drupal.AjaxCommands.prototype.neoAlchemistComponentIframeReload = function (_ajax, response, _status) {
      const iframes = Array.from(document.querySelectorAll(response.selector))
        .filter((el): el is HTMLIFrameElement => el instanceof HTMLIFrameElement);
      if (!iframes.length) {
        return;
      }
      refreshFrames(iframes);
    } as drupal.Core.IAjaxCommand;

    Drupal.AjaxCommands.prototype.neoAlchemistComponentFocus = function (_ajax, response, _status) {
      const container = document.querySelector('.neo-alchemist-manage');
      if (container) {
        const customEvent = new CustomEvent('alchemistManageComponentFocus', {
          bubbles: true,
          cancelable: true,
          detail: {
            uuid: response.uuid,
          }
        });
        // Dispatch the event on the element
        container.dispatchEvent(customEvent);
      }
    } as drupal.Core.IAjaxCommand;
  }

})(Drupal);
