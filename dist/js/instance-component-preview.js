(function(m, y) {
  let r = null;
  const o = document.querySelector("#neo-alchemist--shade"), e = document.querySelector("#neo-alchemist--overlay");
  let n = null;
  const d = ["edit", "sort", "delete", "add-before", "add-after"], c = document.getElementById("neo-alchemist--messages");
  c && (c.classList.remove("opacity-0"), setTimeout(() => {
    c == null || c.classList.add("opacity-0");
  }, 3e3)), e && d.forEach((t) => {
    var s;
    (s = e.querySelector(`.op-${t}`)) == null || s.addEventListener("click", (l) => {
      if (l.preventDefault(), n) {
        const i = n.getAttribute("data-component-uuid"), a = JSON.stringify({
          type: t,
          uuid: i,
          scrollY: window.scrollY,
          scrollX: window.scrollX
        });
        window.parent.postMessage(a, "*");
      }
    });
  });
  const h = (t) => {
    n = t, d.forEach((s) => {
      if (e && n) {
        const l = e.querySelector(`.op-${s}`);
        if (l && n.hasAttribute(`data-component-${s}`)) {
          l.style.display = "";
          const i = n.getAttribute(`data-component-${s}`) === "true";
          l.style.display = i ? "" : "none";
        }
      }
    }), p();
  }, f = () => {
    n = null, r = setTimeout(() => {
      e && (e.classList.remove("is-active"), e.classList.remove("!transition-all")), o && (o.classList.remove("is-active"), o.classList.remove("!transition-all"));
    }, 100);
  }, p = () => {
    if (n) {
      r && clearTimeout(r);
      const t = n.getBoundingClientRect(), s = t.top + window.scrollY, l = t.bottom + window.scrollY, i = t.left + window.scrollX, a = t.right + window.scrollX;
      e && (e.style.top = s + "px", e.style.left = i + "px", e.style.width = t.width + "px", e.style.height = t.height + "px", e.classList.add("is-active"), setTimeout(() => {
        e.classList.add("!transition-all");
      }), e.addEventListener("mouseleave", u)), o && (o.style.top = "0px", o.style.right = "0px", o.style.bottom = "0px", o.style.left = "0px", o.style.width = document.body.clientWidth + "px", o.style.height = document.body.clientHeight + "px", o.style.clipPath = `polygon(0% 0%, 0% 100%, ${i}px 100%, ${i}px ${s}px, ${a}px ${s}px, ${a}px ${l}px, ${i}px ${l}px, ${i}px 100%, 100% 100%, 100% 0%)`, o.classList.add("is-active"), setTimeout(() => {
        o.classList.add("!transition-all");
      }));
    }
  }, u = (t) => {
    t.currentTarget.removeEventListener("mouseleave", u), f();
  };
  setInterval(() => {
    p();
  }, 200), m.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function() {
      window.parent && y("neo.alchemist", "[data-component-uuid]").forEach((t) => {
        t.addEventListener("mouseenter", () => {
          h(t);
        });
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-preview.js.map
