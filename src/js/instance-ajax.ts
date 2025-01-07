(function (Drupal) {

  if (Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.neoAlchemistInstanceComponentPreviewIframe = function (_ajax, _response, _status) {
      const iframe = document.getElementById('neo-alchemist--iframe') as HTMLIFrameElement;
      if (iframe) {
        iframe.contentDocument?.location.reload();
      }
    } as drupal.Core.IAjaxCommand;
  }

})(Drupal);

export {};
