(function(e) {
  e.AjaxCommands && (e.AjaxCommands.prototype.neoAlchemistInstanceComponentPreviewIframe = function(a, t, c) {
    var o;
    const n = document.querySelector(t.selector);
    n instanceof HTMLIFrameElement && ((o = n.contentDocument) == null || o.location.reload());
  });
})(Drupal);
//# sourceMappingURL=instance-ajax.js.map
