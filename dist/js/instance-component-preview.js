(function(b, h) {
  let u = null;
  const s = document.querySelector("#neo-alchemist--shade"), e = document.querySelector("#neo-alchemist--overlay"), p = e == null ? void 0 : e.querySelectorAll(".neo-alchemist--ops");
  let n = null, a = !1, l = null;
  function w(t, o) {
    let i;
    return function(...c) {
      i && clearTimeout(i), i = setTimeout(() => {
        t.apply(this, c);
      }, o);
    };
  }
  if (document.addEventListener("keydown", (t) => {
    t.key === "Escape" && d();
  }), e) {
    e.ondblclick = function(o) {
      n && v("edit");
    }, e.addEventListener("click", () => {
      n && L(n);
    }), e.addEventListener("mouseleave", () => {
      a || d();
    });
    const t = e.querySelector(".close");
    t && t.addEventListener("click", (o) => {
      o.preventDefault(), d();
    });
  }
  s && s.addEventListener("click", (t) => {
    t.preventDefault(), d();
  });
  const v = (t) => {
    if (n && t) {
      const o = JSON.parse(n.dataset.component || "{}");
      if (o.ops[t]) {
        const i = JSON.stringify({
          type: t,
          uuid: o.uuid,
          scrollY: window.scrollY,
          scrollX: window.scrollX
        });
        window.parent.postMessage(i, "*");
      }
    }
  };
  e && e.querySelectorAll(".op").forEach((o) => {
    o.addEventListener("click", (i) => {
      i.preventDefault();
      const c = o.dataset.op;
      c && v(c);
    });
  });
  const y = (t) => {
    n = t, f();
  }, L = (t) => {
    if (a = !0, n = t, l = JSON.parse(n.dataset.component || "{}"), e && l.uuid) {
      n.scrollIntoView({
        behavior: "smooth",
        block: "center",
        inline: "nearest"
      }), e.querySelectorAll(".op").forEach((c) => {
        c.style.display = "none";
      });
      const i = e.querySelector(".title");
      i && (i.innerHTML = l.label, l.status !== !0 && (i.innerHTML += ' <span class="badge bg-alert-500 text-alert-content-500">Draft</span>')), l.ops && Object.keys(l.ops).forEach((c) => {
        if (l.ops[c]) {
          const r = e.querySelector(`[data-op="${c}"]`);
          r && (r.style.display = "");
        }
      });
    }
    f();
  }, d = () => {
    n && (a = !1, n = null, u = setTimeout(() => {
      e && (e.classList.remove("is-active"), e.classList.remove("!transition-all")), s && (s.classList.remove("is-active"), s.classList.remove("!transition-all"));
    }, 100));
  }, f = () => {
    if (n) {
      u && clearTimeout(u);
      const t = n.getBoundingClientRect(), o = t.top + window.scrollY, i = t.bottom + window.scrollY, c = t.left + window.scrollX, m = t.right + window.scrollX;
      e && (e.style.top = o + "px", e.style.left = c + "px", e.style.width = t.width + "px", e.style.height = t.height + "px", e.classList.add("is-active"), a ? e.classList.remove("cursor-pointer") : e.classList.add("cursor-pointer"), setTimeout(() => {
        e.classList.add("!transition-all");
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
      window.parent && (h("neo.alchemist", ".page-wrapper").forEach((t) => {
        new ResizeObserver((i) => {
          g();
        }).observe(t);
      }), h("neo.alchemist", "[data-component]").forEach((t) => {
        if (t.style.display = "block", t.clientHeight === 0) {
          const o = JSON.parse(t.dataset.component || "{}");
          t.innerHTML = '<div class="w-full text-center text-sm bg-base-200 p-4"><strong><em>' + o.label + "</em></strong> has no visible content.</div>";
        }
        t.style.display = "", t.matches(":hover") && y(t), t.addEventListener("mouseenter", () => {
          y(t);
        });
      }));
    }
  };
})(Drupal, once);
//# sourceMappingURL=instance-component-preview.js.map
