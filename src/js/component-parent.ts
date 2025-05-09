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
      if (elementTop < containerVisibleTop) {
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

  function init(container:HTMLElement): void {

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
    const wrapper:HTMLElement|null = container.querySelector('.neo-alchemist-manage--wrapper');
    const messages = document.querySelector('.alchemist-messages');
    const drag = container.querySelector('.neo-alchemist-manage--drag') as HTMLElement;
    if (drag) {
      dragInit(drag);
    }

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

    [
      {id: 'expand', contentHeight: '0%', formHeight: '100%', hideIframe: true, hideForm: false, active: false},
      {id: 'split', contentHeight: '50%', formHeight: '50%', hideIframe: false, hideForm: false, active: true},
      {id: 'contract', contentHeight: '100%', formHeight: '0%', hideIframe: false, hideForm: true, active: false}
    ].forEach(data => {
      once('neo.alchemist', '.neo-alchemist-manage--size-' + data.id, container).forEach(el => {
        const content = container.querySelector('.neo-alchemist-manage--wrapper') as HTMLIFrameElement;
        const form = container.querySelector('.neo-alchemist-manage--form') as HTMLIFrameElement;
        if (data.active) {
          el.classList.add('is-active');
          content.style.height = data.contentHeight;
          form.style.height = data.formHeight;
        }
        content.style.transition = 'all 500ms';
        form.style.transition = 'all 500ms';
        el.addEventListener('click', (e) => {
          e.preventDefault();
          const sizes = container.querySelectorAll('.neo-alchemist--sizing');
          sizes.forEach((el) => {
            el.classList.remove('is-active');
          });
          el.classList.add('is-active');
          content.style.height = data.contentHeight;
          content.style.transform = data.hideIframe ? 'scale(0.5)' : '';
          content.style.opacity = data.hideIframe ? '0' : '';
          form.style.height = data.formHeight;
          form.style.transform = data.hideForm ? 'scale(0.5)' : '';
          form.style.opacity = data.hideForm ? '0' : '';
        });
      });
    });

    const focusButtons = container.querySelectorAll('.neo-alchemist--focus');
    [
      {size: 'desktop', active: true},
      {size: 'tablet', active: false},
      {size: 'mobile', active: false},
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
    const padding = (document.body.clientWidth) / 3;
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

    setScale(scale);
    function setScale(scale: string): void {
      const scaleWrapper = container.querySelector('.neo-alchemist-manage--scale') as HTMLIFrameElement;
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

      // Dispatch the event on the element
      container.dispatchEvent(customEvent);
    }

    function dragInit(el:HTMLElement): void {
      let startX:number;
      let startY:number;
      let scrollLeft:number
      let scrollTop:number;

      el.addEventListener('mousedown', handleDragStart);
      function handleDragStart(e: MouseEvent): void {
        if (wrapper) {
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
          focusButtons.forEach((el) => {
            el.classList.remove('is-active');
          });
        }
      }

      function handleMouseMove(e: MouseEvent): void {
        if (wrapper) {
          const dx = e.clientX - startX;
          const dy = e.clientY - startY;
          wrapper.style.userSelect = '';
          wrapper.scrollLeft = scrollLeft - dx;
          wrapper.scrollTop = scrollTop - dy;
        }
      }

      function handleDragEnd(): void {
        el.style.cursor = 'grab';
        document.removeEventListener('mouseup', handleDragEnd);
        document.removeEventListener('mousemove', handleMouseMove);
        iframes.forEach(iframe => {
          if (iframe instanceof HTMLIFrameElement) {
            iframe.style.pointerEvents = '';
          }
        });
      }
    }

    /**
     * Get iframe by size
     */
    function getIframe(size: string): HTMLIFrameElement | undefined {
      return Array.from(iframes).find(el => el.getAttribute('data-size') === size) as HTMLIFrameElement | undefined;
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

export {};
