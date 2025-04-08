(function (Drupal) {

  if (Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.neoAlchemistComponentIframeReload = function (_ajax, response, _status) {
      const iframes = document.querySelectorAll(response.selector);
      iframes.forEach(iframe => {
        if (iframe instanceof HTMLIFrameElement) {
          if (iframe instanceof HTMLIFrameElement) {
            iframe.contentDocument?.location.reload();
          }
        }
      });
    } as drupal.Core.IAjaxCommand;

    Drupal.AjaxCommands.prototype.neoAlchemistComponentFocus = function (_ajax, response, _status) {
      const container = document.querySelector('.neo-alchemist-manage');
      if (container) {
        const customEvent = new CustomEvent('alchemistManageComponentFocus', {
          bubbles: true,
          cancelable: true,
          detail: {
            uuid: response.uuid,
          }
        });
        // Dispatch the event on the element
        container.dispatchEvent(customEvent);
      }
      // const iframes = document.querySelectorAll(response.selector);
      // iframes.forEach(iframe => {
      //   if (iframe instanceof HTMLIFrameElement) {
      //     if (iframe instanceof HTMLIFrameElement) {
      //       iframe.contentDocument?.location.reload();
      //     }
      //   }
      // });
    } as drupal.Core.IAjaxCommand;
  }

})(Drupal);

export {};
