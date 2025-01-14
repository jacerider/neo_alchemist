(function (Drupal) {

  if (Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.neoAlchemistInstanceComponentPreviewIframe = function (_ajax, response, _status) {
      const iframe = document.getElementById(response.selector) as HTMLIFrameElement;
      if (iframe) {
        iframe.contentDocument?.location.reload();
      }
    } as drupal.Core.IAjaxCommand;
  }

})(Drupal);

export {};
