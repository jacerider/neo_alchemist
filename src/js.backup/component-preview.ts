(function (Drupal, once) {
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

  Drupal.behaviors.neoAlchemistComponentPreview = {
    attach: function () {
      once('neo.alchemist.disable', '[data-component-id] a').forEach(el => {
        el.setAttribute('aria-disabled', 'true');
        el.addEventListener('click', (e) => {
          e.preventDefault();
        });
      });

      once('neo.alchemist', '#neo-alchemist-preview').forEach(el => {
        dragInit(el);
      });

      once('neo.alchemist', '.neo-alchemist-preview').forEach(el => {
        const message = JSON.stringify({
          type: 'focus',
          width: document.documentElement.scrollWidth,
          height: document.documentElement.scrollHeight,
          offsetLeft: el.offsetLeft,
          offsetTop: el.offsetTop,
          offsetWidth: el.offsetWidth,
          offsetHeight: el.offsetHeight
        });
        window.parent.postMessage(message, '*');
      });
    }
  };

  function dragInit(dragArea: HTMLElement): void {
    let baseMouseX: number;
    let baseMouseY: number;
    dragArea.addEventListener('mousedown', handleDragStart);

    function handleDragStart(evt: MouseEvent): void {
      if (evt.target instanceof HTMLElement) {
        if (!evt.target.classList.contains('drag')) {
          return;
        }
      }
      if (dragArea) {
        dragArea.style.userSelect = 'none';
      }
      baseMouseX = evt.clientX;
      baseMouseY = evt.clientY;
      const message = JSON.stringify({
        type: 'drag_start',
        mouseX: baseMouseX,
        mouseY: baseMouseY
      });
      window.parent.postMessage(message, '*');
      document.addEventListener('mouseup', handleDragEnd);
      document.addEventListener('mousemove', handleMouseMove);
    }

    function handleMouseMove(evt: MouseEvent): void {
      const message = JSON.stringify({
        type: 'drag_move',
        offsetX: (evt.clientX - baseMouseX) * -1,
        offsetY: (evt.clientY - baseMouseY) * -1
      });
      window.parent.postMessage(message, '*');
    }

    function handleDragEnd(): void {
      if (dragArea) {
        dragArea.style.userSelect = '';
      }
      const message = JSON.stringify({
          type: 'drag_end'
      });
      window.parent.postMessage(message, '*');
      document.removeEventListener('mouseup', handleDragEnd);
      document.removeEventListener('mousemove', handleMouseMove);
    }
  }

})(Drupal, once);

export {};
