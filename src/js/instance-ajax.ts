(function (Drupal) {

  if (Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.neoAlchemistInstanceComponentPreviewIframe = function (_ajax, response, _status) {
      const iframe = document.querySelector(response.selector);
      if (iframe instanceof HTMLIFrameElement) {
        iframe.contentDocument?.location.reload();
      }
    } as drupal.Core.IAjaxCommand;
  }

})(Drupal);

export {};
