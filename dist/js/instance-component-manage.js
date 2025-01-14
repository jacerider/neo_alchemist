(function(i, a, n) {
  const l = document.getElementById("neo-alchemist--iframe");
  window.addEventListener("message", function(e) {
    const t = JSON.parse(e.data);
    if (typeof t.type == "string") {
      const s = t.type.split("-"), c = s[0], d = s[1] ?? null;
      if (typeof i.behaviors.neoAlchemistInstanceComponentManage[c] != "function")
        return;
      i.behaviors.neoAlchemistInstanceComponentManage[c](t.uuid, d);
    }
  });
  const o = {
    width: "100%",
    height: "100%",
    neo: {
      displaceTop: "0px",
      displaceBottom: "0px"
    }
  };
  i.behaviors.neoAlchemistInstanceComponentManage = {
    attach: function() {
      n && n(!0), [
        { id: "sm", width: "440px", active: !1 },
        { id: "md", width: "768px", active: !1 },
        { id: "lg", width: "100%", active: !0 }
      ].forEach((e) => {
        once("neo.alchemist", "#neo-alchemist--resize-" + e.id).forEach((t) => {
          e.active && t.classList.add("is-active"), t.addEventListener("click", (s) => {
            s.preventDefault(), l && (document.querySelectorAll(".neo-alchemist--resize").forEach((c) => {
              c.classList.remove("is-active");
            }), t.classList.add("is-active"), l.style.maxWidth = e.width);
          });
        });
      });
    },
    edit: (e) => {
      i.ajax({
        url: a.neoAlchemist.baseUrl + "/edit/" + e,
        dialogType: "modal",
        dialog: o
      }).execute();
    },
    sort: (e) => {
      i.ajax({
        url: a.neoAlchemist.baseUrl + "/sort?uuid=" + e,
        dialogType: "modal",
        dialog: o
      }).execute();
    },
    delete: (e) => {
      i.ajax({
        url: a.neoAlchemist.baseUrl + "/delete/" + e,
        dialogType: "modal",
        dialog: o
      }).execute();
    },
    clone: (e) => {
      i.ajax({
        url: a.neoAlchemist.baseUrl + "/clone/" + e
        // dialogType: 'modal',
        // dialog: modalOptions,
      }).execute();
    },
    add: (e, t) => {
      i.ajax({
        url: a.neoAlchemist.baseUrl + `/library?${t}=${e}`,
        dialogType: "modal",
        dialog: o
      }).execute();
    }
  };
})(Drupal, drupalSettings, Drupal.displace);
//# sourceMappingURL=instance-component-manage.js.map
