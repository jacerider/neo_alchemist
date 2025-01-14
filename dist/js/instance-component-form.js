(function(o, c) {
  function i(e, a) {
    let n;
    return function(...s) {
      n && clearTimeout(n), n = setTimeout(() => {
        e.apply(this, s);
      }, a);
    };
  }
  let t = null;
  function u() {
    t && t.dispatchEvent(new Event("mousedown", {
      bubbles: !0,
      cancelable: !0
    }));
  }
  const r = i(u, 250);
  o.behaviors.neoAlchemistInstanceComponentForm = {
    attach: function() {
      c("neo.alchemist", "#neo-alchemist--instance-component-form").forEach((e) => {
        e.addEventListener("input", r), t = e.querySelector("#neo-alchemist--refresh");
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-form.js.map
