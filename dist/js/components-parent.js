(function(f, M) {
  const m = {
    width: "100%",
    height: "100%",
    neo: {
      displaceTop: "0px",
      displaceBottom: "0px"
    }
  };
  M("neo.alchemist.components.parent", ".neo-alchemist-manage").forEach((p) => {
    let l = {}, r = null, v = null, b = null, S = null, u = null, A = localStorage.getItem("neo-alchemist-scale") || "1";
    const E = p.querySelectorAll("iframe"), i = p.querySelector(".neo-alchemist-manage--wrapper"), C = p.querySelector(".neo-alchemist--overlay"), T = p.querySelector(".neo-alchemist--shade"), h = p.querySelectorAll(".neo-alchemist--ops"), g = {}, $ = {}, w = ["desktop", "tablet", "mobile"];
    let B = 0;
    E.forEach((e) => {
      e.addEventListener("load", () => {
        B++, e.contentWindow && ((v || r === "focus") && e.contentWindow.postMessage({
          type: "componentFocus",
          uuid: v || l.uuid
        }, "*"), B === 3 && (v = null));
      });
    });
    const I = (e) => {
      v = e.detail.uuid;
    };
    p.addEventListener("alchemistManageComponentFocus", I);
    const j = (e) => {
      A = e.detail.scale, d(0);
    };
    if (p.addEventListener("alchemistManageScale", j), h && i) {
      const e = i.querySelector(".close");
      e && e.addEventListener("click", (t) => {
        t.preventDefault(), d(0);
      });
    }
    if (C && T && F(C, T), i && i.querySelectorAll(".op").forEach((t) => {
      t.addEventListener("click", (o) => {
        o.preventDefault();
        const n = t.dataset.op;
        n && O(l, n);
      });
    }), i) {
      let e, t;
      i.addEventListener("mousedown", (o) => {
        e = o.clientX, t = o.clientY;
      }), i.addEventListener("mouseup", (o) => {
        r !== null && (o.target instanceof HTMLElement && (o.target.dataset.alchemistIgnore !== void 0 || o.target.closest("[data-alchemist-ignore]")) || e === o.clientX && t === o.clientY && d());
      });
    }
    document.addEventListener("keydown", (e) => {
      e.key === "Escape" && d();
    }), window.addEventListener("message", (e) => {
      const t = e.data;
      if (typeof t.type == "string") {
        const o = k[t.type];
        typeof o == "function" && o(t);
      }
    });
    const k = {
      size: function(e) {
        if (r === "focus") {
          const t = L(e.size);
          t && t.contentWindow && t.contentWindow.postMessage({
            type: "componentHover",
            uuid: l.uuid
          }, "*");
        }
      },
      onComponentHover: function(e) {
        l = e.component, l.uuid = e.uuid, r = "hover", S = e.size, u && clearTimeout(u), u = setTimeout(() => {
          Object.values(g).some(
            (o) => o instanceof HTMLElement && o.matches(":hover")
          ) || d();
        }, 200), z(e.size, e.uuid);
      },
      doComponentHover: function(e) {
        const t = e.size, o = g[t];
        if (o) {
          const n = L(t);
          if (n && i && e.rect) {
            const s = r === "focus";
            P(o, n, e.rect, s);
            const c = o.querySelector(".label");
            c && (c.innerHTML = l.label), setTimeout(() => {
              o.classList.add("!transition-all");
            }), s && (q(), e.size === S && f.behaviors.neoAlchemistComponentParent.scrollElementIntoView(o, i, 100));
          }
        }
      },
      doComponentFocus: function(e) {
        l = e.component, l.uuid = e.uuid, k.doComponentHover(e);
      },
      componentDoesNotExist: function(e) {
        d();
      }
    }, H = (e) => {
      r === "focus" && O(l, "edit");
    };
    function F(e, t) {
      w.forEach((o) => {
        const n = e.cloneNode(!0);
        n.id = `neo-alchemist--overlay-${o}`, n.addEventListener("mouseleave", () => {
          r === "hover" && (u = setTimeout(() => {
            d();
          }, 200));
        }), n.addEventListener("click", (c) => {
          c.preventDefault(), n.removeEventListener("dblclick", H), b === l.uuid && n.addEventListener("dblclick", H), W(), i && f.behaviors.neoAlchemistComponentParent.scrollElementIntoView(n.getBoundingClientRect(), i, 100);
        }), e.insertAdjacentElement("afterend", n), g[o] = n;
        const s = t.cloneNode(!0);
        s.id = `neo-alchemist--shade-${o}`, s.addEventListener("click", (c) => {
          c.preventDefault(), d(0);
        }), t.insertAdjacentElement("afterend", s), $[o] = s;
      }), e.remove(), t.remove();
    }
    function P(e, t, o, n = !1) {
      if (!i) return;
      e.classList.add("is-active", "cursor-pointer");
      const s = i.getBoundingClientRect(), c = t.getBoundingClientRect(), a = parseFloat(A), y = i.scrollTop + c.top + o.top * a + window.scrollY - s.top - 10, x = i.scrollLeft + c.left + o.left * a + window.scrollX - s.left, _ = o.height * a + 20, G = o.width * a + 0;
      n && e.classList.remove("!transition-all"), e.style.top = `${y}px`, e.style.left = `${x}px`, e.style.width = `${G}px`, e.style.height = `${_}px`, n && setTimeout(() => {
        e.classList.add("!transition-all");
      }, 100);
    }
    function z(e, t) {
      V(e).forEach((o) => {
        o.contentWindow && o.contentWindow.postMessage({
          type: "componentHover",
          uuid: t
        }, "*");
      });
    }
    const R = {
      edit: (e) => {
        const t = {
          ...m,
          neo: {
            ...m.neo,
            contentPadding: "0px"
          }
        };
        f.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/edit/${e.uuid}`,
          dialogType: "modal",
          dialog: t
        }).execute();
      },
      sort: (e) => {
        f.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/sort?uuid=${e.uuid}`,
          dialogType: "modal",
          dialog: m
        }).execute();
      },
      delete: (e) => {
        f.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/delete/${e.uuid}`,
          dialogType: "modal",
          dialog: {
            ...m,
            width: "auto",
            height: "auto"
          }
        }).execute();
      },
      clone: (e) => {
        f.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/clone/${e.uuid}`
        }).execute();
      },
      add: (e, t) => {
        f.ajax({
          url: `${drupalSettings.neoAlchemist.baseUrl}/library?${t}=${e.uuid}`,
          dialogType: "modal",
          dialog: m
        }).execute();
      }
    };
    function O(e, t) {
      var s;
      const [o, n] = t.includes("-") ? t.split("-", 2) : [t, void 0];
      (s = e == null ? void 0 : e.ops) != null && s[t] && R[o] && R[o](e, n);
    }
    function W() {
      !l || !i || (r = "focus", U(), X(), Y(), q(), b = l.uuid);
    }
    function U() {
      if (!i) return;
      const e = i.querySelector(".title");
      e && (e.innerHTML = l.label, l.status !== !0 && (e.innerHTML += ' <span class="badge bg-alert-500 text-alert-content-500">Draft</span>'));
    }
    function X() {
      if (!i) return;
      i.querySelectorAll(".op").forEach((t) => {
        t.style.display = "none";
      }), l.ops && Object.entries(l.ops).forEach(([t, o]) => {
        o && i.querySelectorAll(`[data-op="${t}"]`).forEach((s) => {
          s.style.display = "";
        });
      });
    }
    function Y() {
      !h || !i || h.forEach((e) => {
        const t = e.getAttribute("data-placement"), o = i.getBoundingClientRect();
        switch (t) {
          case "top":
            e.style.top = `${o.top}px`;
            break;
          case "bottom":
            const n = window.innerHeight - o.top - o.height;
            e.style.bottom = `${n}px`;
            break;
        }
        e.classList.add("is-focus"), e.style.display = "";
      });
    }
    function q() {
      w.forEach((e) => {
        const t = L(e), o = g[e], n = $[e];
        i && t && o && n && (N(n, t), D(n, o, t), o.classList.add("is-focus"), n.classList.add("is-active"));
      });
    }
    function N(e, t) {
      if (!i) return;
      const o = i.getBoundingClientRect(), n = t.getBoundingClientRect();
      e.style.top = `${i.scrollTop + n.top - o.top}px`, e.style.left = `${i.scrollLeft + n.left - o.left}px`, e.style.width = `${n.width}px`, e.style.height = `${n.height}px`;
    }
    function D(e, t, o) {
      const n = t.getBoundingClientRect(), s = o.getBoundingClientRect(), c = n.top - s.top, a = n.left - s.left, y = a + n.width, x = c + n.height;
      e.style.clipPath = `polygon(0% 0%, 0% 100%, ${a}px 100%, ${a}px ${c}px, ${y}px ${c}px, ${y}px ${x}px, ${a}px ${x}px, ${a}px 100%, 100% 100%, 100% 0%)`;
    }
    function d(e = null) {
      e = e === null ? 100 : e, u && clearTimeout(u), u = setTimeout(() => {
        u = null, h && h.forEach((t) => t.classList.remove("is-focus")), w.forEach((t) => {
          const o = g[t];
          o && o.classList.remove("is-active", "is-focus", "!transition-all");
          const n = $[t];
          n && n.classList.remove("is-active", "!transition-all");
        });
      }, e), l = {}, r = null;
    }
    function L(e) {
      return Array.from(E).find((t) => t.getAttribute("data-size") === e);
    }
    function V(e) {
      return Array.from(E).filter((t) => t.getAttribute("data-size") !== e);
    }
  });
})(Drupal, once);
//# sourceMappingURL=components-parent.js.map
