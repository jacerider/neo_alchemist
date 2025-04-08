(function (Drupal, drupalSettings) {

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
    edit: (uuid: string) => {
      const modalOptionsEdit = Object.assign({}, modalOptions);
      modalOptionsEdit.neo = {...modalOptionsEdit.neo, ...{contentPadding: '0px'}};
      Drupal.ajax({
        url: drupalSettings.neoAlchemist.baseUrl + '/edit/' + uuid,
        dialogType: 'modal',
        dialog: modalOptionsEdit,
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
        dialog: {...modalOptions, ...{width: 'auto', height: 'auto'}},
      }).execute();
    },

    clone: (uuid: string) => {
      Drupal.ajax({
        url: drupalSettings.neoAlchemist.baseUrl + '/clone/' + uuid,
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

})(Drupal, drupalSettings);

export {};
