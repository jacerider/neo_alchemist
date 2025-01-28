(function (Drupal, once, displace) {

  Drupal.behaviors.neoAlchemistComponentManage = {
    attach: function () {
      if (displace) {
        displace(true);
      }

      once('neo.alchemist', '#neo-alchemist--messages').forEach(messages => {
        setTimeout(() => {
          messages.classList.add('transition-all');
          messages.classList.remove('opacity-0', '-translate-y-full');
        }, 100);
        const hasDebug = messages.querySelector('.kint-rich');
        if (!hasDebug) {
          setTimeout(() => {
            messages?.classList.add('opacity-0', '-translate-y-full');
          }, 4000);
        }
      });

      [
        {id: 'expand', iframeHeight: '0%', formHeight: '100%', hideIframe: true, hideForm: false, active: false},
        {id: 'split', iframeHeight: '50%', formHeight: '50%', hideIframe: false, hideForm: false, active: true},
        {id: 'contract', iframeHeight: '100%', formHeight: '0%', hideIframe: false, hideForm: true, active: false}
      ].forEach(data => {
        once('neo.alchemist', '#neo-alchemist--size-' + data.id).forEach(el => {
          const iframe = document.getElementById('neo-alchemist--iframe-form-wrapper') || document.getElementById('neo-alchemist--iframe-wrapper') as HTMLIFrameElement;
          const form = document.getElementById('neo-alchemist--form') || document.getElementById('neo-alchemist--iframe') as HTMLIFrameElement;
          if (data.active) {
            el.classList.add('is-active');
            iframe.style.height = data.iframeHeight;
            form.style.height = data.formHeight;
          }
          iframe.style.transition = 'all 500ms';
          form.style.transition = 'all 500ms';
          el.addEventListener('click', (e) => {
            e.preventDefault();
            const sizes = document.querySelectorAll('.neo-alchemist--sizing');
            sizes.forEach((el) => {
              el.classList.remove('is-active');
            });
            el.classList.add('is-active');
            iframe.style.height = data.iframeHeight;
            iframe.style.transform = data.hideIframe ? 'scale(0.5)' : '';
            iframe.style.opacity = data.hideIframe ? '0' : '';
            form.style.height = data.formHeight;
            form.style.transform = data.hideForm ? 'scale(0.5)' : '';
            form.style.opacity = data.hideForm ? '0' : '';
            // form.classList.add('h-11/12');
            // form.classList.remove('flex-1');
          });

        });
      });

      [
        {id: 'sm', width: '440px', active: false},
        {id: 'md', width: '768px', active: false},
        {id: 'lg', width: '100%', active: true},
      ].forEach(data => {
        once('neo.alchemist', '#neo-alchemist--resize-' + data.id).forEach(el => {
          const iframe = document.getElementById('neo-alchemist--iframe-form') || document.getElementById('neo-alchemist--iframe') as HTMLIFrameElement;
          if (data.active) {
            el.classList.add('is-active');
          }
          el.addEventListener('click', (e) => {
            e.preventDefault();
            if (iframe) {
              document.querySelectorAll('.neo-alchemist--resize').forEach((el) => {
                el.classList.remove('is-active');
              });
              el.classList.add('is-active');
              iframe.style.maxWidth = data.width;
            }
          });
        });
      });
    },
  };

})(Drupal, once, Drupal.displace);

export {};
