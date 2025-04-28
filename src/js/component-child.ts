(function (Drupal, once) {

  // TypeScript interfaces
  interface ClickOptions {
    capture?: boolean;
    passive?: boolean;
  }

  const id = new URLSearchParams(window.location.search).get('id');
  const size = new URLSearchParams(window.location.search).get('size');

  Drupal.behaviors.neoAlchemistComponentChild = {
    attach: function () {
      once('neo.alchemist', '.neo-alchemist-preview').forEach(element => {

        // Handle iframe messages and send to parent if desktop size.
        const messages = document.querySelector('.alchemist-messages .messages--wrapper');
        if (messages) {
          const debug = messages.querySelector('.sf-dump') || messages.querySelector('.kint-rich');
          if (debug) {
            const wrapper = document.querySelector('.alchemist-messages');
            if (wrapper) {
              wrapper.classList.add('opacity-100');
              wrapper.classList.remove('invisible', 'opacity-0');
            }
          }
          else {
            if (size === 'desktop') {
              if (!debug) {
                window.parent.postMessage({
                  type: 'messages',
                  id: id,
                  size: size,
                  messages: messages.innerHTML,
                }, '*');
              }
            }
            messages.remove();
          }
        }

        // Create a ResizeObserver instance
        const resizeObserver = new ResizeObserver(entries => {
          for (const _entry of entries) {
            window.parent.postMessage({
              type: 'size',
              id: id,
              size: size,
              height: element.scrollHeight,
            }, '*');
          }
        });

        // Start observing the body element
        resizeObserver.observe(element);

        // Disable global left click on the body element
        disableGlobalLeftClick(element);
      });
    }
  };

  const disableGlobalLeftClick = (element:HTMLElement): void => {
    const handleClick = (event: MouseEvent): boolean => {
      // Check if it's a left click (button property is 0 for left clicks)
      if (event.button === 0) {
        event.preventDefault();
        return false;
      }
      return true;
    };

    element.addEventListener('click', handleClick, { capture: true } as ClickOptions);
  };

})(Drupal, once);

export {};
