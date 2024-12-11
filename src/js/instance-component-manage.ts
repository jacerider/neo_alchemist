(function (Drupal, drupalSettings, displace) {
  const iframe = document.getElementById('neo-alchemist--iframe') as HTMLIFrameElement;
  // iframe.style.maxWidth = '100%';

  window.addEventListener('message', function (e) {
    // Get the sent data
    const data = JSON.parse(e.data);
    if (typeof data.type === 'string') {
      console.log(data);
      switch (data.type) {
        case 'edit':
          return Drupal.behaviors.neoAlchemistInstanceComponentManage.edit(data.uuid);
        case 'sort':
          return Drupal.behaviors.neoAlchemistInstanceComponentManage.sort(data.uuid);
        case 'delete':
          return Drupal.behaviors.neoAlchemistInstanceComponentManage.delete(data.uuid);
        case 'add-before':
          return Drupal.behaviors.neoAlchemistInstanceComponentManage.add(data.uuid, 'before');
        case 'add-after':
          return Drupal.behaviors.neoAlchemistInstanceComponentManage.add(data.uuid, 'after');
      }
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
              // console.log(data, iframe);
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
