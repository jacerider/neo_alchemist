(function(w, h) {
  let u = null;
  const o = document.querySelector("#neo-alchemist--shade"), t = document.querySelector("#neo-alchemist--overlay"), p = t == null ? void 0 : t.querySelectorAll(".neo-alchemist--ops");
  let i = null, a = !1, l = null;
  function L(e, s) {
    let c;
    return function(...n) {
      c && clearTimeout(c), c = setTimeout(() => {
        e.apply(this, n);
      }, s);
    };
  }
  if (document.addEventListener("keydown", (e) => {
    e.key === "Escape" && d();
  }), t) {
    t.ondblclick = function(s) {
      i && v("edit");
    }, t.addEventListener("click", () => {
      i && x(i);
    }), t.addEventListener("mouseleave", () => {
      a || d();
    });
    const e = t.querySelector(".close");
    e && e.addEventListener("click", (s) => {
      s.preventDefault(), d();
    });
  }
  o && o.addEventListener("click", (e) => {
    e.preventDefault(), d();
  });
  const v = (e) => {
    if (i && e) {
      const s = JSON.parse(i.dataset.component || "{}");
      if (s.ops[e]) {
        const c = JSON.stringify({
          type: e,
          uuid: s.uuid,
          scrollY: window.scrollY,
          scrollX: window.scrollX
        });
        window.parent.postMessage(c, "*");
      }
    }
  };
  t && t.querySelectorAll(".op").forEach((s) => {
    s.addEventListener("click", (c) => {
      c.preventDefault();
      const n = s.dataset.op;
      n && v(n);
    });
  });
  const y = (e) => {
    i = e, f();
  }, x = (e) => {
    if (a = !0, i = e, l = JSON.parse(i.dataset.component || "{}"), t && l.uuid) {
      i.scrollIntoView({
        behavior: "smooth",
        block: "center",
        inline: "nearest"
      }), t.querySelectorAll(".op").forEach((n) => {
        n.style.display = "none";
      });
      const c = t.querySelector(".title");
      c && (c.innerHTML = l.label, console.log(l)), l.ops && Object.keys(l.ops).forEach((n) => {
        if (l.ops[n]) {
          const r = t.querySelector(`[data-op="${n}"]`);
          r && (r.style.display = "");
        }
      });
    }
    f();
  }, d = () => {
    i && (a = !1, i = null, u = setTimeout(() => {
      t && (t.classList.remove("is-active"), t.classList.remove("!transition-all")), o && (o.classList.remove("is-active"), o.classList.remove("!transition-all"));
    }, 100));
  }, f = () => {
    if (i) {
      u && clearTimeout(u);
      const e = i.getBoundingClientRect(), s = e.top + window.scrollY, c = e.bottom + window.scrollY, n = e.left + window.scrollX, m = e.right + window.scrollX;
      t && (t.style.top = s + "px", t.style.left = n + "px", t.style.width = e.width + "px", t.style.height = e.height + "px", t.classList.add("is-active"), a ? t.classList.remove("cursor-pointer") : t.classList.add("cursor-pointer"), setTimeout(() => {
        t.classList.add("!transition-all");
      })), a ? (p && p.forEach((r) => {
        r.classList.add("is-focus");
      }), o && (o.style.top = "0px", o.style.right = "0px", o.style.bottom = "0px", o.style.left = "0px", o.style.width = document.body.clientWidth + "px", o.style.height = document.body.clientHeight + "px", o.style.clipPath = `polygon(0% 0%, 0% 100%, ${n}px 100%, ${n}px ${s}px, ${m}px ${s}px, ${m}px ${c}px, ${n}px ${c}px, ${n}px 100%, 100% 100%, 100% 0%)`, o.classList.add("is-active"), setTimeout(() => {
        o.classList.add("!transition-all");
      }))) : (p && p.forEach((r) => {
        r.classList.remove("is-focus");
      }), o && (o.classList.remove("is-active"), o.classList.remove("!transition-all")));
    }
  };
  function b() {
    i && a && i.scrollIntoView({
      behavior: "smooth",
      block: "center",
      inline: "nearest"
    }), f();
  }
  const g = L(b, 50);
  w.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function() {
      window.parent && (h("neo.alchemist", ".page-wrapper").forEach((e) => {
        new ResizeObserver((c) => {
          g();
        }).observe(e);
      }), h("neo.alchemist", "[data-component]").forEach((e) => {
        e.matches(":hover") && y(e), e.addEventListener("mouseenter", () => {
          y(e);
        });
      }));
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-preview.js.map
