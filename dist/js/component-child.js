(function(c, n) {
  const a = new URLSearchParams(window.location.search).get("id"), i = new URLSearchParams(window.location.search).get("size");
  c.behaviors.neoAlchemistComponentChild = {
    attach: function() {
      n("neo.alchemist", ".neo-alchemist-preview").forEach((s) => {
        const e = document.querySelector(".alchemist-messages .messages--wrapper");
        if (e) {
          const t = e.querySelector(".sf-dump") || e.querySelector(".kint-rich");
          if (t) {
            const r = document.querySelector(".alchemist-messages");
            r && (r.classList.add("opacity-100"), r.classList.remove("invisible", "opacity-0"));
          } else
            i === "desktop" && (t || window.parent.postMessage({
              type: "messages",
              id: a,
              size: i,
              messages: e.innerHTML
            }, "*")), e.remove();
        }
        new ResizeObserver((t) => {
          for (const r of t)
            window.parent.postMessage({
              type: "size",
              id: a,
              size: i,
              height: s.scrollHeight
            }, "*");
        }).observe(s), l(s);
      });
    }
  };
  const l = (s) => {
    const e = (o) => o.button === 0 ? (o.preventDefault(), !1) : !0;
    s.addEventListener("click", e, { capture: !0 });
  };
})(Drupal, once);
//# sourceMappingURL=component-child.js.map
