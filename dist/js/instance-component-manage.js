(function(t, i, l) {
  const s = document.getElementById("neo-alchemist--iframe");
  window.addEventListener("message", function(e) {
    const o = JSON.parse(e.data);
    if (typeof o.type == "string") {
      const n = o.type.split("-"), a = n[0], d = n[1] ?? null;
      if (typeof t.behaviors.neoAlchemistInstanceComponentManage[a] != "function")
        return;
      t.behaviors.neoAlchemistInstanceComponentManage[a](o.uuid, d);
    }
  });
  const c = {
    width: "100%",
    height: "100%",
    neo: {
      displaceTop: "0px",
      displaceBottom: "0px"
    }
  };
  t.behaviors.neoAlchemistInstanceComponentManage = {
    attach: function() {
      l && l(!0), [
        { id: "sm", width: "440px", active: !1 },
        { id: "md", width: "768px", active: !1 },
        { id: "lg", width: "100%", active: !0 }
      ].forEach((e) => {
        once("neo.alchemist", "#neo-alchemist--resize-" + e.id).forEach((o) => {
          e.active && o.classList.add("is-active"), o.addEventListener("click", (n) => {
            n.preventDefault(), s && (document.querySelectorAll(".neo-alchemist--resize").forEach((a) => {
              a.classList.remove("is-active");
            }), o.classList.add("is-active"), s.style.maxWidth = e.width);
          });
        });
      });
    },
    edit: (e) => {
      t.ajax({
        url: i.neoAlchemist.baseUrl + "/edit/" + e,
        dialogType: "modal",
        dialog: c
      }).execute();
    },
    sort: (e) => {
      t.ajax({
        url: i.neoAlchemist.baseUrl + "/sort?uuid=" + e,
        dialogType: "modal",
        dialog: c
      }).execute();
    },
    delete: (e) => {
      t.ajax({
        url: i.neoAlchemist.baseUrl + "/delete/" + e,
        dialogType: "modal",
        dialog: c
      }).execute();
    },
    clone: (e) => {
      t.ajax({
        url: i.neoAlchemist.baseUrl + "/clone/" + e
        // dialogType: 'modal',
        // dialog: modalOptions,
      }).execute();
    },
    add: (e, o) => {
      t.ajax({
        url: i.neoAlchemist.baseUrl + `/library?${o}=${e}`,
        dialogType: "modal",
        dialog: c
      }).execute();
    }
  }, t.AjaxCommands && (t.AjaxCommands.prototype.neoAlchemistInstanceComponentPreviewIframe = function(e, o, n) {
    var a;
    s && ((a = s.contentDocument) == null || a.location.reload());
  });
})(Drupal, drupalSettings, Drupal.displace);
//# sourceMappingURL=instance-component-manage.js.map
