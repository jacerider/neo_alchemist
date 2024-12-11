(function(a, o, c) {
  const i = document.getElementById("neo-alchemist--iframe");
  window.addEventListener("message", function(t) {
    const e = JSON.parse(t.data);
    if (typeof e.type == "string")
      switch (console.log(e), e.type) {
        case "edit":
          return a.behaviors.neoAlchemistInstanceComponentManage.edit(e.uuid);
        case "sort":
          return a.behaviors.neoAlchemistInstanceComponentManage.sort(e.uuid);
        case "delete":
          return a.behaviors.neoAlchemistInstanceComponentManage.delete(e.uuid);
        case "add-before":
          return a.behaviors.neoAlchemistInstanceComponentManage.add(e.uuid, "before");
        case "add-after":
          return a.behaviors.neoAlchemistInstanceComponentManage.add(e.uuid, "after");
      }
  });
  const n = {
    width: "100%",
    height: "100%",
    neo: {
      displaceTop: "0px",
      displaceBottom: "0px"
    }
  };
  a.behaviors.neoAlchemistInstanceComponentManage = {
    attach: function() {
      c && c(!0), [
        { id: "sm", width: "440px", active: !1 },
        { id: "md", width: "768px", active: !1 },
        { id: "lg", width: "", active: !0 }
      ].forEach((t) => {
        once("neo.alchemist", "#neo-alchemist--resize-" + t.id).forEach((e) => {
          t.active && e.classList.add("is-active"), e.addEventListener("click", (d) => {
            d.preventDefault(), i && (document.querySelectorAll(".neo-alchemist--resize").forEach((s) => {
              s.classList.remove("is-active");
            }), e.classList.add("is-active"), i.style.maxWidth = t.width);
          });
        });
      });
    },
    edit: (t) => {
      a.ajax({
        url: o.neoAlchemist.baseUrl + "/edit/" + t,
        dialogType: "modal",
        dialog: n
      }).execute();
    },
    sort: (t) => {
      a.ajax({
        url: o.neoAlchemist.baseUrl + "/sort?uuid=" + t,
        dialogType: "modal",
        dialog: n
      }).execute();
    },
    delete: (t) => {
      a.ajax({
        url: o.neoAlchemist.baseUrl + "/delete/" + t,
        dialogType: "modal",
        dialog: n
      }).execute();
    },
    add: (t, e) => {
      a.ajax({
        url: o.neoAlchemist.baseUrl + `/library?${e}=${t}`,
        dialogType: "modal",
        dialog: n
      }).execute();
    }
  }, a.AjaxCommands && (a.AjaxCommands.prototype.neoAlchemistInstanceComponentPreviewIframe = function(t, e, d) {
    var s;
    i && ((s = i.contentDocument) == null || s.location.reload());
  });
})(Drupal, drupalSettings, Drupal.displace);
//# sourceMappingURL=instance-component-manage.js.map
