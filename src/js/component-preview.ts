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
    }
  };

})(Drupal, once);

export {};
