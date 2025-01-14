(function(h, v) {
  let u = null;
  const e = document.querySelector("#neo-alchemist--shade"), t = document.querySelector("#neo-alchemist--overlay"), d = t == null ? void 0 : t.querySelectorAll(".neo-alchemist--ops");
  let l = null, p = null;
  if (t) {
    t.addEventListener("click", () => {
      l && x(l);
    });
    const s = t.querySelector(".close");
    s && s.addEventListener("click", (o) => {
      o.preventDefault(), m();
    });
  }
  e && e.addEventListener("click", (s) => {
    s.preventDefault(), m();
  });
  const c = document.getElementById("neo-alchemist--messages");
  c && (setTimeout(() => {
    c.classList.add("transition-all"), c.classList.remove("opacity-0", "-translate-y-full");
  }, 100), c.querySelector(".kint-rich") ? c.classList.remove("fixed") : setTimeout(() => {
    c == null || c.classList.add("opacity-0", "-translate-y-full");
  }, 4e3)), t && t.querySelectorAll(".op").forEach((o) => {
    o.addEventListener("click", (a) => {
      a.preventDefault();
      const i = o.dataset.op;
      if (console.log("click", i), l && i) {
        const n = JSON.parse(l.dataset.component || "{}");
        if (n.ops[i]) {
          const r = JSON.stringify({
            type: i,
            uuid: n.uuid,
            scrollY: window.scrollY,
            scrollX: window.scrollX
          });
          window.parent.postMessage(r, "*");
        }
      }
    });
  });
  const L = (s) => {
    l = s, y(!1);
  }, x = (s) => {
    if (l = s, p = JSON.parse(l.dataset.component || "{}"), t && p.uuid) {
      t.querySelectorAll(".op").forEach((i) => {
        i.style.display = "none";
      });
      const a = t.querySelector(".title");
      a && (a.innerHTML = p.label), p.ops && Object.keys(p.ops).forEach((i) => {
        if (p.ops[i]) {
          const r = t.querySelector(`[data-op="${i}"]`);
          r && (r.style.display = "");
        }
      });
    }
    y(!0);
  }, m = () => {
    l = null, u = setTimeout(() => {
      t && (t.classList.remove("is-active"), t.classList.remove("!transition-all")), e && (e.classList.remove("is-active"), e.classList.remove("!transition-all"));
    }, 100);
  }, y = (s) => {
    if (l) {
      s = s ?? !1, u && clearTimeout(u);
      const o = l.getBoundingClientRect(), a = o.top + window.scrollY, i = o.bottom + window.scrollY, n = o.left + window.scrollX, r = o.right + window.scrollX;
      t && (t.style.top = a + "px", t.style.left = n + "px", t.style.width = o.width + "px", t.style.height = o.height + "px", t.classList.add("is-active"), s ? t.classList.remove("cursor-pointer") : t.classList.add("cursor-pointer"), setTimeout(() => {
        t.classList.add("!transition-all");
      })), s ? (d && d.forEach((f) => {
        f.classList.add("is-active");
      }), e && (e.style.top = "0px", e.style.right = "0px", e.style.bottom = "0px", e.style.left = "0px", e.style.width = document.body.clientWidth + "px", e.style.height = document.body.clientHeight + "px", e.style.clipPath = `polygon(0% 0%, 0% 100%, ${n}px 100%, ${n}px ${a}px, ${r}px ${a}px, ${r}px ${i}px, ${n}px ${i}px, ${n}px 100%, 100% 100%, 100% 0%)`, e.classList.add("is-active"), setTimeout(() => {
        e.classList.add("!transition-all");
      }))) : (d && d.forEach((f) => {
        f.classList.remove("is-active");
      }), e && (e.classList.remove("is-active"), e.classList.remove("!transition-all")));
    }
  };
  h.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function() {
      window.parent && v("neo.alchemist", "[data-component]").forEach((s) => {
        s.addEventListener("mouseenter", () => {
          L(s);
        });
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-preview.js.map
