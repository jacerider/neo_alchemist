(function (Drupal, drupalSettings, displace) {
  const iframe = document.getElementById('neo-alchemist--iframe') as HTMLIFrameElement;

  window.addEventListener('message', function (e) {
    // Get the sent data
    const data = JSON.parse(e.data);
    if (typeof data.type === 'string') {
      const parts = data.type.split('-');
      const op = parts[0];
      const spec = parts[1] ?? null;
      if (typeof Drupal.behaviors.neoAlchemistInstanceComponentManage[op] !== 'function') {
        return;
      }
      Drupal.behaviors.neoAlchemistInstanceComponentManage[op](data.uuid, spec);
    }
  });

  const modalOptions = {
    width: '100%',
    height: '100%',
    neo: {
      displaceTop: '0px',
      displaceBottom: '0px',
    },
  };

  Drupal.behaviors.neoAlchemistInstanceComponentManage = {
    attach: function () {
      if (displace) {
        displace(true);
      }

      [
        {id: 'sm', width: '440px', active: false},
        {id: 'md', width: '768px', active: false},
        {id: 'lg', width: '100%', active: true},
      ].forEach((data) => {
        once('neo.alchemist', '#neo-alchemist--resize-' + data.id).forEach(el => {
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

    edit: (uuid: string) => {
      Drupal.ajax({
        url: drupalSettings.neoAlchemist.baseUrl + '/edit/' + uuid,
        dialogType: 'modal',
        dialog: modalOptions,
      }).execute();
    },

    sort: (uuid: string) => {
      Drupal.ajax({
        url: drupalSettings.neoAlchemist.baseUrl + '/sort?uuid=' + uuid,
        dialogType: 'modal',
        dialog: modalOptions,
      }).execute();
    },

    delete: (uuid: string) => {
      Drupal.ajax({
        url: drupalSettings.neoAlchemist.baseUrl + '/delete/' + uuid,
        dialogType: 'modal',
        dialog: modalOptions,
      }).execute();
    },

    clone: (uuid: string) => {
      Drupal.ajax({
        url: drupalSettings.neoAlchemist.baseUrl + '/clone/' + uuid,
        // dialogType: 'modal',
        // dialog: modalOptions,
      }).execute();
    },

    add: (uuid: string, position: string) => {
      Drupal.ajax({
        url: drupalSettings.neoAlchemist.baseUrl + `/library?${position}=${uuid}`,
        dialogType: 'modal',
        dialog: modalOptions,
      }).execute();
    },
  };

  if (Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.neoAlchemistInstanceComponentPreviewIframe = function (_ajax, _response, _status) {
      if (iframe) {
        iframe.contentDocument?.location.reload();
      }
    } as drupal.Core.IAjaxCommand;
  }

})(Drupal, drupalSettings, Drupal.displace);

export {};
