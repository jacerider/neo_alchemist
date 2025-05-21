(function(r) {
  const o = new URLSearchParams(window.location.search).get("id"), s = new URLSearchParams(window.location.search).get("size");
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
      const n = t.uuid, e = c(n);
      e && d(e);
    },
    componentFocus: function(t) {
      const n = t.uuid, e = c(n);
      e ? window.parent.postMessage({
        type: "doComponentFocus",
        id: o,
        size: s,
        uuid: e.dataset.componentUuid,
        component: JSON.parse(e.dataset.component || "{}"),
        rect: e.getBoundingClientRect()
      }, "*") : window.parent.postMessage({
        type: "componentDoesNotExist",
        id: o,
        size: s,
        uuid: n
      }, "*");
    }
  };
  r("neo.alchemist", "[data-component]").forEach((t) => {
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
      id: o,
      size: s,
      uuid: t.dataset.componentUuid,
      component: JSON.parse(t.dataset.component || "{}")
    }, "*"), d(t));
  }
  function d(t) {
    t.dataset.componentUuid && window.parent.postMessage({
      type: "doComponentHover",
      id: o,
      size: s,
      rect: t.getBoundingClientRect()
    }, "*");
  }
  function u(t) {
    if (t.style.display = "block", t.clientHeight === 0) {
      const n = JSON.parse(t.dataset.component || "{}"), e = document.createElement("div");
      e.classList.add("w-full", "text-center", "text-sm", "bg-base-200", "p-4"), e.innerHTML = "<strong><em>" + n.label + "</em></strong> has no visible content.", t.appendChild(e);
      let m = t.clientHeight;
      const p = new ResizeObserver((g) => {
        g[0].contentRect.height !== m && (e.remove(), p.unobserve(t));
      });
      p.observe(t);
    }
    t.style.display = "";
  }
})(once);
//# sourceMappingURL=components-child.js.map
