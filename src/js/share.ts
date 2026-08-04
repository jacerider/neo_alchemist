/**
 * Native share (Web Share API) for share links produced by the `share`
 * ComponentValue plugin.
 *
 * The plugin emits an extra menu item carrying `share_native` / `share_title` /
 * `share_url`; a component renders that item hidden, with the payload on
 * `data-neo-share-*` attributes. This reveals it only once the browser can
 * actually handle the payload, so the ordinary network links stay the fallback
 * on desktop, without JS, and over plain HTTP.
 *
 * `navigator.share` exists only in a secure context (HTTPS or localhost) and
 * requires transient user activation, so it must be called synchronously from
 * within the click handler — never after an await.
 */
(function (Drupal, once) {

  interface ShareData {
    title?: string;
    text?: string;
    url?: string;
  }

  /**
   * Read the share payload off an element, omitting empty values.
   *
   * Passing an explicit `undefined`/`null` is not the same as omitting the key:
   * the Web Share API coerces a null title to the string "null".
   */
  const readShareData = (el: HTMLElement): ShareData => {
    const data: ShareData = {};
    const title = el.dataset.neoShareTitle;
    const text = el.dataset.neoShareText;
    const url = el.dataset.neoShareUrl;
    if (title) {
      data.title = title;
    }
    if (text) {
      data.text = text;
    }
    if (url) {
      // navigator.share wants an absolute URL; resolve one just in case the
      // component rendered a root-relative href.
      data.url = new URL(url, window.location.href).href;
    }
    return data;
  };

  Drupal.behaviors.neoAlchemistShare = {
    attach(context: HTMLElement | Document) {
      // No API, no button. The item stays hidden and the network links serve
      // as the fallback.
      if (typeof navigator === 'undefined' || !navigator.share) {
        return;
      }

      once('neo-alchemist-share', '[data-neo-share]', context as HTMLElement).forEach((element) => {
        const el = element as HTMLElement;
        const data = readShareData(el);
        if (!data.url) {
          return;
        }

        // Check before revealing, so a browser that has share() but rejects
        // this payload never shows a button that cannot work.
        if (navigator.canShare && !navigator.canShare(data)) {
          return;
        }

        const trigger = el.matches('button, a') ? el : el.querySelector('button, a');
        if (!trigger) {
          return;
        }

        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          // Synchronous: awaiting anything first would spend the transient
          // activation the API requires.
          navigator.share(data).catch((error: unknown) => {
            // The user dismissing the share sheet rejects with AbortError.
            // That is a normal outcome, not a failure worth logging.
            if (error instanceof Error && error.name === 'AbortError') {
              return;
            }
            console.warn('Native share failed', error);
          });
        });

        el.hidden = false;
        el.classList.remove('hidden');
      });
    },
  };

})(Drupal, once);
