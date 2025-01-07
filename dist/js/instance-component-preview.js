(function(y, f) {
  let d = null;
  const s = document.querySelector("#neo-alchemist--shade"), t = document.querySelector("#neo-alchemist--overlay");
  let i = null, a = null;
  const n = document.getElementById("neo-alchemist--messages");
  n && (setTimeout(() => {
    n.classList.add("transition-all"), n.classList.remove("opacity-0", "-translate-y-full");
  }, 100), n.querySelector(".kint-rich") ? n.classList.remove("fixed") : setTimeout(() => {
    n == null || n.classList.add("opacity-0", "-translate-y-full");
  }, 4e3)), t && t.querySelectorAll(".op").forEach((l) => {
    l.addEventListener("click", (c) => {
      c.preventDefault();
      const o = l.dataset.op;
      if (i && o) {
        const r = JSON.parse(i.dataset.component || "{}");
        if (r.ops[o]) {
          const p = JSON.stringify({
            type: o,
            uuid: r.uuid,
            scrollY: window.scrollY,
            scrollX: window.scrollX
          });
          window.parent.postMessage(p, "*");
        }
      }
    });
  });
  const h = (e) => {
    if (i = e, a = JSON.parse(i.dataset.component || "{}"), t && a.uuid) {
      t.querySelectorAll(".op").forEach((o) => {
        o.style.display = "none";
      });
      const c = t.querySelector(".title");
      c && (c.innerHTML = a.label), a.ops && Object.keys(a.ops).forEach((o) => {
        if (a.ops[o]) {
          const p = t.querySelector(`[data-op="${o}"]`);
          p && (p.style.display = "");
        }
      });
    }
    u();
  }, v = () => {
    i = null, d = setTimeout(() => {
      t && (t.classList.remove("is-active"), t.classList.remove("!transition-all")), s && (s.classList.remove("is-active"), s.classList.remove("!transition-all"));
    }, 100);
  }, u = () => {
    if (i) {
      d && clearTimeout(d);
      const e = i.getBoundingClientRect(), l = e.top + window.scrollY, c = e.bottom + window.scrollY, o = e.left + window.scrollX, r = e.right + window.scrollX;
      t && (t.style.top = l + "px", t.style.left = o + "px", t.style.width = e.width + "px", t.style.height = e.height + "px", t.classList.add("is-active"), setTimeout(() => {
        t.classList.add("!transition-all");
      }), t.addEventListener("mouseleave", m)), s && (s.style.top = "0px", s.style.right = "0px", s.style.bottom = "0px", s.style.left = "0px", s.style.width = document.body.clientWidth + "px", s.style.height = document.body.clientHeight + "px", s.style.clipPath = `polygon(0% 0%, 0% 100%, ${o}px 100%, ${o}px ${l}px, ${r}px ${l}px, ${r}px ${c}px, ${o}px ${c}px, ${o}px 100%, 100% 100%, 100% 0%)`, s.classList.add("is-active"), setTimeout(() => {
        s.classList.add("!transition-all");
      }));
    }
  }, m = (e) => {
    e.currentTarget.removeEventListener("mouseleave", m), v();
  };
  setInterval(() => {
    u();
  }, 200), y.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function() {
      window.parent && f("neo.alchemist", "[data-component]").forEach((e) => {
        e.addEventListener("mouseenter", () => {
          h(e);
        });
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-preview.js.map
