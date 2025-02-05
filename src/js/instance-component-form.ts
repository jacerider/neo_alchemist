(function (Drupal, once) {

  function debounce<T extends (...args: any[]) => void>(func: T, delay: number): T {
    let timeoutId: ReturnType<typeof setTimeout>|null;
    return function (this: any, ...args: any[]) {
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
      timeoutId = setTimeout(() => {
        func.apply(this, args);
      }, delay);
    } as T;
  }

  let refreshButton = null as HTMLButtonElement|null;
  function handleRefresh() {
    if (refreshButton) {
      refreshButton.dispatchEvent(new Event('mousedown', {
        bubbles: true,
        cancelable: true
      }));
    }
  }

  const throttledInput = debounce(handleRefresh, 250);

  Drupal.behaviors.neoAlchemistInstanceComponentForm = {
    attach: function () {
      once('neo.alchemist', '#neo-alchemist--instance-component-form [data-autocomplete-path]').forEach(el => {
        jQuery(el).on('autocompleteselect', function (_e) {
          throttledInput();
        });
      });
      once('neo.alchemist', '#neo-alchemist--instance-component-form').forEach(el => {
        el.addEventListener('input', function (e) {
          if (e.target instanceof HTMLInputElement) {
            if (e.target.dataset.autocompletePath) {
              return;
            }
            if (e.target.dataset.once && e.target.dataset.once.includes('drupal-ajax')) {
              return;
            }
            else {
              throttledInput();
            }
          }
        });
        const observer = new MutationObserver((mutations) => {
          mutations.forEach((mutation) => {
            const target = mutation.target as HTMLElement;
            if (
              target.classList.contains('ts-dropdown') ||
              target.classList.contains('highlight') ||
              target.closest('.ts-dropdown') ||
              target.classList.contains('ts-wrapper')
            ) {
              return;
            }
            throttledInput();
          });
        });
        observer.observe(el, { childList: true, subtree: true });
        refreshButton = el.querySelector('#neo-alchemist--refresh') as HTMLButtonElement;
      });
    }
  };

})(Drupal, once);

export {};
