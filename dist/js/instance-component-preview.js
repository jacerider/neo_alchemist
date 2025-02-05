(function(b, h) {
  let u = null;
  const s = document.querySelector("#neo-alchemist--shade"), t = document.querySelector("#neo-alchemist--overlay"), p = t == null ? void 0 : t.querySelectorAll(".neo-alchemist--ops");
  let n = null, a = !1, l = null;
  function w(e, o) {
    let i;
    return function(...c) {
      i && clearTimeout(i), i = setTimeout(() => {
        e.apply(this, c);
      }, o);
    };
  }
  if (document.addEventListener("keydown", (e) => {
    e.key === "Escape" && d();
  }), t) {
    t.ondblclick = function(o) {
      n && v("edit");
    }, t.addEventListener("click", () => {
      n && L(n);
    }), t.addEventListener("mouseleave", () => {
      a || d();
    });
    const e = t.querySelector(".close");
    e && e.addEventListener("click", (o) => {
      o.preventDefault(), d();
    });
  }
  s && s.addEventListener("click", (e) => {
    e.preventDefault(), d();
  });
  const v = (e) => {
    if (n && e) {
      const o = JSON.parse(n.dataset.component || "{}");
      if (o.ops[e]) {
        const i = JSON.stringify({
          type: e,
          uuid: o.uuid,
          scrollY: window.scrollY,
          scrollX: window.scrollX
        });
        window.parent.postMessage(i, "*");
      }
    }
  };
  t && t.querySelectorAll(".op").forEach((o) => {
    o.addEventListener("click", (i) => {
      i.preventDefault();
      const c = o.dataset.op;
      c && v(c);
    });
  });
  const y = (e) => {
    n = e, f();
  }, L = (e) => {
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
      t && (t.classList.remove("is-active"), t.classList.remove("!transition-all")), s && (s.classList.remove("is-active"), s.classList.remove("!transition-all"));
    }, 100));
  }, f = () => {
    if (n) {
      u && clearTimeout(u);
      const e = n.getBoundingClientRect(), o = e.top + window.scrollY, i = e.bottom + window.scrollY, c = e.left + window.scrollX, m = e.right + window.scrollX;
      t && (t.style.top = o + "px", t.style.left = c + "px", t.style.width = e.width + "px", t.style.height = e.height + "px", t.classList.add("is-active"), a ? t.classList.remove("cursor-pointer") : t.classList.add("cursor-pointer"), setTimeout(() => {
        t.classList.add("!transition-all");
      })), a ? (p && p.forEach((r) => {
        r.classList.add("is-focus");
      }), s && (s.style.top = "0px", s.style.right = "0px", s.style.bottom = "0px", s.style.left = "0px", s.style.width = document.body.clientWidth + "px", s.style.height = document.body.clientHeight + "px", s.style.clipPath = `polygon(0% 0%, 0% 100%, ${c}px 100%, ${c}px ${o}px, ${m}px ${o}px, ${m}px ${i}px, ${c}px ${i}px, ${c}px 100%, 100% 100%, 100% 0%)`, s.classList.add("is-active"), setTimeout(() => {
        s.classList.add("!transition-all");
      }))) : (p && p.forEach((r) => {
        r.classList.remove("is-focus");
      }), s && (s.classList.remove("is-active"), s.classList.remove("!transition-all")));
    }
  };
  function x() {
    n && a && n.scrollIntoView({
      behavior: "smooth",
      block: "center",
      inline: "nearest"
    }), f();
  }
  const g = w(x, 50);
  b.behaviors.neoAlchemistInstanceComponentPreview = {
    attach: function() {
      window.parent && (h("neo.alchemist", ".page-wrapper").forEach((e) => {
        new ResizeObserver((i) => {
          g();
        }).observe(e);
      }), h("neo.alchemist", "[data-component]").forEach((e) => {
        if (e.clientHeight === 0) {
          const o = JSON.parse(e.dataset.component || "{}");
          e.innerHTML = '<div class="text-center text-sm bg-base-200 p-4"><strong><em>' + o.label + "</em></strong> has no visible content.</div>";
        }
        e.matches(":hover") && y(e), e.addEventListener("mouseenter", () => {
          y(e);
        });
      }));
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-preview.js.map
