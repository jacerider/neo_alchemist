(function (Drupal, once) {

  Drupal.behaviors.neoAlchemistComponentParent = {
    scale: 1,

    attach: function () {
      once('neo.alchemist.component.parent', '.neo-alchemist-manage').forEach(container => {
        init(container);
      });
    },

    scrollElementIntoView: function (
      element: HTMLElement|DOMRect,
      container: HTMLElement = document.documentElement,
      offset: number | { top?: number; bottom?: number; left?: number; right?: number } = 0,
      behavior: ScrollBehavior = 'smooth'
    ): void {
      // Get positions
      const containerRect = container.getBoundingClientRect();
      const elementRect = element instanceof DOMRect ? element : element.getBoundingClientRect();

      // Normalize offset to object
      const offsets = typeof offset === 'number'
        ? { top: offset, bottom: offset, left: offset, right: offset }
        : { top: 0, bottom: 0, left: 0, right: 0, ...offset };

      // Calculate positions
      const isRoot = container === document.documentElement;

      // Vertical calculations
      const elementTop = elementRect.top - (isRoot ? 0 : containerRect.top);
      const elementBottom = elementRect.bottom - (isRoot ? 0 : containerRect.top);
      const containerVisibleTop = isRoot ? 0 : offsets.top;
      const containerVisibleBottom = (isRoot ? window.innerHeight : containerRect.height) - offsets.bottom;

      // Horizontal calculations
      const elementLeft = elementRect.left - (isRoot ? 0 : containerRect.left);
      const elementRight = elementRect.right - (isRoot ? 0 : containerRect.left);
      const containerVisibleLeft = 0;
      const containerVisibleRight = (isRoot ? window.innerWidth : containerRect.width);

      // Calculate scroll values
      let scrollTop = container.scrollTop;
      let scrollLeft = container.scrollLeft;
      let needsScroll = false;

      // Determine if vertical scrolling is needed
      if (elementRect.height > containerRect.height) {
        // alert('help');
      }
      else if (elementTop < containerVisibleTop) {
        // Element is above the visible area, scroll up
        scrollTop += elementTop - offsets.top;
        needsScroll = true;
      }
      else if (elementBottom > containerVisibleBottom) {
        // Element is above the visible area, scroll up
        scrollTop += elementTop - offsets.top;
        needsScroll = true;
      }

      // Determine if horizontal scrolling is needed
      if (containerRect.width < elementRect.width) {
        // We need to center the element
        const elementCenter = (elementLeft + elementRight) / 2;
        const containerCenter = (containerVisibleLeft + containerVisibleRight) / 2;
        const offsetLeft = elementCenter - containerCenter;
        scrollLeft += offsetLeft;
        needsScroll = true;
      } else if (elementLeft < containerVisibleLeft) {
        // Element is to the left of the visible area, scroll left
        scrollLeft += elementLeft - offsets.left;
        needsScroll = true;
      } else if (elementRight > containerVisibleRight) {
        // Element is to the right of the visible area, scroll right
        scrollLeft += elementRight - containerVisibleRight + offsets.right;
        needsScroll = true;
      }

      // Apply scroll if needed
      if (needsScroll) {
        container.scrollTo({
          top: scrollTop,
          left: scrollLeft,
          behavior
        });
      }
    }
  };

  function waitForAllIframesToLoad(iframes: NodeListOf<HTMLIFrameElement>): Promise<void> {
    if (iframes) {
      const promises = Array.from(iframes).map((iframe) => {
        return new Promise<void>((resolve) => {
          if (iframe.contentDocument?.readyState === 'complete') {
            resolve();
          } else {
            iframe.addEventListener('load', () => resolve(), { once: true });
          }
        });
      });

      return Promise.all(promises).then(() => {});
    }
    return Promise.resolve();
  }

  function init(container:HTMLElement): void {
    const id = container.id;

    window.addEventListener('message', function (e) {
      const data = e.data;
      if (typeof data.id !== 'string') {
        return;
      }
      if (data.id !== container.id) {
        return;
      }
      if (typeof data.type === 'string') {
        if (typeof operations[data.type] !== 'function') {
          return;
        }
        operations[data.type](data);
      }
    });

    let initialized: boolean = false;
    const iframes = container.querySelectorAll('iframe');
    const wrapper = container.querySelector('.neo-alchemist-manage--wrapper') as HTMLElement;
    const messages = document.querySelector('.alchemist-messages');
    const formWrapper = container.querySelector('.neo-alchemist-manage--form-wrapper') as HTMLElement;
    const scroll = container.querySelector('.neo-alchemist-manage--form-scroll') as HTMLElement;
    const form = container.querySelector('.neo-alchemist-manage--form') as HTMLIFrameElement;

    const drag = container.querySelector('.neo-alchemist-manage--drag') as HTMLElement;
    waitForAllIframesToLoad(iframes).then(() => {
      if (drag) {
        dragInit(drag);
      }
    });

    const screenshotButton = document.getElementById('neo-alchemist-thumbnail-generate-button');
    if (screenshotButton) {
      screenshotButton.addEventListener('click', (e) => {
        e.preventDefault();
        screenshotButton.setAttribute('disabled', 'disabled');
        const iframe = getIframe('desktop');
        if (iframe && iframe.contentWindow) {
          iframe.contentWindow.postMessage({
            type: 'screenshot',
          }, "*");
        }
      });
    }

    if (messages) {
      setTimeout(() => {
        messages.classList.add('opacity-100');
        messages.classList.remove('invisible', 'opacity-0');
        setTimeout(() => {
          const debug = messages.querySelector('.sf-dump') || messages.querySelector('.kint-rich');
          if (!debug) {
            const children = messages.querySelector('.messages--wrapper') as HTMLElement;
            if (children) {
              fadeOutAndRemove(children);
            }
          }
        }, 3000);
      }, 100);
    }

    // Watch for errors.
    iframes.forEach(iframe => {
      iframe.onload = () => {
        if (iframe.contentWindow) {
          const html = iframe.contentWindow.document.querySelector('html');
          if (html && !html.classList.contains('js') && wrapper) {
            wrapper.style.visibility = '';
            iframe.style.height = html.offsetHeight + 'px';
          }
        }
      };
    });

    if (scroll) {
      const resizeObserver = new ResizeObserver(() => {
        scroll.style.width = '';
        if (scroll && scroll.scrollWidth > scroll.offsetWidth) {
          // Get the parent's padding
          const computedStyle = window.getComputedStyle(scroll);
          const paddingRight = parseFloat(computedStyle.paddingRight);
          scroll.style.width = scroll.offsetWidth + paddingRight + (scroll.scrollWidth - scroll.offsetWidth) + 'px';
        }
      });
      resizeObserver.observe(form);

      const expand = formWrapper.querySelector('.neo-alchemist-manage--expand') as HTMLElement;
      const collapse = formWrapper.querySelector('.neo-alchemist-manage--collapse') as HTMLElement;
      if (drag && expand && collapse) {
        expand.addEventListener('click', function (e) {
          e.preventDefault();
          resizeObserver.disconnect();
          drag.style.opacity = '0';
          scroll.style.width = '';
          expand.classList.toggle('hidden');
          collapse.classList.toggle('hidden');
          scroll.classList.toggle('expanded');
        });
        collapse.addEventListener('click', function (e) {
          e.preventDefault();
          drag.style.opacity = '';
          expand.classList.toggle('hidden');
          collapse.classList.toggle('hidden');
          scroll.classList.toggle('expanded');
          resizeObserver.observe(form);
        });
      }
    }

    const active: string = localStorage.getItem('neo-alchemist-size') || 'split';
    [
      {id: 'expand', contentHeight: '0%', formHeight: '100%', hideIframe: true, hideForm: false, active: active === 'expand'},
      {id: 'split', contentHeight: '50%', formHeight: '50%', hideIframe: false, hideForm: false, active: active === 'split'},
      {id: 'contract', contentHeight: '100%', formHeight: '0%', hideIframe: false, hideForm: true, active: active === 'contract'}
    ].forEach(data => {
      once('neo.alchemist', '.neo-alchemist-manage--size-' + data.id, container).forEach(el => {
        if (data.active) {
          el.classList.add('is-active');
          wrapper.style.height = data.contentHeight;
          formWrapper.style.height = data.formHeight;
        }
        wrapper.style.transition = 'all 500ms';
        formWrapper.style.transition = 'all 500ms';
        el.addEventListener('click', (e) => {
          e.preventDefault();
          const sizes = container.querySelectorAll('.neo-alchemist--sizing');
          sizes.forEach((el) => {
            el.classList.remove('is-active');
          });
          localStorage.setItem('neo-alchemist-size', data.id);
          el.classList.add('is-active');
          wrapper.style.height = data.contentHeight;
          wrapper.style.transform = data.hideIframe ? 'scale(0.5)' : '';
          wrapper.style.opacity = data.hideIframe ? '0' : '';
          formWrapper.style.height = data.formHeight;
          formWrapper.style.transform = data.hideForm ? 'scale(0.5)' : '';
          formWrapper.style.opacity = data.hideForm ? '0' : '';
        });
      });
    });

    let sizeCount = 0;
    const operations:any = {
      size: function (data:any) {
        const size = data.size;
        const height = Math.max(data.height, 0);
        const iframe = Array.from(iframes).find(el =>
          el.getAttribute('data-size') === size
        );
        if (iframe instanceof HTMLIFrameElement) {
          sizeCount++;
          iframe.style.height = height + 'px';
          const iframeWrapper = iframe.closest('.neo-alchemist--iframe-wrapper') as HTMLIFrameElement;
          const iframeSize = iframeWrapper?.querySelector('.neo-alchemist--iframe-size') as HTMLIFrameElement;
          if (iframeSize) {
            iframeSize.innerHTML = iframe.clientWidth + '×' + height;
          }
          if (wrapper && sizeCount === iframes.length) {
            wrapper.style.visibility = '';
          }
        }
      },

      messages: function (data:any) {
        const messages = document.querySelector('.alchemist-messages');
        if (messages) {
          const messagesContent = document.createElement('div');
          messagesContent.classList.add('neo-alchemist--messages-content');
          messagesContent.innerHTML = data.messages;
          messages.appendChild(messagesContent);
          setTimeout(() => {
            fadeOutAndRemove(messagesContent);
          }, 3000);
        }
      },

      screenshot: function (data:any) {
        const screenshotButton = document.getElementById('neo-alchemist-thumbnail-generate-button');
        if (screenshotButton instanceof HTMLButtonElement) {
          // set button value to 'awesome'
          screenshotButton.innerHTML = 'Image Generated <small>Save component to finish capture</small>';
        }
        const screenshotData = document.getElementById('neo-alchemist-thumbnail-generate-data') as HTMLTextAreaElement;
        if (screenshotData) {
          screenshotData.value = data.dataUrl;
        }
      },
    };

    // Pad the edges of the drag area
    const padding = Math.floor(document.body.clientWidth * 0.9);
    drag.style.paddingLeft = padding + 'px';
    drag.style.paddingRight = padding + 'px';

    const scale:string = localStorage.getItem('neo-alchemist-scale') || '1';
    const scaleButtons = container.querySelectorAll('.neo-alchemist--scale');
    [
      {size: 'full', scale: '1'},
      {size: '75', scale: '0.75'},
      {size: '50', scale: '0.5'},
    ].forEach(data => {
      once('neo.alchemist', '.neo-alchemist--scale[data-size="' + data.size + '"]').forEach(el => {
        if (scale === data.scale) {
          el.classList.add('is-active');
        }
        el.addEventListener('click', (e) => {
          e.preventDefault();
          scaleButtons.forEach((el) => {
            el.classList.remove('is-active');
          });
          el.classList.add('is-active');
          setScale(data.scale);
        });
      });
    });

    const scaleWrapper = container.querySelector('.neo-alchemist-manage--scale') as HTMLIFrameElement;
    setScale(scale);
    scaleWrapper.addEventListener('transitionend', (event: TransitionEvent) => {
      // Check if the transition was specifically for transform
      if (event.propertyName === 'transform') {
        const customEvent = new CustomEvent('alchemistManageScaleEnd');
        container.dispatchEvent(customEvent);
      }
    });
    function setScale(scale: string): void {
      if (scaleWrapper) {
        if (!initialized) {
          scaleWrapper.style.transformOrigin = 'top left';
        }
        scaleWrapper.style.transform = `scale(${scale})`;
        if (wrapper) {
          // Reset position each time scale is changed
          const rect = scaleWrapper.getBoundingClientRect();
          wrapper.scrollTo({
            top: 0,
            left: rect.left + wrapper.scrollLeft,
            behavior: initialized ? 'smooth' : 'auto',
          });
        }
        if (!initialized) {
          scaleWrapper.style.transition = 'transform 0.2s ease-in-out';
        }
      }

      localStorage.setItem('neo-alchemist-scale', scale);
      const customEvent = new CustomEvent('alchemistManageScale', {
        bubbles: true,
        cancelable: true,
        detail: {
          scale: scale,
        }
      });
      container.dispatchEvent(customEvent);
    }

    function dragInit(el:HTMLElement): void {
      let startX:number;
      let startY:number;
      let scrollLeft:number
      let scrollTop:number;

      if (wrapper) {
        const initScrollLeft = parseInt(localStorage.getItem(id + '-scroll-l') || '0');
        const initScrollTop = parseInt(localStorage.getItem(id + '-scroll-t') || '0');
        if (initScrollLeft) {
          wrapper.scrollLeft = initScrollLeft;
        }
        if (initScrollTop) {
          wrapper.scrollTop = initScrollTop;
        }
      }

      const focusButtons = container.querySelectorAll('.neo-alchemist--focus');
      const mostVisibleSize = getMostVisibleIframe()?.getAttribute('data-size') || null;
      [
        {size: 'desktop', active: mostVisibleSize === 'desktop'},
        {size: 'tablet', active: mostVisibleSize === 'tablet'},
        {size: 'mobile', active: mostVisibleSize === 'mobile'},
      ].forEach(data => {
        once('neo.alchemist', '.neo-alchemist--focus[data-size="' + data.size + '"]').forEach(el => {
          if (data.active) {
            el.classList.add('is-active');
          }
          el.addEventListener('click', (e) => {
            e.preventDefault();
            const iframe = getIframe(data.size as string);
            if (iframe) {
              focusButtons.forEach((el) => {
                el.classList.remove('is-active');
              });
              el.classList.add('is-active');
              iframe.closest('.neo-alchemist--iframe-wrapper')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
                inline: 'center',
              });
            }
          });
        });
      });

      function allowDrag(el: HTMLElement): boolean {
        // Check if target has data-alchemist-ignore
        if (el.dataset.alchemistIgnore !== undefined || el.closest('[data-alchemist-nodrag]')) {
          return false;
        }
        return true;
      }

      el.addEventListener('mousedown', handleDragStart);
      let moved: boolean;
      function handleDragStart(e: MouseEvent): void {
        if (!allowDrag(e.target as HTMLElement)) {
          return;
        }
        if (wrapper) {
          moved = false;
          wrapper.style.userSelect = 'none';
          el.style.cursor = 'grabbing';
          startX = e.clientX;
          startY = e.clientY;
          scrollLeft = wrapper.scrollLeft;
          scrollTop = wrapper.scrollTop;
          document.addEventListener('mouseup', handleDragEnd);
          document.addEventListener('mousemove', handleMouseMove);
          iframes.forEach(iframe => {
            if (iframe instanceof HTMLIFrameElement) {
              iframe.style.pointerEvents = 'none';
            }
          });
        }
      }

      function handleMouseMove(e: MouseEvent): void {
        if (wrapper) {
          const dx = e.clientX - startX;
          const dy = e.clientY - startY;
          moved = true;
          wrapper.style.userSelect = '';
          wrapper.scrollLeft = scrollLeft - dx;
          wrapper.scrollTop = scrollTop - dy;
        }
      }

      function handleDragEnd(): void {
        if (wrapper) {
          localStorage.setItem(id + '-scroll-l', wrapper.scrollLeft.toString());
          localStorage.setItem(id + '-scroll-t', wrapper.scrollTop.toString());
          el.style.cursor = 'grab';
          document.removeEventListener('mouseup', handleDragEnd);
          document.removeEventListener('mousemove', handleMouseMove);
          iframes.forEach(iframe => {
            if (iframe instanceof HTMLIFrameElement) {
              iframe.style.pointerEvents = '';
            }
          });
          if (moved) {
            focusButtons.forEach((el) => {
              el.classList.remove('is-active');
            });
            const mostVisibleSize = getMostVisibleIframe()?.getAttribute('data-size') || null;
            if (mostVisibleSize) {
              const focusButton = container.querySelector('.neo-alchemist--focus[data-size="' + mostVisibleSize + '"]');
              if (focusButton) {
                focusButton.classList.add('is-active');
              }
            }
          }
        }
      }
    }

    /**
     * Get iframe by size
     */
    function getIframe(size: string): HTMLIFrameElement | undefined {
      return Array.from(iframes).find(el => el.getAttribute('data-size') === size) as HTMLIFrameElement | undefined;
    }

    /**
     * Get the most visible iframe within the container
     */
    function getMostVisibleIframe(): HTMLElement | null {
      const children = Array.from(iframes) as HTMLElement[];
      const containerRect = container.getBoundingClientRect();

      let mostVisibleDiv: HTMLElement | null = null;
      let maxVisibleArea = 0;
      let foundFullyVisible = false;

      children.forEach((child) => {
        const childRect = child.getBoundingClientRect();

        // Calculate the visible portion
        const visibleLeft = Math.max(childRect.left, containerRect.left);
        const visibleRight = Math.min(childRect.right, containerRect.right);

        // Calculate visible width (0 if not visible at all)
        const visibleWidth = Math.max(0, visibleRight - visibleLeft);

        // Check if element is fully visible
        const isFullyVisible =
          childRect.left >= containerRect.left &&
          childRect.right <= containerRect.right;

        // If we found a fully visible element and haven't found one before, prioritize it
        if (isFullyVisible && !foundFullyVisible) {
          mostVisibleDiv = child;
          maxVisibleArea = visibleWidth;
          foundFullyVisible = true;
        }
        // If we already found a fully visible element, ignore partially visible ones
        else if (!foundFullyVisible && visibleWidth > maxVisibleArea) {
          maxVisibleArea = visibleWidth;
          mostVisibleDiv = child;
        }
      });

      return mostVisibleDiv;
    }

    initialized = true;
  };

  /**
   * Fades out an element and moves it up 1rem while removing it from the DOM
   * @param element The element to fade out and remove (or its ID as string)
   * @param duration The duration of the fade-out animation in milliseconds
   * @param callback Optional callback function to run after element is removed
   */
  function fadeOutAndRemove(
    element: HTMLElement | string,
    duration: number = 500,
    callback?: () => void
  ): void {
    // Get the element if a string ID was provided
    const targetElement = typeof element === 'string'
      ? document.getElementById(element)
      : element;

    // Return if element doesn't exist
    if (!targetElement) {
      console.error(`Element ${typeof element === 'string' ? element : 'provided'} not found`);
      return;
    }

    // Store the element's original opacity
    const originalOpacity = window.getComputedStyle(targetElement).opacity;

    // Ensure the element is visible
    targetElement.style.opacity = originalOpacity;

    // Store the original position information
    const originalPosition = window.getComputedStyle(targetElement).position;

    // Set relative positioning if the element isn't already positioned
    if (originalPosition === 'static') {
      targetElement.style.position = 'relative';
    }

    // Add CSS transition for both opacity and transform
    targetElement.style.transition = `opacity ${duration}ms ease, transform ${duration}ms ease`;

    // Start the fade out and move up
    targetElement.style.opacity = '0';
    targetElement.style.transform = 'translateY(-1rem)';

    // Remove the element after the transition completes
    setTimeout(() => {
      targetElement.parentNode?.removeChild(targetElement);

      // Call the callback function if provided
      if (callback) {
        callback();
      }
    }, duration);
  }

})(Drupal, once);
