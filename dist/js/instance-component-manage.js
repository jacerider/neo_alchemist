(function(t, n) {
  window.addEventListener("message", function(e) {
    const o = JSON.parse(e.data);
    if (typeof o.type == "string") {
      const i = o.type.split("-"), s = i[0], l = i[1] ?? null;
      if (typeof t.behaviors.neoAlchemistInstanceComponentManage[s] != "function")
        return;
      t.behaviors.neoAlchemistInstanceComponentManage[s](o.uuid, l);
    }
  });
  const a = {
    width: "100%",
    height: "100%",
    neo: {
      displaceTop: "0px",
      displaceBottom: "0px"
    }
  };
  t.behaviors.neoAlchemistInstanceComponentManage = {
    edit: (e) => {
      const o = Object.assign({}, a);
      o.neo = { ...o.neo, contentPadding: "0px" }, t.ajax({
        url: n.neoAlchemist.baseUrl + "/edit/" + e,
        dialogType: "modal",
        dialog: o
      }).execute();
    },
    sort: (e) => {
      t.ajax({
        url: n.neoAlchemist.baseUrl + "/sort?uuid=" + e,
        dialogType: "modal",
        dialog: a
      }).execute();
    },
    delete: (e) => {
      t.ajax({
        url: n.neoAlchemist.baseUrl + "/delete/" + e,
        dialogType: "modal",
        dialog: { ...a, width: "auto", height: "auto" }
      }).execute();
    },
    clone: (e) => {
      t.ajax({
        url: n.neoAlchemist.baseUrl + "/clone/" + e
      }).execute();
    },
    add: (e, o) => {
      t.ajax({
        url: n.neoAlchemist.baseUrl + `/library?${o}=${e}`,
        dialogType: "modal",
        dialog: a
      }).execute();
    }
  };
})(Drupal, drupalSettings);
//# sourceMappingURL=instance-component-manage.js.map
