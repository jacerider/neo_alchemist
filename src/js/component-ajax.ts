(function (Drupal) {

  if (Drupal.AjaxCommands) {
    /**
     * Reloads the preview iframes one at a time, each waiting on the previous
     * frame's load event before it starts.
     *
     * A scheme change (or any other prop edit that re-renders) reloads every
     * preview frame — desktop, tablet and mobile — at once. Each reloaded
     * document re-requests the same shared URLs: the ~25 Drupal core scripts,
     * and under a running `vite watch` the dev server's `/@vite/client` plus
     * every CSS-as-JS module it serves. Firefox services same-URL parallel
     * requests off a cache entry that is still being written and intermittently
     * hands one of them an empty body — a 200 with no network error. When that
     * empty body is `/@vite/client`, the module "loads" but exports nothing, so
     * every CSS module importing from it dies with "doesn't provide an export
     * named 'createHotContext'" and the frame comes back unstyled.
     *
     * Chaining the reloads means a URL is never requested twice at once; the
     * second and third frames then read a warm cache, so this costs little.
     * This mirrors staggerIframeLoads() in component-parent.ts, which serialises
     * the initial frame loads for the same reason.
     */
    Drupal.AjaxCommands.prototype.neoAlchemistComponentIframeReload = function (_ajax, response, _status) {
      const iframes = Array.from(document.querySelectorAll(response.selector))
        .filter((el): el is HTMLIFrameElement => el instanceof HTMLIFrameElement);

      const reloadNext = (index: number): void => {
        const iframe = iframes[index];
        if (!iframe) {
          return;
        }
        let advanced = false;
        const advance = () => {
          if (advanced) {
            return;
          }
          advanced = true;
          reloadNext(index + 1);
        };
        iframe.addEventListener('load', advance, { once: true });
        // A frame that never fires load must not strand the ones behind it.
        setTimeout(advance, 10000);
        try {
          iframe.contentWindow?.location.reload();
        }
        catch (e) {
          advance();
        }
      };

      reloadNext(0);
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
