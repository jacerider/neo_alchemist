(function (Drupal, once) {
  let componentBlurTimeout:ReturnType<typeof setTimeout>|null = null;
  const shade:HTMLElement|null = document.querySelector('#neo-alchemist--shade');
  const overlay:HTMLElement|null = document.querySelector('#neo-alchemist--overlay');
  const ops:NodeListOf<HTMLElement>|undefined = overlay?.querySelectorAll('.neo-alchemist--ops');
  let component:HTMLElement|null = null;
  let componentData:any = null;

  if (overlay) {
    overlay.addEventListener('click', () => {
      if (component) {
        componentFocus(component);
      }
    });
    const close:HTMLElement|null = overlay.querySelector('.close');
    if (close) {
      close.addEventListener('click', e => {
        e.preventDefault();
        componentBlur();
      });
    }
  }

  if (shade) {
    shade.addEventListener('click', e => {
      e.preventDefault();
      componentBlur();
    });
  }

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
        console.log('click', opKey);
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
            window.parent.postMessage(message, '*');
          }
        }
      });
    });
  }

  const componentHover = (el:HTMLElement) => {
    component = el;
    componentSize(false);
  }

  const componentFocus = (el:HTMLElement) => {
    component = el;
    componentData = JSON.parse(component.dataset.component || '{}');
    if (overlay && componentData.uuid) {
      const opButtons = overlay.querySelectorAll('.op') as NodeListOf<HTMLElement>;
      opButtons.forEach(opButton => {
        opButton.style.display = 'none';
      });
      const title = overlay.querySelector('.title');
      if (title) {
        title.innerHTML = componentData.label;
      }
      if (componentData.ops) {
        Object.keys(componentData.ops).forEach(opKey => {
          const status = componentData.ops[opKey];
          if (status) {
            const opButton = overlay.querySelector(`[data-op="${opKey}"]`) as HTMLElement;
            if (opButton) {
              opButton.style.display = '';
            }
          }
        });
      }
    }
    componentSize(true);
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

  const componentSize = (isFocused:boolean) => {
    if (component) {
      isFocused = isFocused ?? false;
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
        if (isFocused) {
          overlay.classList.remove('cursor-pointer');
        }
        else {
          overlay.classList.add('cursor-pointer');
        }
        setTimeout(() => {
          overlay.classList.add('!transition-all');
        })
        // overlay.addEventListener('mouseleave', onOverlayMouseLeave);
      }
      if (isFocused) {
        if (ops) {
          ops.forEach(op => {
            op.classList.add('is-active');
          });
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
      else {
        if (ops) {
          ops.forEach(op => {
            op.classList.remove('is-active');
          });
        }
        if (shade) {
          shade.classList.remove('is-active');
          shade.classList.remove('!transition-all');
        }
      }
    }
  };

  const onOverlayMouseLeave = (e:MouseEvent) => {
    const el = e.currentTarget as HTMLElement;
    el.removeEventListener('mouseleave', onOverlayMouseLeave);
    componentBlur();
  };

  // setInterval(() => {
  //   componentSize(false);
  // }, 200);

  Drupal.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function () {
      if (window.parent) {
        once('neo.alchemist', '[data-component]').forEach(el => {
          el.addEventListener('mouseenter', () => {
            componentHover(el);
          });
        });
      }
    }
  };

})(Drupal, once);

export {};
