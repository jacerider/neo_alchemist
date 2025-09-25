(function () {

  const id = new URLSearchParams(window.location.search).get('id');
  const size = new URLSearchParams(window.location.search).get('size');

  window.addEventListener('message', function(e) {
    const data = e.data;
    if (typeof data.type === 'string' && data.type === 'screenshot') {
      const wrapper = document.querySelector('.neo-alchemist-preview') as HTMLElement;
      if (!wrapper) {
        return;
      }
      wrapper.style.width = '1024px';
      wrapper.style.maxHeight = '1024px';
      wrapper.style.minHeight = '440px';
      wrapper.style.overflow = 'hidden';
      wrapper.style.display = 'flex';
      wrapper.style.alignItems = 'start';
      wrapper.style.justifyContent = 'center';
      wrapper.style.padding = '0';
      const components = this.document.querySelectorAll('[data-component-id]') as NodeListOf<HTMLElement>;
      components.forEach((el) => {
        el.style.margin = '0px';
        el.style.width = '1024px';
        el.style.setProperty('--spacing-component', '30px');
      });
      setTimeout(() => {
        html2canvas(wrapper, {
          width: 1024,
          useCORS: true,
        }).then((canvas:any) => {
          wrapper.style.width = '';
          wrapper.style.maxHeight = '';
          wrapper.style.minHeight = '';
          wrapper.style.overflow = '';
          wrapper.style.display = '';
          wrapper.style.alignItems = '';
          wrapper.style.justifyContent = '';
          wrapper.style.padding = '';
          components.forEach((el) => {
            el.style.margin = '';
            el.style.width = '';
            el.style.setProperty('--spacing-component', '');
          });
          window.parent.postMessage({
            type: 'screenshot',
            id: id,
            size: size,
            dataUrl: canvas.toDataURL(),
          }, '*');
        });
      }, 300);
    }
  });

})();
