(function(o, s) {
  function l(n, t) {
    let e;
    return function(...r) {
      e && clearTimeout(e), e = setTimeout(() => {
        n.apply(this, r);
      }, t);
    };
  }
  function m() {
    console.log("Refreshing Neo Alchemist instance component form");
    const n = jQuery("#neo-alchemist--instance-component-form");
    if (o.Ajax) {
      c = null;
      const t = {
        callback: "::ajaxRefresh",
        dialogType: "ajax",
        event: "none",
        httpMethod: "POST",
        keypress: !0,
        selector: "#neo-alchemist--refresh",
        submit: {
          js: !0,
          _triggering_element_name: "op",
          _triggering_element_value: "Refresh"
        },
        url: n.attr("action") + "?ajax_form=1"
      }, e = o.ajax(t);
      e.element = jQuery("<div>")[0], e.$form = n, n.ajaxSubmit(e.options);
    }
  }
  const a = l(m, 250), i = "neo-alchemist--instance-component-form";
  let c = null;
  o.behaviors.neoAlchemistInstanceComponentAjaxForm = {
    attach: function() {
      s("neo.alchemist", "#" + i + " [data-autocomplete-path]").forEach((t) => {
        jQuery(t).on("autocompleteselect", function(e) {
          a();
        });
      }), s("neo.alchemist", "#" + i).forEach((t) => {
        console.log(t), o.CKEditor5Instances && setTimeout(() => {
          o.CKEditor5Instances.size && s("neo.alchemist", "#" + i + " [data-ckeditor5-id]").forEach((e) => {
            o.CKEditor5Instances.forEach((r) => {
              r.model.document.on("change:data", () => {
                a();
              });
            });
          });
        }), t.addEventListener("input", function(e) {
          if (e.target instanceof HTMLElement) {
            if (e.target.dataset.autocompletePath || e.target.dataset.once && e.target.dataset.once.includes("drupal-ajax"))
              return;
            a();
          }
        });
      });
      const n = document.getElementById(i);
      if (n) {
        const t = n.querySelector('input[name="form_build_id"]');
        t && t.value !== c && (c && a(), c = t.value);
      }
    }
  };
})(Drupal, once);
//# sourceMappingURL=component-ajax-form.js.map
