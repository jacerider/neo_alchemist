(function(u, i) {
  function s(e, r) {
    let t;
    return function(...c) {
      t && clearTimeout(t), t = setTimeout(() => {
        e.apply(this, c);
      }, r);
    };
  }
  let n = null;
  function a() {
    n && n.dispatchEvent(new Event("mousedown", {
      bubbles: !0,
      cancelable: !0
    }));
  }
  const o = s(a, 250);
  u.behaviors.neoAlchemistInstanceComponentForm = {
    attach: function() {
      i("neo.alchemist", "#neo-alchemist--instance-component-form").forEach((e) => {
        e.addEventListener("input", o), new MutationObserver((t) => {
          t.forEach((c) => {
            o();
          });
        }).observe(e, { childList: !0, subtree: !0 }), n = e.querySelector("#neo-alchemist--refresh");
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-form.js.map
