(function (once) {

  const id = new URLSearchParams(window.location.search).get('id');
  const size = new URLSearchParams(window.location.search).get('size');

  window.addEventListener('message', function(e) {
    const data = e.data;
    if (typeof data.type === 'string') {
      if (typeof operations[data.type] !== 'function') {
        return;
      }
      operations[data.type](data);
    }
  });

  const operations:any = {

    componentHover: function (data: any) {
      const uuid = data.uuid;
      const component = getComponentByUuid(uuid);
      if (component) {
        doComponentHover(component);
      }
    },

    componentFocus: function (data: any) {
      const uuid = data.uuid;
      const component = getComponentByUuid(uuid);
      if (component) {
        window.parent.postMessage({
          type: 'doComponentFocus',
          id: id,
          size: size,
          uuid: component.dataset.componentUuid,
          component: JSON.parse(component.dataset.component || '{}'),
          rect: component.getBoundingClientRect(),
        }, '*');
      }
      else {
        window.parent.postMessage({
          type: 'componentDoesNotExist',
          id: id,
          size: size,
          uuid: uuid,
        }, '*');
      }
    }

  };

  once('neo.alchemist', '[data-component]').forEach(el => {
    // Check for empty component.
    checkEmpty(el);
    if (el.matches(':hover')) {
      componentHover(el);
    }
    el.addEventListener('mouseenter', () => {
      componentHover(el);
    });
  });

  function getComponentByUuid(uuid: string): HTMLElement | null {
    return document.querySelector(`[data-component-uuid="${uuid}"]`);
  }

  function componentHover(el: HTMLElement) {
    if (el.dataset.componentUuid) {
      window.parent.postMessage({
        type: 'onComponentHover',
        id: id,
        size: size,
        uuid: el.dataset.componentUuid,
        component: JSON.parse(el.dataset.component || '{}'),
      }, '*');
      doComponentHover(el);
    }
  }

  function doComponentHover(el: HTMLElement) {
    if (el.dataset.componentUuid) {
      window.parent.postMessage({
        type: 'doComponentHover',
        id: id,
        size: size,
        rect: el.getBoundingClientRect(),
      }, '*');
    }
  }

  function checkEmpty(el: HTMLElement) {
    // Check if the element is empty.
    el.style.display = 'block';
    if (el.clientHeight === 0) {
      const data = JSON.parse(el.dataset.component || '{}');
      const message = document.createElement('div');
      message.classList.add('w-full', 'text-center', 'text-sm', 'bg-base-200', 'p-4');
      message.innerHTML = '<strong><em>' + data.label + '</em></strong> has no visible content.';
      el.appendChild(message);
      // Watch for changes to the element's height. This allows dynamic content
      // to be loaded into the element, and the message to be removed.
      let lastHeight = el.clientHeight;
      const observer = new ResizeObserver(entries => {
        const entry = entries[0];
        const newHeight = entry.contentRect.height;
        if (newHeight !== lastHeight) {
          message.remove();
          observer.unobserve(el);
        }
      });
      observer.observe(el);
    }
    el.style.display = '';
  }

})(once);

export {};
