(function(q, L) {
  q.behaviors.neoAlchemistComponentParent = {
    scale: 1,
    attach: function() {
      L("neo.alchemist.component.parent", ".neo-alchemist-manage").forEach((o) => {
        B(o);
      });
    },
    scrollElementIntoView: function(o, a = document.documentElement, r = 0, i = "smooth") {
      const c = a.getBoundingClientRect(), f = o instanceof DOMRect ? o : o.getBoundingClientRect(), m = typeof r == "number" ? { top: r, bottom: r, left: r, right: r } : { top: 0, bottom: 0, left: 0, right: 0, ...r }, d = a === document.documentElement, g = f.top - (d ? 0 : c.top), b = f.bottom - (d ? 0 : c.top), S = d ? 0 : m.top, w = (d ? window.innerHeight : c.height) - m.bottom, E = f.left - (d ? 0 : c.left), p = f.right - (d ? 0 : c.left), z = 0, y = d ? window.innerWidth : c.width;
      let t = a.scrollTop, e = a.scrollLeft, s = !1;
      if ((g < S || b > w) && (t += g - m.top, s = !0), c.width < f.width) {
        const n = (E + p) / 2, l = (z + y) / 2, u = n - l;
        e += u, s = !0;
      } else E < z ? (e += E - m.left, s = !0) : p > y && (e += p - y + m.right, s = !0);
      s && a.scrollTo({
        top: t,
        left: e,
        behavior: i
      });
    }
  };
  function B(o) {
    window.addEventListener("message", function(t) {
      const e = t.data;
      if (typeof e.id == "string" && e.id === o.id && typeof e.type == "string") {
        if (typeof b[e.type] != "function")
          return;
        b[e.type](e);
      }
    });
    let a = !1;
    const r = o.querySelectorAll("iframe"), i = o.querySelector(".neo-alchemist-manage--wrapper"), c = document.querySelector(".alchemist-messages"), f = o.querySelector(".neo-alchemist-manage--drag");
    f && z(f);
    const m = document.getElementById("neo-alchemist-thumbnail-generate-button");
    m && m.addEventListener("click", (t) => {
      t.preventDefault(), m.setAttribute("disabled", "disabled");
      const e = y("desktop");
      e && e.contentWindow && e.contentWindow.postMessage({
        type: "screenshot"
      }, "*");
    }), c && setTimeout(() => {
      c.classList.add("opacity-100"), c.classList.remove("invisible", "opacity-0"), setTimeout(() => {
        if (!(c.querySelector(".sf-dump") || c.querySelector(".kint-rich"))) {
          const e = c.querySelector(".messages--wrapper");
          e && I(e);
        }
      }, 3e3);
    }, 100), [
      { id: "expand", contentHeight: "0%", formHeight: "100%", hideIframe: !0, hideForm: !1, active: !1 },
      { id: "split", contentHeight: "50%", formHeight: "50%", hideIframe: !1, hideForm: !1, active: !0 },
      { id: "contract", contentHeight: "100%", formHeight: "0%", hideIframe: !1, hideForm: !0, active: !1 }
    ].forEach((t) => {
      L("neo.alchemist", ".neo-alchemist-manage--size-" + t.id, o).forEach((e) => {
        const s = o.querySelector(".neo-alchemist-manage--wrapper"), n = o.querySelector(".neo-alchemist-manage--form");
        t.active && (e.classList.add("is-active"), s.style.height = t.contentHeight, n.style.height = t.formHeight), s.style.transition = "all 500ms", n.style.transition = "all 500ms", e.addEventListener("click", (l) => {
          l.preventDefault(), o.querySelectorAll(".neo-alchemist--sizing").forEach((T) => {
            T.classList.remove("is-active");
          }), e.classList.add("is-active"), s.style.height = t.contentHeight, s.style.transform = t.hideIframe ? "scale(0.5)" : "", s.style.opacity = t.hideIframe ? "0" : "", n.style.height = t.formHeight, n.style.transform = t.hideForm ? "scale(0.5)" : "", n.style.opacity = t.hideForm ? "0" : "";
        });
      });
    });
    const d = o.querySelectorAll(".neo-alchemist--focus");
    [
      { size: "desktop", active: !0 },
      { size: "tablet", active: !1 },
      { size: "mobile", active: !1 }
    ].forEach((t) => {
      L("neo.alchemist", '.neo-alchemist--focus[data-size="' + t.size + '"]').forEach((e) => {
        t.active && e.classList.add("is-active"), e.addEventListener("click", (s) => {
          var l;
          s.preventDefault();
          const n = y(t.size);
          n && (d.forEach((u) => {
            u.classList.remove("is-active");
          }), e.classList.add("is-active"), (l = n.closest(".neo-alchemist--iframe-wrapper")) == null || l.scrollIntoView({
            behavior: "smooth",
            block: "start",
            inline: "center"
          }));
        });
      });
    });
    let g = 0;
    const b = {
      size: function(t) {
        const e = t.size, s = Math.max(t.height, 0), n = Array.from(r).find(
          (l) => l.getAttribute("data-size") === e
        );
        if (n instanceof HTMLIFrameElement) {
          g++, n.style.height = s + "px";
          const l = n.closest(".neo-alchemist--iframe-wrapper"), u = l == null ? void 0 : l.querySelector(".neo-alchemist--iframe-size");
          u && (u.innerHTML = n.clientWidth + "×" + s), i && g === r.length && (i.style.visibility = "");
        }
      },
      messages: function(t) {
        const e = document.querySelector(".alchemist-messages");
        if (e) {
          const s = document.createElement("div");
          s.classList.add("neo-alchemist--messages-content"), s.innerHTML = t.messages, e.appendChild(s), setTimeout(() => {
            I(s);
          }, 3e3);
        }
      },
      screenshot: function(t) {
        const e = document.getElementById("neo-alchemist-thumbnail-generate-button");
        e instanceof HTMLButtonElement && (e.innerHTML = "Image Generated <small>Save component to finish capture</small>");
        const s = document.getElementById("neo-alchemist-thumbnail-generate-data");
        s && (s.value = t.dataUrl);
      }
    }, S = document.body.clientWidth / 3;
    f.style.paddingLeft = S + "px", f.style.paddingRight = S + "px";
    const w = localStorage.getItem("neo-alchemist-scale") || "1", E = o.querySelectorAll(".neo-alchemist--scale");
    [
      { size: "full", scale: "1" },
      { size: "75", scale: "0.75" },
      { size: "50", scale: "0.5" }
    ].forEach((t) => {
      L("neo.alchemist", '.neo-alchemist--scale[data-size="' + t.size + '"]').forEach((e) => {
        w === t.scale && e.classList.add("is-active"), e.addEventListener("click", (s) => {
          s.preventDefault(), E.forEach((n) => {
            n.classList.remove("is-active");
          }), e.classList.add("is-active"), p(t.scale);
        });
      });
    }), p(w);
    function p(t) {
      const e = o.querySelector(".neo-alchemist-manage--scale");
      if (e) {
        if (a || (e.style.transformOrigin = "top left"), e.style.transform = `scale(${t})`, i) {
          const n = e.getBoundingClientRect();
          i.scrollTo({
            top: 0,
            left: n.left + i.scrollLeft,
            behavior: a ? "smooth" : "auto"
          });
        }
        a || (e.style.transition = "transform 0.2s ease-in-out");
      }
      localStorage.setItem("neo-alchemist-scale", t);
      const s = new CustomEvent("alchemistManageScale", {
        bubbles: !0,
        cancelable: !0,
        detail: {
          scale: t
        }
      });
      o.dispatchEvent(s);
    }
    function z(t) {
      let e, s, n, l;
      t.addEventListener("mousedown", u);
      function u(h) {
        i && (i.style.userSelect = "none", t.style.cursor = "grabbing", e = h.clientX, s = h.clientY, n = i.scrollLeft, l = i.scrollTop, document.addEventListener("mouseup", H), document.addEventListener("mousemove", T), r.forEach((v) => {
          v instanceof HTMLIFrameElement && (v.style.pointerEvents = "none");
        }), d.forEach((v) => {
          v.classList.remove("is-active");
        }));
      }
      function T(h) {
        if (i) {
          const v = h.clientX - e, C = h.clientY - s;
          i.style.userSelect = "", i.scrollLeft = n - v, i.scrollTop = l - C;
        }
      }
      function H() {
        t.style.cursor = "grab", document.removeEventListener("mouseup", H), document.removeEventListener("mousemove", T), r.forEach((h) => {
          h instanceof HTMLIFrameElement && (h.style.pointerEvents = "");
        });
      }
    }
    function y(t) {
      return Array.from(r).find((e) => e.getAttribute("data-size") === t);
    }
    a = !0;
  }
  function I(o, a = 500, r) {
    const i = typeof o == "string" ? document.getElementById(o) : o;
    if (!i) {
      console.error(`Element ${typeof o == "string" ? o : "provided"} not found`);
      return;
    }
    const c = window.getComputedStyle(i).opacity;
    i.style.opacity = c, window.getComputedStyle(i).position === "static" && (i.style.position = "relative"), i.style.transition = `opacity ${a}ms ease, transform ${a}ms ease`, i.style.opacity = "0", i.style.transform = "translateY(-1rem)", setTimeout(() => {
      var m;
      (m = i.parentNode) == null || m.removeChild(i);
    }, a);
  }
})(Drupal, once);
//# sourceMappingURL=component-parent.js.map
