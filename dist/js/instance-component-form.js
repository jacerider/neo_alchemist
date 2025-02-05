(function(i, c) {
  function u(e, s) {
    let t;
    return function(...r) {
      t && clearTimeout(t), t = setTimeout(() => {
        e.apply(this, r);
      }, s);
    };
  }
  let o = null;
  function l() {
    o && o.dispatchEvent(new Event("mousedown", {
      bubbles: !0,
      cancelable: !0
    }));
  }
  const a = u(l, 250);
  i.behaviors.neoAlchemistInstanceComponentForm = {
    attach: function() {
      c("neo.alchemist", "#neo-alchemist--instance-component-form [data-autocomplete-path]").forEach((e) => {
        jQuery(e).on("autocompleteselect", function(s) {
          a();
        });
      }), c("neo.alchemist", "#neo-alchemist--instance-component-form").forEach((e) => {
        e.addEventListener("input", function(t) {
          if (t.target instanceof HTMLInputElement) {
            if (t.target.dataset.autocompletePath || t.target.dataset.once && t.target.dataset.once.includes("drupal-ajax"))
              return;
            a();
          }
        }), new MutationObserver((t) => {
          t.forEach((r) => {
            const n = r.target;
            n.classList.contains("ts-dropdown") || n.classList.contains("highlight") || n.closest(".ts-dropdown") || n.classList.contains("ts-wrapper") || a();
          });
        }).observe(e, { childList: !0, subtree: !0 }), o = e.querySelector("#neo-alchemist--refresh");
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-form.js.map
