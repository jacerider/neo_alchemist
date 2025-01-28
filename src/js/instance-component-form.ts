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
      once('neo.alchemist', '#neo-alchemist--instance-component-form').forEach(el => {
        el.addEventListener('input', throttledInput);
        const observer = new MutationObserver((mutations) => {
          mutations.forEach((_mutation) => {
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
