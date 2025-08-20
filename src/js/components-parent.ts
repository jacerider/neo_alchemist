(function (Drupal, once) {

  // Define interfaces for better type safety
  interface Component {
    uuid: string;
    label: string;
    alerts: string[];
    warnings: string[];
    ops?: Record<string, boolean>;
  }

  interface ComponentData {
    uuid: string;
    component: Component;
    size: string;
    rect?: DOMRect;
    type: string;
  }

  /**
   * Interface for modal configuration options
   */
  interface ModalOptions {
    width: string;
    height: string;
    neo: {
      displaceTop: string;
      displaceBottom: string;
      contentPadding?: string;
    };
  }

  /**
   * Interface for component operations
   */
  interface ComponentOperations {
    edit: (component: Component) => void;
    sort: (component: Component) => void;
    delete: (component: Component) => void;
    clone: (component: Component) => void;
    add: (component: Component, position: string) => void;
  }

  /**
   * Base modal options configuration
   */
  const baseModalOptions: ModalOptions = {
    width: '100%',
    height: '100%',
    neo: {
      displaceTop: '0px',
      displaceBottom: '0px',
    },
  };

  once('neo.alchemist.components.parent', '.neo-alchemist-manage').forEach(container => {
    // State variables
    let component: Component = {} as Component;
    let componentState: 'hover' | 'focus' | null = null;
    let componentPendingFocus: string | null = null;
    let componentLastFocus:string|null = null;
    let componentInteractSize: string | null = null;
    let componentBlurTimeout: ReturnType<typeof setTimeout> | null = null;
    let scale: string = localStorage.getItem('neo-alchemist-scale') || '1';

    // DOM elements
    const iframes = container.querySelectorAll('iframe');
    const wrapper = container.querySelector<HTMLElement>('.neo-alchemist-manage--wrapper');
    const overlay = container.querySelector<HTMLElement>('.neo-alchemist--overlay');
    const shade = container.querySelector<HTMLElement>('.neo-alchemist--shade');
    const ops = container.querySelectorAll<HTMLElement>('.neo-alchemist--ops');

    // Collections
    const overlays: Record<string, HTMLElement> = {};
    const shades: Record<string, HTMLElement> = {};
    const viewportSizes = ['desktop', 'tablet', 'mobile'];

    let iframeProcessing = 0;
    iframes.forEach(iframe => {
      iframe.addEventListener('load', () => {
        iframeProcessing++;
        if (!iframe.contentWindow) {
          return;
        }
        if (componentPendingFocus || componentState === 'focus') {
          // If we have a pending component or current focused component,
          // we want to trigger focus on it. If it no longer exists, it will
          // be removed.
          iframe.contentWindow.postMessage({
            type: 'componentFocus',
            uuid: componentPendingFocus || component.uuid,
          }, "*");
        }
        if (iframeProcessing === 3) {
          componentPendingFocus = null;
        }
      });
    });

    // Watch for component focus event
    const focusCallback = (event: CustomEvent<any>) => {
      const detail = event.detail as { uuid: string };
      // Set pending focus to the new component so that iframe load event
      // can pick it up.
      componentPendingFocus = detail.uuid;
    }
    container.addEventListener('alchemistManageComponentFocus', focusCallback as EventListener);

    // Watch for scale changes
    const scaleCallback = (event: CustomEvent<any>) => {
      const detail = event.detail as { scale: string };
      scale = detail.scale as string;
      componentBlur(0);
    }
    container.addEventListener('alchemistManageScale', scaleCallback as EventListener);

    // Add close button event listener
    if (ops && wrapper) {
      const closeButton = wrapper.querySelector<HTMLElement>('.close');
      if (closeButton) {
        closeButton.addEventListener('click', (e) => {
          e.preventDefault();
          componentBlur(0);
        });
      }
    }

    // Initialize overlays and shades for different viewport sizes
    if (overlay && shade) {
      initializeOverlaysAndShades(overlay, shade);
    }

    if (wrapper) {
      const opButtons = wrapper.querySelectorAll('.op') as NodeListOf<HTMLElement>;
      opButtons.forEach(opButton => {
        opButton.addEventListener('click', (e) => {
          e.preventDefault();
          const opKey = opButton.dataset.op as keyof ComponentOperations;
          if (opKey) {
            executeComponentOperation(component, opKey);
          }
        });
      });
    }

    if (wrapper) {
      let startX:number;
      let startY:number;
      wrapper.addEventListener('mousedown', e => {
        startX = e.clientX;
        startY = e.clientY;
      });
      wrapper.addEventListener('mouseup', e => {
        if (componentState === null) {
          return;
        }
        // Check if target has data-alchemist-ignore
        if (e.target instanceof HTMLElement && (e.target.dataset.alchemistIgnore !== undefined || e.target.closest('[data-alchemist-ignore]'))) {
          return;
        }
        // If mouse hasn't moved, we blur the component.
        if (startX === e.clientX && startY === e.clientY) {
          componentBlur();
        }
      });
    }

    // Add escape key handler
    document.addEventListener('keydown', (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        componentBlur();
      }
    });

    // Handle postMessage communication
    window.addEventListener('message', (e) => {
      const data = e.data;
      if (typeof data.type === 'string') {
        const handler = childOperations[data.type];
        if (typeof handler === 'function') {
          handler(data);
        }
      }
    });

    // Define behaviors
    const childOperations:any = {
      size: function (data: ComponentData):void {
        if (componentState === 'focus') {
          const iframe = getIframe(data.size);
          if (iframe && iframe.contentWindow) {
            iframe.contentWindow.postMessage({
              type: 'componentHover',
              uuid: component.uuid,
            }, "*");
          }
        }
      },

      onComponentHover: function(data: ComponentData):void {
        component = data.component;
        component.uuid = data.uuid;
        componentState = 'hover';
        componentInteractSize = data.size;

        // Clear existing timeout if any
        if (componentBlurTimeout) {
          clearTimeout(componentBlurTimeout);
        }

        // Handle quick mouse movement edge case
        componentBlurTimeout = setTimeout(() => {
          const isHovered = Object.values(overlays).some(overlay =>
            overlay instanceof HTMLElement && overlay.matches(':hover')
          );
          if (!isHovered) {
            componentBlur();
          }
        }, 200);

        // Notify other iframes
        notifyOtherIframes(data.size, data.uuid);
      },

      doComponentHover: function(data: ComponentData):void {
        const size = data.size;
        const overlay = overlays[size];

        if (overlay) {
          const iframe = getIframe(size);

          if (iframe && wrapper && data.rect) {
            const hasFocused = componentState === 'focus';
            positionOverlay(overlay, iframe, data.rect, hasFocused);

            // Set component label
            const label = overlay.querySelector('.label');
            if (label) {
              label.innerHTML = `<span class="px-1">${component.label}</span>`;
              if (component.warnings && component.warnings.length > 0) {
                component.warnings.forEach(warning => {
                  label.innerHTML = ` <span class="badge rounded-sm bg-warning-500 text-warning-500-content">${warning}</span>` + label.innerHTML;
                });
              }
              if (component.alerts && component.alerts.length > 0) {
                component.alerts.forEach(warning => {
                  label.innerHTML = ` <span class="badge rounded-sm bg-alert-500 text-alert-500-content">${warning}</span>` + label.innerHTML;
                });
              }
            }

            // Add transition after positioning
            setTimeout(() => {
              overlay.classList.add('!transition-all');
            });

            if (hasFocused) {
              // If we have a focused component, we need to recalculate the
              // overlay position.
              configureOverlaysAndShades();
              if (data.size === componentInteractSize) {
                Drupal.behaviors.neoAlchemistComponentParent.scrollElementIntoView(overlay, wrapper, 100);
              }
            }
          }
        }
      },

      doComponentFocus: function(data: ComponentData):void {
        component = data.component;
        component.uuid = data.uuid;
        childOperations.doComponentHover(data);
      },

      componentDoesNotExist: function(_data: ComponentData):void {
        // Component no longer exists.
        componentBlur();
      }
    };

    // Helper functions

    const onOverlayDblClick = (_e: MouseEvent) => {
      if (componentState === 'focus') {
        executeComponentOperation(component, 'edit');
      }
    };

    /**
     * Initialize overlays and shades for different viewport sizes
     */
    function initializeOverlaysAndShades(baseOverlay: HTMLElement, baseShade: HTMLElement): void {
      viewportSizes.forEach(size => {
        // Clone and set up overlay
        const cloneOverlay = baseOverlay.cloneNode(true) as HTMLElement;
        cloneOverlay.id = `neo-alchemist--overlay-${size}`;

        cloneOverlay.addEventListener('mouseleave', () => {
          if (componentState === 'hover') {
            componentBlurTimeout = setTimeout(() => {
              componentBlur();
            }, 200);
          }
        });

        cloneOverlay.addEventListener('click', (e) => {
          e.preventDefault();

          cloneOverlay.removeEventListener('dblclick', onOverlayDblClick);
          if (componentLastFocus === component.uuid) {
            cloneOverlay.addEventListener('dblclick', onOverlayDblClick);
          }

          componentFocus();

          if (wrapper) {
            // Scroll the element into the view.
            Drupal.behaviors.neoAlchemistComponentParent.scrollElementIntoView(cloneOverlay.getBoundingClientRect(), wrapper, 100);
          }
        });

        baseOverlay.insertAdjacentElement('afterend', cloneOverlay);
        overlays[size] = cloneOverlay;

        // Clone and set up shade
        const cloneShade = baseShade.cloneNode(true) as HTMLElement;
        cloneShade.id = `neo-alchemist--shade-${size}`;

        cloneShade.addEventListener('click', e => {
          e.preventDefault();
          componentBlur(0);
        });

        baseShade.insertAdjacentElement('afterend', cloneShade);
        shades[size] = cloneShade;
      });

      // Remove original elements after cloning
      baseOverlay.remove();
      baseShade.remove();
    }

    /**
     * Position overlay based on the component's position
     */
    function positionOverlay(overlay: HTMLElement, iframe: HTMLIFrameElement, rect: DOMRect, instant: boolean = false): void {
      if (!wrapper) return;

      overlay.classList.add('is-active', 'cursor-pointer');

      const wrapperRect = wrapper.getBoundingClientRect();
      const iframeRect = iframe.getBoundingClientRect();

      const scaleInt = parseFloat(scale);
      const top = wrapper.scrollTop + iframeRect.top + (rect.top * scaleInt) + window.scrollY - wrapperRect.top - 10;
      const left = wrapper.scrollLeft + iframeRect.left + (rect.left * scaleInt) + window.scrollX - wrapperRect.left;
      const height = (rect.height * scaleInt) + 20;
      const width = (rect.width * scaleInt) + 0;

      if (instant) {
        overlay.classList.remove('!transition-all');
      }

      overlay.style.top = `${top}px`;
      overlay.style.left = `${left}px`;
      overlay.style.width = `${width}px`;
      overlay.style.height = `${height}px`;

      if (instant) {
        setTimeout(() => {
          overlay.classList.add('!transition-all');
        }, 100);
      }
    }

    /**
     * Notify other iframes about component hover
     */
    function notifyOtherIframes(currentSize: string, uuid: string): void {
      getIframeExclude(currentSize).forEach(iframe => {
        if (iframe.contentWindow) {
          iframe.contentWindow.postMessage({
            type: 'componentHover',
            uuid: uuid,
          }, "*");
        }
      });
    }

    /**
     * Operations available for components
     */
    const operations: ComponentOperations = {
      edit: (component: Component): void => {
        const modalOptionsEdit: ModalOptions = {
          ...baseModalOptions,
          neo: {
            ...baseModalOptions.neo,
            contentPadding: '0px',
          },
        };

        Drupal.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/edit/${component.uuid}`,
          dialogType: 'modal',
          dialog: modalOptionsEdit,
        }).execute();
      },

      sort: (component: Component): void => {
        Drupal.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/sort?uuid=${component.uuid}`,
          dialogType: 'modal',
          dialog: baseModalOptions,
        }).execute();
      },

      delete: (component: Component): void => {
        Drupal.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/delete/${component.uuid}`,
          dialogType: 'modal',
          dialog: {
            ...baseModalOptions,
            width: 'auto',
            height: 'auto',
          },
        }).execute();
      },

      clone: (component: Component): void => {
        Drupal.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/clone/${component.uuid}`,
        }).execute();
      },

      add: (component: Component, position: string): void => {
        Drupal.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/library?${position}=${component.uuid}`,
          dialogType: 'modal',
          dialog: baseModalOptions,
        }).execute();
      },
    };

    /**
     * Execute a component operation if it's available
     *
     * @param component - The component to operate on
     * @param opKey - Key of the operation to execute
     */
    function executeComponentOperation(
      component: Component | null,
      opKey: keyof ComponentOperations
    ): void {
      const [op, spec] = opKey.includes('-') ? opKey.split('-', 2) : [opKey, undefined];
      if (component?.ops?.[opKey] && operations[op as keyof ComponentOperations]) {
        operations[op as keyof ComponentOperations](component, spec as string);
      }
    }

    /**
     * Focus on a component
     */
    function componentFocus(): void {
      if (!component || !wrapper) return;

      componentState = 'focus';

      // Update with component info
      updateComponentInfo();

      // Configure operation buttons
      configureOperationButtons();

      // Position operation panels
      positionOperationPanels();

      // Configure overlays and shades
      configureOverlaysAndShades();

      componentLastFocus = component.uuid;
    }

    /**
     * Update the component title display
     */
    function updateComponentInfo(): void {
      if (!wrapper) return;

      const title = wrapper.querySelector('.title');
      if (title) {
        title.innerHTML = `<span>${component.label}</span>`;
        if (component.warnings && component.warnings.length > 0) {
          component.warnings.forEach(warning => {
            title.innerHTML = `<span class="badge px-2 rounded bg-warning-500 text-warning-500-content">${warning}</span>` + title.innerHTML;
          });
        }
        if (component.alerts && component.alerts.length > 0) {
          component.alerts.forEach(warning => {
            title.innerHTML = `<span class="badge px-2 rounded bg-alert-500 text-alert-500-content">${warning}</span>` + title.innerHTML;
          });
        }
      }
    }

    /**
     * Configure operation buttons based on component ops
     */
    function configureOperationButtons(): void {
      if (!wrapper) return;

      // Hide all op buttons first
      const opButtons = wrapper.querySelectorAll<HTMLElement>('.op');
      opButtons.forEach(opButton => {
        opButton.style.display = 'none';
      });

      // Show only available ops
      if (component.ops) {
        Object.entries(component.ops).forEach(([opKey, status]) => {
          if (status) {
            const opButtons = wrapper.querySelectorAll<HTMLElement>(`[data-op="${opKey}"]`);
            opButtons.forEach(opButton => {
              opButton.style.display = '';
            });
          }
        });
      }
    }

    /**
     * Position operation panels
     */
    function positionOperationPanels(): void {
      if (!ops || !wrapper) return;

      ops.forEach(op => {
        const placement = op.getAttribute('data-placement');
        const rect = wrapper.getBoundingClientRect();

        switch (placement) {
          case 'top':
            op.style.top = `${rect.top}px`;
            break;
          case 'bottom':
            const offset = window.innerHeight - rect.top - rect.height;
            op.style.bottom = `${offset}px`;
            break;
        }

        op.classList.add('is-focus');
        op.style.display = '';
      });
    }

    /**
     * Configure overlays and shades for focus state
     */
    function configureOverlaysAndShades(): void {
      viewportSizes.forEach(size => {
        const iframe = getIframe(size);
        const overlay = overlays[size];
        const shade = shades[size];

        if (wrapper && iframe && overlay && shade) {
          positionShade(shade, iframe);
          createClipPath(shade, overlay, iframe);

          overlay.classList.add('is-focus');
          shade.classList.add('is-active');
        }
      });
    }

    /**
     * Position shade element
     */
    function positionShade(shade: HTMLElement, iframe: HTMLIFrameElement): void {
      if (!wrapper) return;

      const wrapperRect = wrapper.getBoundingClientRect();
      const iframeRect = iframe.getBoundingClientRect();

      shade.style.top = `${wrapper.scrollTop + iframeRect.top - wrapperRect.top}px`;
      shade.style.left = `${wrapper.scrollLeft + iframeRect.left - wrapperRect.left}px`;
      shade.style.width = `${iframeRect.width}px`;
      shade.style.height = `${iframeRect.height}px`;
    }

    /**
     * Create clip path for the shade
     */
    function createClipPath(shade: HTMLElement, overlay: HTMLElement, iframe: HTMLIFrameElement): void {
      const rect = overlay.getBoundingClientRect();
      const iframeRect = iframe.getBoundingClientRect();

      const top = rect.top - iframeRect.top;
      const left = rect.left - iframeRect.left;
      const right = left + rect.width;
      const bottom = top + rect.height;

      shade.style.clipPath = `polygon(0% 0%, 0% 100%, ${left}px 100%, ${left}px ${top}px, ${right}px ${top}px, ${right}px ${bottom}px, ${left}px ${bottom}px, ${left}px 100%, 100% 100%, 100% 0%)`;
    }

    /**
     * Blur/unfocus a component
     */
    function componentBlur(timeout: number | null = null): void {
      timeout = timeout === null ? 100 : timeout;

      if (componentBlurTimeout) {
        clearTimeout(componentBlurTimeout);
      }

      componentBlurTimeout = setTimeout(() => {
        componentBlurTimeout = null;

        // Remove focus from operation panels
        if (ops) {
          ops.forEach(op => op.classList.remove('is-focus'));
        }

        // Reset overlays and shades
        viewportSizes.forEach(size => {
          const overlay = overlays[size];
          if (overlay) {
            overlay.classList.remove('is-active', 'is-focus', '!transition-all');
          }

          const shade = shades[size];
          if (shade) {
            shade.classList.remove('is-active', '!transition-all');
          }
        });
      }, timeout);

      // Reset state
      component = {} as Component;
      componentState = null;
    }

    /**
     * Get iframe by size
     */
    function getIframe(size: string): HTMLIFrameElement | undefined {
      return Array.from(iframes).find(el => el.getAttribute('data-size') === size) as HTMLIFrameElement | undefined;
    }

    /**
     * Get all iframes except the one with the specified size
     */
    function getIframeExclude(size: string): HTMLIFrameElement[] {
      return Array.from(iframes).filter(el => el.getAttribute('data-size') !== size) as HTMLIFrameElement[];
    }
  });

})(Drupal, once);
