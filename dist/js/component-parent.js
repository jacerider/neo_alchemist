(function(q, v) {
  q.behaviors.neoAlchemistComponentParent = {
    scale: 1,
    attach: function() {
      v("neo.alchemist.component.parent", ".neo-alchemist-manage").forEach((n) => {
        I(n);
      });
    },
    scrollElementIntoView: function(n, l = document.documentElement, a = 0, s = "smooth") {
      const c = l.getBoundingClientRect(), f = n instanceof DOMRect ? n : n.getBoundingClientRect(), m = typeof a == "number" ? { top: a, bottom: a, left: a, right: a } : { top: 0, bottom: 0, left: 0, right: 0, ...a }, d = l === document.documentElement, g = f.top - (d ? 0 : c.top), E = f.bottom - (d ? 0 : c.top), L = d ? 0 : m.top, z = (d ? window.innerHeight : c.height) - m.bottom, y = f.left - (d ? 0 : c.left), b = f.right - (d ? 0 : c.left), w = 0, t = d ? window.innerWidth : c.width;
      let e = l.scrollTop, i = l.scrollLeft, o = !1;
      (g < L || E > z) && (e += g - m.top, o = !0), y < w ? (i += y - m.left, o = !0) : b > t && (i += b - t + m.right, o = !0), o && l.scrollTo({
        top: e,
        left: i,
        behavior: s
      });
    }
  };
  function I(n) {
    window.addEventListener("message", function(t) {
      const e = t.data;
      if (typeof e.id == "string" && e.id === n.id && typeof e.type == "string") {
        if (typeof g[e.type] != "function")
          return;
        g[e.type](e);
      }
    });
    let l = !1;
    const a = n.querySelectorAll("iframe"), s = n.querySelector(".neo-alchemist-manage--wrapper"), c = document.querySelector(".alchemist-messages"), f = n.querySelector(".neo-alchemist-manage--drag");
    f && b(f), c && setTimeout(() => {
      c.classList.add("opacity-100"), c.classList.remove("invisible", "opacity-0"), setTimeout(() => {
        if (!(c.querySelector(".sf-dump") || c.querySelector(".kint-rich"))) {
          const e = c.querySelector(".messages--wrapper");
          e && T(e);
        }
      }, 3e3);
    }, 100), [
      { id: "expand", contentHeight: "0%", formHeight: "100%", hideIframe: !0, hideForm: !1, active: !1 },
      { id: "split", contentHeight: "50%", formHeight: "50%", hideIframe: !1, hideForm: !1, active: !0 },
      { id: "contract", contentHeight: "100%", formHeight: "0%", hideIframe: !1, hideForm: !0, active: !1 }
    ].forEach((t) => {
      v("neo.alchemist", ".neo-alchemist-manage--size-" + t.id, n).forEach((e) => {
        const i = n.querySelector(".neo-alchemist-manage--wrapper"), o = n.querySelector(".neo-alchemist-manage--form");
        t.active && (e.classList.add("is-active"), i.style.height = t.contentHeight, o.style.height = t.formHeight), i.style.transition = "all 500ms", o.style.transition = "all 500ms", e.addEventListener("click", (r) => {
          r.preventDefault(), n.querySelectorAll(".neo-alchemist--sizing").forEach((S) => {
            S.classList.remove("is-active");
          }), e.classList.add("is-active"), i.style.height = t.contentHeight, i.style.transform = t.hideIframe ? "scale(0.5)" : "", i.style.opacity = t.hideIframe ? "0" : "", o.style.height = t.formHeight, o.style.transform = t.hideForm ? "scale(0.5)" : "", o.style.opacity = t.hideForm ? "0" : "";
        });
      });
    });
    const m = n.querySelectorAll(".neo-alchemist--focus");
    [
      { size: "desktop", active: !0 },
      { size: "tablet", active: !1 },
      { size: "mobile", active: !1 }
    ].forEach((t) => {
      v("neo.alchemist", '.neo-alchemist--focus[data-size="' + t.size + '"]').forEach((e) => {
        t.active && e.classList.add("is-active"), e.addEventListener("click", (i) => {
          var r;
          i.preventDefault();
          const o = w(t.size);
          o && (m.forEach((u) => {
            u.classList.remove("is-active");
          }), e.classList.add("is-active"), (r = o.closest(".neo-alchemist--iframe-wrapper")) == null || r.scrollIntoView({
            behavior: "smooth",
            block: "start",
            inline: "center"
          }));
        });
      });
    });
    let d = 0;
    const g = {
      size: function(t) {
        const e = t.size, i = Math.max(t.height, 0), o = Array.from(a).find(
          (r) => r.getAttribute("data-size") === e
        );
        if (o instanceof HTMLIFrameElement) {
          d++, o.style.height = i + "px";
          const r = o.closest(".neo-alchemist--iframe-wrapper"), u = r == null ? void 0 : r.querySelector(".neo-alchemist--iframe-size");
          u && (u.innerHTML = o.clientWidth + "×" + i), s && d === a.length && (s.style.visibility = "");
        }
      },
      messages: function(t) {
        const e = document.querySelector(".alchemist-messages");
        if (e) {
          const i = document.createElement("div");
          i.classList.add("neo-alchemist--messages-content"), i.innerHTML = t.messages, e.appendChild(i), setTimeout(() => {
            T(i);
          }, 3e3);
        }
      }
    }, E = document.body.clientWidth / 3;
    f.style.paddingLeft = E + "px", f.style.paddingRight = E + "px";
    const L = localStorage.getItem("neo-alchemist-scale") || "1", z = n.querySelectorAll(".neo-alchemist--scale");
    [
      { size: "full", scale: "1" },
      { size: "75", scale: "0.75" },
      { size: "50", scale: "0.5" }
    ].forEach((t) => {
      v("neo.alchemist", '.neo-alchemist--scale[data-size="' + t.size + '"]').forEach((e) => {
        L === t.scale && e.classList.add("is-active"), e.addEventListener("click", (i) => {
          i.preventDefault(), z.forEach((o) => {
            o.classList.remove("is-active");
          }), e.classList.add("is-active"), y(t.scale);
        });
      });
    }), y(L);
    function y(t) {
      const e = n.querySelector(".neo-alchemist-manage--scale");
      if (e) {
        if (l || (e.style.transformOrigin = "top left"), e.style.transform = `scale(${t})`, s) {
          const o = e.getBoundingClientRect();
          s.scrollTo({
            top: 0,
            left: o.left + s.scrollLeft,
            behavior: l ? "smooth" : "auto"
          });
        }
        l || (e.style.transition = "transform 0.2s ease-in-out");
      }
      localStorage.setItem("neo-alchemist-scale", t);
      const i = new CustomEvent("alchemistManageScale", {
        bubbles: !0,
        cancelable: !0,
        detail: {
          scale: t
        }
      });
      n.dispatchEvent(i);
    }
    function b(t) {
      let e, i, o, r;
      t.addEventListener("mousedown", u);
      function u(h) {
        s && (s.style.userSelect = "none", t.style.cursor = "grabbing", e = h.clientX, i = h.clientY, o = s.scrollLeft, r = s.scrollTop, document.addEventListener("mouseup", H), document.addEventListener("mousemove", S), a.forEach((p) => {
          p instanceof HTMLIFrameElement && (p.style.pointerEvents = "none");
        }), m.forEach((p) => {
          p.classList.remove("is-active");
        }));
      }
      function S(h) {
        if (s) {
          const p = h.clientX - e, C = h.clientY - i;
          s.style.userSelect = "", s.scrollLeft = o - p, s.scrollTop = r - C;
        }
      }
      function H() {
        t.style.cursor = "grab", document.removeEventListener("mouseup", H), document.removeEventListener("mousemove", S), a.forEach((h) => {
          h instanceof HTMLIFrameElement && (h.style.pointerEvents = "");
        });
      }
    }
    function w(t) {
      return Array.from(a).find((e) => e.getAttribute("data-size") === t);
    }
    l = !0;
  }
  function T(n, l = 500, a) {
    const s = typeof n == "string" ? document.getElementById(n) : n;
    if (!s) {
      console.error(`Element ${typeof n == "string" ? n : "provided"} not found`);
      return;
    }
    const c = window.getComputedStyle(s).opacity;
    s.style.opacity = c, window.getComputedStyle(s).position === "static" && (s.style.position = "relative"), s.style.transition = `opacity ${l}ms ease, transform ${l}ms ease`, s.style.opacity = "0", s.style.transform = "translateY(-1rem)", setTimeout(() => {
      var m;
      (m = s.parentNode) == null || m.removeChild(s);
    }, l);
  }
})(Drupal, once);
//# sourceMappingURL=component-parent.js.map
