(function(p) {
  const e = new URLSearchParams(window.location.search).get("id"), s = new URLSearchParams(window.location.search).get("size");
  window.addEventListener("message", function(t) {
    const n = t.data;
    if (typeof n.type == "string") {
      if (typeof i[n.type] != "function")
        return;
      i[n.type](n);
    }
  });
  const i = {
    componentHover: function(t) {
      const n = t.uuid, o = c(n);
      o && d(o);
    },
    componentFocus: function(t) {
      const n = t.uuid, o = c(n);
      o ? window.parent.postMessage({
        type: "doComponentFocus",
        id: e,
        size: s,
        uuid: o.dataset.componentUuid,
        component: JSON.parse(o.dataset.component || "{}"),
        rect: o.getBoundingClientRect()
      }, "*") : window.parent.postMessage({
        type: "componentDoesNotExist",
        id: e,
        size: s,
        uuid: n
      }, "*");
    }
  };
  p("neo.alchemist", "[data-component]").forEach((t) => {
    u(t), t.matches(":hover") && a(t), t.addEventListener("mouseenter", () => {
      a(t);
    });
  });
  function c(t) {
    return document.querySelector(`[data-component-uuid="${t}"]`);
  }
  function a(t) {
    t.dataset.componentUuid && (window.parent.postMessage({
      type: "onComponentHover",
      id: e,
      size: s,
      uuid: t.dataset.componentUuid,
      component: JSON.parse(t.dataset.component || "{}")
    }, "*"), d(t));
  }
  function d(t) {
    t.dataset.componentUuid && window.parent.postMessage({
      type: "doComponentHover",
      id: e,
      size: s,
      rect: t.getBoundingClientRect()
    }, "*");
  }
  function u(t) {
    if (t.style.display = "block", t.clientHeight === 0) {
      const n = JSON.parse(t.dataset.component || "{}");
      t.innerHTML = '<div class="w-full text-center text-sm bg-base-200 p-4"><strong><em>' + n.label + "</em></strong> has no visible content.</div>";
    }
    t.style.display = "";
  }
})(once);
//# sourceMappingURL=components-child.js.map
