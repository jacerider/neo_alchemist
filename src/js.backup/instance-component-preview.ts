(function (Drupal, once) {
  let componentBlurTimeout:ReturnType<typeof setTimeout>|null = null;
  const shade:HTMLElement|null = document.querySelector('#neo-alchemist--shade');
  const overlay:HTMLElement|null = document.querySelector('#neo-alchemist--overlay');
  const ops:NodeListOf<HTMLElement>|undefined = overlay?.querySelectorAll('.neo-alchemist--ops');
  let component:HTMLElement|null = null;
  let focus:boolean = false;
  let componentData:any = null;

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

  document.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
      componentBlur();
    }
  });

  if (overlay) {
    overlay.ondblclick = function(_e: MouseEvent) {
      if (component) {
        componentDo('edit');
      }
    };

    overlay.addEventListener('click', () => {
      if (component) {
        componentFocus(component);
      }
    });
    overlay.addEventListener('mouseleave', () => {
      if (!focus) {
        componentBlur();
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

  const componentDo = (opKey:string) => {
    if (component && opKey) {
      const data = JSON.parse(component.dataset.component || '{}');
      if (data.ops[opKey]) {
        const message = JSON.stringify({
          type: opKey,
          uuid: data.uuid,
          scrollY: window.scrollY,
          scrollX: window.scrollX,
        });
        window.parent.postMessage(message, '*');
      }
    }
  }

  if (overlay) {
    const opButtons = overlay.querySelectorAll('.op') as NodeListOf<HTMLElement>;
    opButtons.forEach(opButton => {
      opButton.addEventListener('click', (e) => {
        e.preventDefault();
        const opKey = opButton.dataset.op;
        if (opKey) {
          componentDo(opKey);
        }
      });
    });
  }

  const componentHover = (el:HTMLElement) => {
    component = el;
    componentSize();
  }

  const componentFocus = (el:HTMLElement) => {
    focus = true;
    component = el;
    componentData = JSON.parse(component.dataset.component || '{}');
    if (overlay && componentData.uuid) {
      component.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
        inline: 'nearest',
      });
      const opButtons = overlay.querySelectorAll('.op') as NodeListOf<HTMLElement>;
      opButtons.forEach(opButton => {
        opButton.style.display = 'none';
      });
      const title = overlay.querySelector('.title');
      if (title) {
        title.innerHTML = componentData.label;
        if (componentData.status !== true) {
          title.innerHTML += ` <span class="badge bg-alert-500 text-alert-content-500">Draft</span>`;
        }
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
    componentSize();
  }

  const componentBlur = () => {
    if (component) {
      focus = false;
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
    }
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
        if (focus) {
          overlay.classList.remove('cursor-pointer');
        }
        else {
          overlay.classList.add('cursor-pointer');
        }
        setTimeout(() => {
          overlay.classList.add('!transition-all');
        })
      }
      if (focus) {
        if (ops) {
          ops.forEach(op => {
            op.classList.add('is-focus');
          });
        }
        if (shade) {
          shade.style.top = '0px';
          shade.style.right = '0px';
          shade.style.bottom = '0px';
          shade.style.left = '0px';
          shade.style.width = document.documentElement.scrollWidth + 'px';
          shade.style.height = document.documentElement.scrollHeight + 'px';
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
            op.classList.remove('is-focus');
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

  function onPageResize() {
    if (component && focus) {
      component.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
        inline: 'nearest',
      });
    }
    componentSize();
  }
  const throttlePageObserver = debounce(onPageResize, 50);

  Drupal.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function () {
      if (window.parent) {

        once('neo.alchemist', '.page-wrapper').forEach(el => {
          const observer = new ResizeObserver((_entries) => {
            throttlePageObserver();
          });
          observer.observe(el);
        });

        once('neo.alchemist', '[data-component]').forEach(el => {
          el.style.display = 'block';
          if (el.clientHeight === 0) {
            const data = JSON.parse(el.dataset.component || '{}');
            el.innerHTML = '<div class="w-full text-center text-sm bg-base-200 p-4"><strong><em>' + data.label + '</em></strong> has no visible content.</div>';
          }
          el.style.display = '';
          if (el.matches(':hover')) {
            componentHover(el);
          }
          el.addEventListener('mouseenter', () => {
            componentHover(el);
          })
        });
      }
    }
  };

})(Drupal, once);

export {};
