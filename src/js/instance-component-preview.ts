(function (Drupal, once) {
  let componentBlurTimeout:ReturnType<typeof setTimeout>|null = null;
  const shade:HTMLElement|null = document.querySelector('#neo-alchemist--shade');
  const overlay:HTMLElement|null = document.querySelector('#neo-alchemist--overlay');
  let component:HTMLElement|null = null;
  // const ops = ['edit', 'sort', 'delete', 'clone', 'add-before', 'add-after'];

  const messages = document.getElementById('neo-alchemist--messages');
  if (messages) {
    setTimeout(() => {
      messages.classList.add('transition-all');
      messages.classList.remove('opacity-0', '-translate-y-full');
    }, 100);
    const hasDebug = messages.querySelector('.kint-rich');
    if (hasDebug) {
      messages.classList.remove('fixed');
    }
    else {
      setTimeout(() => {
        messages?.classList.add('opacity-0', '-translate-y-full');
      }, 4000);
    }
  }

  if (overlay) {
    const opButtons = overlay.querySelectorAll('.op') as NodeListOf<HTMLElement>;
    opButtons.forEach(opButton => {
      opButton.addEventListener('click', (e) => {
        e.preventDefault();
        const opKey = opButton.dataset.op;
        if (component && opKey) {
          const data = JSON.parse(component.dataset.component || '{}');
          if (data.ops[opKey]) {
            // const parts = opKey.split('-');
            // const op = parts[0];
            // const spec = parts[1] ?? null;
            const message = JSON.stringify({
              type: opKey,
              uuid: data.uuid,
              scrollY: window.scrollY,
              scrollX: window.scrollX,
            });
            console.log('message', message);
            window.parent.postMessage(message, '*');
          }
        }
      });
    });
  }

  const componentFocus = (el:HTMLElement) => {
    component = el;
    const data = JSON.parse(component.dataset.component || '{}');
    if (overlay && data.uuid) {
      const opButtons = overlay.querySelectorAll('.op') as NodeListOf<HTMLElement>;
      opButtons.forEach(opButton => {
        opButton.style.display = 'none';
      });
      const title = overlay.querySelector('.title');
      if (title) {
        title.innerHTML = data.label;
      }
      if (data.ops) {
        Object.keys(data.ops).forEach(opKey => {
          const status = data.ops[opKey];
          if (status) {
            const opButton = overlay.querySelector(`[data-op="${opKey}"]`) as HTMLElement;
            if (opButton) {
              opButton.style.display = '';
            }
          }
        });
      }
    }
    componentSize();
  }

  const componentBlur = () => {
    component = null;
    componentBlurTimeout = setTimeout(() => {
      if (overlay) {
        overlay.classList.remove('is-active');
        overlay.classList.remove('!transition-all');
      }
      if (shade) {
        shade.classList.remove('is-active');
        shade.classList.remove('!transition-all');
      }
    }, 100);
  };

  const componentSize = () => {
    if (component) {
      if (componentBlurTimeout) {
        clearTimeout(componentBlurTimeout);
      }
      const rect = component.getBoundingClientRect();
      const top = rect.top + window.scrollY;
      const bottom = rect.bottom + window.scrollY;
      const left = rect.left + window.scrollX;
      const right = rect.right + window.scrollX;
      if (overlay) {
        overlay.style.top = top + 'px';
        overlay.style.left = left + 'px';
        overlay.style.width = rect.width + 'px';
        overlay.style.height = rect.height + 'px';
        overlay.classList.add('is-active');
        setTimeout(() => {
          overlay.classList.add('!transition-all');
        })
        overlay.addEventListener('mouseleave', onOverlayMouseLeave);
      }
      if (shade) {
        shade.style.top = '0px';
        shade.style.right = '0px';
        shade.style.bottom = '0px';
        shade.style.left = '0px';
        shade.style.width = document.body.clientWidth + 'px';
        shade.style.height = document.body.clientHeight + 'px';
        shade.style.clipPath = `polygon(0% 0%, 0% 100%, ${left}px 100%, ${left}px ${top}px, ${right}px ${top}px, ${right}px ${bottom}px, ${left}px ${bottom}px, ${left}px 100%, 100% 100%, 100% 0%)`;
        shade.classList.add('is-active');
        setTimeout(() => {
          shade.classList.add('!transition-all');
        })
      }
    }
  };

  const onOverlayMouseLeave = (e:MouseEvent) => {
    const el = e.currentTarget as HTMLElement;
    el.removeEventListener('mouseleave', onOverlayMouseLeave);
    componentBlur();
  };

  setInterval(() => {
    componentSize();
  }, 200);

  Drupal.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function () {
      if (window.parent) {
        once('neo.alchemist', '[data-component]').forEach(el => {
          el.addEventListener('mouseenter', () => {
            componentFocus(el);
          });
          // el.addEventListener('click', (e) => {
          //   e.preventDefault();
          //   const uuid = el.getAttribute('data-component-uuid');
          //   const message = JSON.stringify({
          //     type: 'sort',
          //     uuid: uuid,
          //   });
          //   window.parent.postMessage(message, '*');
          // });
        });
      }
    }
  };

})(Drupal, once);

export {};
