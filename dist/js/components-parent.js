(function(f, q) {
  const m = {
    width: "100%",
    height: "100%",
    neo: {
      displaceTop: "0px",
      displaceBottom: "0px"
    }
  };
  q("neo.alchemist.components.parent", ".neo-alchemist-manage").forEach((p) => {
    let i = {}, r = null, v = null, b = null, T = null, d = null, S = localStorage.getItem("neo-alchemist-scale") || "1";
    const w = p.querySelectorAll("iframe"), s = p.querySelector(".neo-alchemist-manage--wrapper"), A = p.querySelector(".neo-alchemist--overlay"), C = p.querySelector(".neo-alchemist--shade"), g = p.querySelectorAll(".neo-alchemist--ops"), h = {}, E = {}, $ = ["desktop", "tablet", "mobile"];
    let H = 0;
    w.forEach((e) => {
      e.addEventListener("load", () => {
        H++, e.contentWindow && ((v || r === "focus") && e.contentWindow.postMessage({
          type: "componentFocus",
          uuid: v || i.uuid
        }, "*"), H === 3 && (v = null));
      });
    });
    const I = (e) => {
      v = e.detail.uuid;
    };
    p.addEventListener("alchemistManageComponentFocus", I);
    const j = (e) => {
      S = e.detail.scale, u(0);
    };
    if (p.addEventListener("alchemistManageScale", j), g && s) {
      const e = s.querySelector(".close");
      e && e.addEventListener("click", (t) => {
        t.preventDefault(), u(0);
      });
    }
    if (A && C && F(A, C), s && s.querySelectorAll(".op").forEach((t) => {
      t.addEventListener("click", (n) => {
        n.preventDefault();
        const o = t.dataset.op;
        o && R(i, o);
      });
    }), s) {
      let e, t;
      s.addEventListener("mousedown", (n) => {
        e = n.clientX, t = n.clientY;
      }), s.addEventListener("mouseup", (n) => {
        r !== null && (n.target instanceof HTMLElement && (n.target.dataset.alchemistIgnore !== void 0 || n.target.closest("[data-alchemist-ignore]")) || e === n.clientX && t === n.clientY && u());
      });
    }
    document.addEventListener("keydown", (e) => {
      e.key === "Escape" && u();
    }), window.addEventListener("message", (e) => {
      const t = e.data;
      if (typeof t.type == "string") {
        const n = B[t.type];
        typeof n == "function" && n(t);
      }
    });
    const B = {
      size: function(e) {
        if (r === "focus") {
          const t = L(e.size);
          t && t.contentWindow && t.contentWindow.postMessage({
            type: "componentHover",
            uuid: i.uuid
          }, "*");
        }
      },
      onComponentHover: function(e) {
        i = e.component, i.uuid = e.uuid, r = "hover", T = e.size, d && clearTimeout(d), d = setTimeout(() => {
          Object.values(h).some(
            (n) => n instanceof HTMLElement && n.matches(":hover")
          ) || u();
        }, 200), z(e.size, e.uuid);
      },
      doComponentHover: function(e) {
        const t = e.size, n = h[t];
        if (n) {
          const o = L(t);
          if (o && s && e.rect) {
            const l = r === "focus";
            P(n, o, e.rect, l);
            const c = n.querySelector(".label");
            c && (c.innerHTML = `<span class="px-1">${i.label}</span>`, i.warnings && i.warnings.length > 0 && i.warnings.forEach((a) => {
              c.innerHTML = ` <span class="badge rounded-sm bg-warning-500 text-warning-content-500">${a}</span>` + c.innerHTML;
            }), i.alerts && i.alerts.length > 0 && i.alerts.forEach((a) => {
              c.innerHTML = ` <span class="badge rounded-sm bg-alert-500 text-alert-content-500">${a}</span>` + c.innerHTML;
            })), setTimeout(() => {
              n.classList.add("!transition-all");
            }), l && (O(), e.size === T && f.behaviors.neoAlchemistComponentParent.scrollElementIntoView(n, s, 100));
          }
        }
      },
      doComponentFocus: function(e) {
        i = e.component, i.uuid = e.uuid, B.doComponentHover(e);
      },
      componentDoesNotExist: function(e) {
        u();
      }
    }, M = (e) => {
      r === "focus" && R(i, "edit");
    };
    function F(e, t) {
      $.forEach((n) => {
        const o = e.cloneNode(!0);
        o.id = `neo-alchemist--overlay-${n}`, o.addEventListener("mouseleave", () => {
          r === "hover" && (d = setTimeout(() => {
            u();
          }, 200));
        }), o.addEventListener("click", (c) => {
          c.preventDefault(), o.removeEventListener("dblclick", M), b === i.uuid && o.addEventListener("dblclick", M), W(), s && f.behaviors.neoAlchemistComponentParent.scrollElementIntoView(o.getBoundingClientRect(), s, 100);
        }), e.insertAdjacentElement("afterend", o), h[n] = o;
        const l = t.cloneNode(!0);
        l.id = `neo-alchemist--shade-${n}`, l.addEventListener("click", (c) => {
          c.preventDefault(), u(0);
        }), t.insertAdjacentElement("afterend", l), E[n] = l;
      }), e.remove(), t.remove();
    }
    function P(e, t, n, o = !1) {
      if (!s) return;
      e.classList.add("is-active", "cursor-pointer");
      const l = s.getBoundingClientRect(), c = t.getBoundingClientRect(), a = parseFloat(S), y = s.scrollTop + c.top + n.top * a + window.scrollY - l.top - 10, x = s.scrollLeft + c.left + n.left * a + window.scrollX - l.left, D = n.height * a + 20, G = n.width * a + 0;
      o && e.classList.remove("!transition-all"), e.style.top = `${y}px`, e.style.left = `${x}px`, e.style.width = `${G}px`, e.style.height = `${D}px`, o && setTimeout(() => {
        e.classList.add("!transition-all");
      }, 100);
    }
    function z(e, t) {
      _(e).forEach((n) => {
        n.contentWindow && n.contentWindow.postMessage({
          type: "componentHover",
          uuid: t
        }, "*");
      });
    }
    const k = {
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
    function R(e, t) {
      var l;
      const [n, o] = t.includes("-") ? t.split("-", 2) : [t, void 0];
      (l = e == null ? void 0 : e.ops) != null && l[t] && k[n] && k[n](e, o);
    }
    function W() {
      !i || !s || (r = "focus", U(), X(), Y(), O(), b = i.uuid);
    }
    function U() {
      if (!s) return;
      const e = s.querySelector(".title");
      e && (e.innerHTML = `<span>${i.label}</span>`, i.warnings && i.warnings.length > 0 && i.warnings.forEach((t) => {
        e.innerHTML = `<span class="badge px-2 rounded bg-warning-500 text-warning-content-500">${t}</span>` + e.innerHTML;
      }), i.alerts && i.alerts.length > 0 && i.alerts.forEach((t) => {
        e.innerHTML = `<span class="badge px-2 rounded bg-alert-500 text-alert-content-500">${t}</span>` + e.innerHTML;
      }));
    }
    function X() {
      if (!s) return;
      s.querySelectorAll(".op").forEach((t) => {
        t.style.display = "none";
      }), i.ops && Object.entries(i.ops).forEach(([t, n]) => {
        n && s.querySelectorAll(`[data-op="${t}"]`).forEach((l) => {
          l.style.display = "";
        });
      });
    }
    function Y() {
      !g || !s || g.forEach((e) => {
        const t = e.getAttribute("data-placement"), n = s.getBoundingClientRect();
        switch (t) {
          case "top":
            e.style.top = `${n.top}px`;
            break;
          case "bottom":
            const o = window.innerHeight - n.top - n.height;
            e.style.bottom = `${o}px`;
            break;
        }
        e.classList.add("is-focus"), e.style.display = "";
      });
    }
    function O() {
      $.forEach((e) => {
        const t = L(e), n = h[e], o = E[e];
        s && t && n && o && (N(o, t), V(o, n, t), n.classList.add("is-focus"), o.classList.add("is-active"));
      });
    }
    function N(e, t) {
      if (!s) return;
      const n = s.getBoundingClientRect(), o = t.getBoundingClientRect();
      e.style.top = `${s.scrollTop + o.top - n.top}px`, e.style.left = `${s.scrollLeft + o.left - n.left}px`, e.style.width = `${o.width}px`, e.style.height = `${o.height}px`;
    }
    function V(e, t, n) {
      const o = t.getBoundingClientRect(), l = n.getBoundingClientRect(), c = o.top - l.top, a = o.left - l.left, y = a + o.width, x = c + o.height;
      e.style.clipPath = `polygon(0% 0%, 0% 100%, ${a}px 100%, ${a}px ${c}px, ${y}px ${c}px, ${y}px ${x}px, ${a}px ${x}px, ${a}px 100%, 100% 100%, 100% 0%)`;
    }
    function u(e = null) {
      e = e === null ? 100 : e, d && clearTimeout(d), d = setTimeout(() => {
        d = null, g && g.forEach((t) => t.classList.remove("is-focus")), $.forEach((t) => {
          const n = h[t];
          n && n.classList.remove("is-active", "is-focus", "!transition-all");
          const o = E[t];
          o && o.classList.remove("is-active", "!transition-all");
        });
      }, e), i = {}, r = null;
    }
    function L(e) {
      return Array.from(w).find((t) => t.getAttribute("data-size") === e);
    }
    function _(e) {
      return Array.from(w).filter((t) => t.getAttribute("data-size") !== e);
    }
  });
})(Drupal, once);
//# sourceMappingURL=components-parent.js.map
