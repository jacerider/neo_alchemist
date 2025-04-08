(function(t) {
  t.AjaxCommands && (t.AjaxCommands.prototype.neoAlchemistComponentIframeReload = function(c, n, s) {
    document.querySelectorAll(n.selector).forEach((e) => {
      var a;
      e instanceof HTMLIFrameElement && e instanceof HTMLIFrameElement && ((a = e.contentDocument) == null || a.location.reload());
    });
  }, t.AjaxCommands.prototype.neoAlchemistComponentFocus = function(c, n, s) {
    const o = document.querySelector(".neo-alchemist-manage");
    if (o) {
      const e = new CustomEvent("alchemistManageComponentFocus", {
        bubbles: !0,
        cancelable: !0,
        detail: {
          uuid: n.uuid
        }
      });
      o.dispatchEvent(e);
    }
  });
})(Drupal);
//# sourceMappingURL=component-ajax.js.map
