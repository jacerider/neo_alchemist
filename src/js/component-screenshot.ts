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
      wrapper.style.justifyContent = 'start';
      wrapper.style.padding = '15px 0 0';
      wrapper.style.boxShadow = 'inset 0 0 0 2px black';
      // wrapper.style.border = '2px dotted black';
      // wrapper.style.borderStyle = 'inset';
      const components = this.document.querySelectorAll('[data-component-id]') as NodeListOf<HTMLElement>;
      components.forEach((el) => {
        el.style.width = '1024px';
        el.style.setProperty('margin', '0px', 'important');
        el.style.setProperty('--spacing-component', '30px');
      });

      const controls = this.document.createElement('div');
      let group: HTMLDivElement;
      let groupTitle: HTMLDivElement;
      let links: HTMLDivElement;
      let link: HTMLAnchorElement;
      controls.classList.add('fixed', 'flex', 'items-end', 'p-2', 'gap-2', 'border', 'bg-base-0', 'rounded', 'shadow');
      controls.style.top = '10px';
      controls.style.right = '10px';
      controls.style.zIndex = '9999';
      this.document.body.appendChild(controls);

      const actions = [
        { name: 'Zoom', property: 'transform', subproperty: 'scale', value: 0.2 },
        { name: 'Width', property: 'width', value: 10 },
      ];

      actions.forEach((action) => {
        const property:string = action.property as string;
        const subproperty = action.subproperty || null;
        const value:number = action.value as number;
        group = this.document.createElement('div') as HTMLDivElement;
        controls.appendChild(group);

        groupTitle = this.document.createElement('div') as HTMLDivElement;
        groupTitle.innerHTML = action.name;
        groupTitle.classList.add('text-xs', 'uppercase', 'font-bold', 'text-base-500');
        group.appendChild(groupTitle);

        links = this.document.createElement('div') as HTMLDivElement;
        links.classList.add('btn-group');
        group.appendChild(links);

        link = this.document.createElement('a') as HTMLAnchorElement;
        link.classList.add('btn', 'btn-xs');
        link.textContent = '+';
        link.style.minWidth = '24px';
        link.href = '#';
        link.addEventListener('click', (event) => {
          event.preventDefault();
          components.forEach((el) => {
            if (subproperty) {
              const current = (el.style as any)[property] ? parseFloat((el.style as any)[property].replace(subproperty + '(', '').replace(')', '')) : 1;
              const newValue = current + value;
              (el.style as any)[property] = `scale(${newValue})`;
              el.style.transformOrigin = 'top left';
            }
            else {
              const current = (el.style as any)[property] ? parseFloat((el.style as any)[property].replace('px', '')) : 1024;
              const newWidth = current + value;
              (el.style as any)[property] = `${newWidth}px`;
            }
          });
        });
        links.appendChild(link);

        link = this.document.createElement('a') as HTMLAnchorElement;
        link.classList.add('btn', 'btn-xs');
        link.style.minWidth = '24px';
        link.textContent = '-';
        link.href = '#';
        link.addEventListener('click', (event) => {
          event.preventDefault();
          components.forEach((el) => {
            if (subproperty) {
              const current = (el.style as any)[property] ? parseFloat((el.style as any)[property].replace(subproperty + '(', '').replace(')', '')) : 1;
              const newValue = current - value;
              (el.style as any)[property] = `scale(${newValue})`;
              el.style.transformOrigin = 'top left';
            }
            else {
              const current = (el.style as any)[property] ? parseFloat((el.style as any)[property].replace('px', '')) : 1024;
              const newWidth = current - value;
              (el.style as any)[property] = `${newWidth}px`;
            }
          });
        });
        links.appendChild(link);
      });

      link = this.document.createElement('a') as HTMLAnchorElement;
      link.classList.add('btn', 'btn-xs', 'btn-alert');
      link.textContent = 'Capture';
      link.href = '#';
      link.addEventListener('click', (event) => {
        event.preventDefault();
        wrapper.style.boxShadow = '';
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
            el.style.transform = '';
            el.style.transformOrigin = '';
            el.style.setProperty('--spacing-component', '');
          });
          window.parent.postMessage({
            type: 'screenshot',
            id: id,
            size: size,
            dataUrl: canvas.toDataURL(),
          }, '*');
        });
      });
      controls.appendChild(link);
    }
  });

})();
