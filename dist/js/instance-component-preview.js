(function(w, h) {
  let u = null;
  const o = document.querySelector("#neo-alchemist--shade"), t = document.querySelector("#neo-alchemist--overlay"), p = t == null ? void 0 : t.querySelectorAll(".neo-alchemist--ops");
  let n = null, a = !1, l = null;
  function L(e, s) {
    let i;
    return function(...c) {
      i && clearTimeout(i), i = setTimeout(() => {
        e.apply(this, c);
      }, s);
    };
  }
  if (document.addEventListener("keydown", (e) => {
    e.key === "Escape" && d();
  }), t) {
    t.ondblclick = function(s) {
      n && v("edit");
    }, t.addEventListener("click", () => {
      n && b(n);
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
    if (n && e) {
      const s = JSON.parse(n.dataset.component || "{}");
      if (s.ops[e]) {
        const i = JSON.stringify({
          type: e,
          uuid: s.uuid,
          scrollY: window.scrollY,
          scrollX: window.scrollX
        });
        window.parent.postMessage(i, "*");
      }
    }
  };
  t && t.querySelectorAll(".op").forEach((s) => {
    s.addEventListener("click", (i) => {
      i.preventDefault();
      const c = s.dataset.op;
      c && v(c);
    });
  });
  const y = (e) => {
    n = e, f();
  }, b = (e) => {
    if (a = !0, n = e, l = JSON.parse(n.dataset.component || "{}"), t && l.uuid) {
      n.scrollIntoView({
        behavior: "smooth",
        block: "center",
        inline: "nearest"
      }), t.querySelectorAll(".op").forEach((c) => {
        c.style.display = "none";
      });
      const i = t.querySelector(".title");
      i && (i.innerHTML = l.label, l.status !== !0 && (i.innerHTML += ' <span class="badge bg-alert-500 text-alert-content-500">Draft</span>')), l.ops && Object.keys(l.ops).forEach((c) => {
        if (l.ops[c]) {
          const r = t.querySelector(`[data-op="${c}"]`);
          r && (r.style.display = "");
        }
      });
    }
    f();
  }, d = () => {
    n && (a = !1, n = null, u = setTimeout(() => {
      t && (t.classList.remove("is-active"), t.classList.remove("!transition-all")), o && (o.classList.remove("is-active"), o.classList.remove("!transition-all"));
    }, 100));
  }, f = () => {
    if (n) {
      u && clearTimeout(u);
      const e = n.getBoundingClientRect(), s = e.top + window.scrollY, i = e.bottom + window.scrollY, c = e.left + window.scrollX, m = e.right + window.scrollX;
      t && (t.style.top = s + "px", t.style.left = c + "px", t.style.width = e.width + "px", t.style.height = e.height + "px", t.classList.add("is-active"), a ? t.classList.remove("cursor-pointer") : t.classList.add("cursor-pointer"), setTimeout(() => {
        t.classList.add("!transition-all");
      })), a ? (p && p.forEach((r) => {
        r.classList.add("is-focus");
      }), o && (o.style.top = "0px", o.style.right = "0px", o.style.bottom = "0px", o.style.left = "0px", o.style.width = document.body.clientWidth + "px", o.style.height = document.body.clientHeight + "px", o.style.clipPath = `polygon(0% 0%, 0% 100%, ${c}px 100%, ${c}px ${s}px, ${m}px ${s}px, ${m}px ${i}px, ${c}px ${i}px, ${c}px 100%, 100% 100%, 100% 0%)`, o.classList.add("is-active"), setTimeout(() => {
        o.classList.add("!transition-all");
      }))) : (p && p.forEach((r) => {
        r.classList.remove("is-focus");
      }), o && (o.classList.remove("is-active"), o.classList.remove("!transition-all")));
    }
  };
  function x() {
    n && a && n.scrollIntoView({
      behavior: "smooth",
      block: "center",
      inline: "nearest"
    }), f();
  }
  const g = L(x, 50);
  w.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function() {
      window.parent && (h("neo.alchemist", ".page-wrapper").forEach((e) => {
        new ResizeObserver((i) => {
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
