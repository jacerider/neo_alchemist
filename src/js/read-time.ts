/**
 * Read-time estimate for the `read_time` ComponentValue provider.
 *
 * The provider emits a hidden placeholder carrying its settings as data
 * attributes; this counts the words in the configured container, writes the
 * label and reveals the element. The reveal is the last step on purpose — a
 * page with nothing to count, or with the container missing, leaves the
 * placeholder hidden so the component can collapse its separator along with it
 * rather than rendering a dangling bullet.
 */
(function (Drupal, once) {

  /**
   * Count the words inside an element.
   *
   * textContent rather than innerText: it does not force a layout, and
   * hidden-but-present prose still counts toward the estimate.
   */
  const countWords = (el: HTMLElement): number => {
    const text = (el.textContent || '').trim();
    if (text === '') {
      return 0;
    }
    return text.split(/\s+/).length;
  };

  Drupal.behaviors.neoAlchemistReadTime = {
    attach(context: HTMLElement | Document) {
      once('neo-alchemist-read-time', '[data-neo-readtime]', context as HTMLElement).forEach((element) => {
        const el = element as HTMLElement;

        const selector = el.dataset.neoReadtimeSelector || '.region--content';
        const wpm = parseInt(el.dataset.neoReadtimeWpm || '200', 10) || 200;
        const minimum = parseInt(el.dataset.neoReadtimeMin || '1', 10);
        const format = el.dataset.neoReadtimeFormat || '@count min read';

        let source: HTMLElement | null = null;
        try {
          source = document.querySelector(selector);
        }
        catch {
          // A malformed selector from the settings form should not take the
          // rest of the page's behaviors down with it.
          return;
        }
        if (!source) {
          return;
        }

        const words = countWords(source);
        if (words === 0) {
          return;
        }

        const minutes = Math.max(minimum, Math.ceil(words / wpm));
        el.textContent = format.replace('@count', String(minutes));
        el.hidden = false;
      });
    },
  };

})(Drupal, once);
