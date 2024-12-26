(function(y, f) {
  let r = null;
  const o = document.querySelector("#neo-alchemist--shade"), t = document.querySelector("#neo-alchemist--overlay");
  let c = null;
  const i = document.getElementById("neo-alchemist--messages");
  i && (setTimeout(() => {
    i.classList.add("transition-all"), i.classList.remove("opacity-0", "-translate-y-full");
  }, 100), i.querySelector(".kint-rich") ? i.classList.remove("fixed") : setTimeout(() => {
    i == null || i.classList.add("opacity-0", "-translate-y-full");
  }, 4e3)), t && t.querySelectorAll(".op").forEach((s) => {
    s.addEventListener("click", (a) => {
      a.preventDefault();
      const n = s.dataset.op;
      if (c && n) {
        const l = JSON.parse(c.dataset.component || "{}");
        if (l.ops[n]) {
          const p = JSON.stringify({
            type: n,
            uuid: l.uuid,
            scrollY: window.scrollY,
            scrollX: window.scrollX
          });
          console.log("message", p), window.parent.postMessage(p, "*");
        }
      }
    });
  });
  const h = (e) => {
    c = e;
    const s = JSON.parse(c.dataset.component || "{}");
    if (t && s.uuid) {
      t.querySelectorAll(".op").forEach((l) => {
        l.style.display = "none";
      });
      const n = t.querySelector(".title");
      n && (n.innerHTML = s.label), s.ops && Object.keys(s.ops).forEach((l) => {
        if (s.ops[l]) {
          const m = t.querySelector(`[data-op="${l}"]`);
          m && (m.style.display = "");
        }
      });
    }
    d();
  }, v = () => {
    c = null, r = setTimeout(() => {
      t && (t.classList.remove("is-active"), t.classList.remove("!transition-all")), o && (o.classList.remove("is-active"), o.classList.remove("!transition-all"));
    }, 100);
  }, d = () => {
    if (c) {
      r && clearTimeout(r);
      const e = c.getBoundingClientRect(), s = e.top + window.scrollY, a = e.bottom + window.scrollY, n = e.left + window.scrollX, l = e.right + window.scrollX;
      t && (t.style.top = s + "px", t.style.left = n + "px", t.style.width = e.width + "px", t.style.height = e.height + "px", t.classList.add("is-active"), setTimeout(() => {
        t.classList.add("!transition-all");
      }), t.addEventListener("mouseleave", u)), o && (o.style.top = "0px", o.style.right = "0px", o.style.bottom = "0px", o.style.left = "0px", o.style.width = document.body.clientWidth + "px", o.style.height = document.body.clientHeight + "px", o.style.clipPath = `polygon(0% 0%, 0% 100%, ${n}px 100%, ${n}px ${s}px, ${l}px ${s}px, ${l}px ${a}px, ${n}px ${a}px, ${n}px 100%, 100% 100%, 100% 0%)`, o.classList.add("is-active"), setTimeout(() => {
        o.classList.add("!transition-all");
      }));
    }
  }, u = (e) => {
    e.currentTarget.removeEventListener("mouseleave", u), v();
  };
  setInterval(() => {
    d();
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
