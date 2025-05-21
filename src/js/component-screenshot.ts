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
      const components = this.document.querySelectorAll('[data-component-id]') as NodeListOf<HTMLElement>;
      components.forEach((el) => {
        el.style.margin = '0px';
      });
      html2canvas(wrapper).then((canvas:any) => {
        wrapper.style.width = '';
        components.forEach((el) => {
          el.style.margin = '';
        });
        window.parent.postMessage({
          type: 'screenshot',
          id: id,
          size: size,
          dataUrl: canvas.toDataURL(),
        }, '*');
      });
    }
  });

})();

export {};
