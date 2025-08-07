(function(B, S) {
  B.behaviors.neoAlchemistComponentParent = {
    scale: 1,
    attach: function() {
      S("neo.alchemist.component.parent", ".neo-alchemist-manage").forEach((i) => {
        C(i);
      });
    },
    scrollElementIntoView: function(i, a = document.documentElement, r = 0, s = "smooth") {
      const c = a.getBoundingClientRect(), f = i instanceof DOMRect ? i : i.getBoundingClientRect(), m = typeof r == "number" ? { top: r, bottom: r, left: r, right: r } : { top: 0, bottom: 0, left: 0, right: 0, ...r }, d = a === document.documentElement, g = f.top - (d ? 0 : c.top), w = f.bottom - (d ? 0 : c.top), z = d ? 0 : m.top, I = (d ? window.innerHeight : c.height) - m.bottom, p = f.left - (d ? 0 : c.left), E = f.right - (d ? 0 : c.left), L = 0, b = d ? window.innerWidth : c.width;
      let y = a.scrollTop, t = a.scrollLeft, e = !1;
      if ((g < z || w > I) && (y += g - m.top, e = !0), c.width < f.width) {
        const o = (p + E) / 2, n = (L + b) / 2, l = o - n;
        t += l, e = !0;
      } else p < L ? (t += p - m.left, e = !0) : E > b && (t += E - b + m.right, e = !0);
      e && a.scrollTo({
        top: y,
        left: t,
        behavior: s
      });
    }
  };
  function C(i) {
    window.addEventListener("message", function(t) {
      const e = t.data;
      if (typeof e.id == "string" && e.id === i.id && typeof e.type == "string") {
        if (typeof z[e.type] != "function")
          return;
        z[e.type](e);
      }
    });
    let a = !1;
    const r = i.querySelectorAll("iframe"), s = i.querySelector(".neo-alchemist-manage--wrapper"), c = document.querySelector(".alchemist-messages"), f = i.querySelector(".neo-alchemist-manage--drag");
    f && b(f);
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
          e && H(e);
        }
      }, 3e3);
    }, 100), r.forEach((t) => {
      t.onload = () => {
        if (t.contentWindow) {
          const e = t.contentWindow.document.querySelector("html");
          e && !e.classList.contains("js") && s && (s.style.visibility = "", t.style.height = e.offsetHeight + "px");
        }
      };
    });
    const d = localStorage.getItem("neo-alchemist-size") || "split";
    [
      { id: "expand", contentHeight: "0%", formHeight: "100%", hideIframe: !0, hideForm: !1, active: d === "expand" },
      { id: "split", contentHeight: "50%", formHeight: "50%", hideIframe: !1, hideForm: !1, active: d === "split" },
      { id: "contract", contentHeight: "100%", formHeight: "0%", hideIframe: !1, hideForm: !0, active: d === "contract" }
    ].forEach((t) => {
      S("neo.alchemist", ".neo-alchemist-manage--size-" + t.id, i).forEach((e) => {
        const o = i.querySelector(".neo-alchemist-manage--wrapper"), n = i.querySelector(".neo-alchemist-manage--form");
        t.active && (e.classList.add("is-active"), o.style.height = t.contentHeight, n.style.height = t.formHeight), o.style.transition = "all 500ms", n.style.transition = "all 500ms", e.addEventListener("click", (l) => {
          l.preventDefault(), i.querySelectorAll(".neo-alchemist--sizing").forEach((T) => {
            T.classList.remove("is-active");
          }), localStorage.setItem("neo-alchemist-size", t.id), e.classList.add("is-active"), o.style.height = t.contentHeight, o.style.transform = t.hideIframe ? "scale(0.5)" : "", o.style.opacity = t.hideIframe ? "0" : "", n.style.height = t.formHeight, n.style.transform = t.hideForm ? "scale(0.5)" : "", n.style.opacity = t.hideForm ? "0" : "";
        });
      });
    });
    const g = i.querySelectorAll(".neo-alchemist--focus");
    [
      { size: "desktop", active: !0 },
      { size: "tablet", active: !1 },
      { size: "mobile", active: !1 }
    ].forEach((t) => {
      S("neo.alchemist", '.neo-alchemist--focus[data-size="' + t.size + '"]').forEach((e) => {
        t.active && e.classList.add("is-active"), e.addEventListener("click", (o) => {
          var l;
          o.preventDefault();
          const n = y(t.size);
          n && (g.forEach((u) => {
            u.classList.remove("is-active");
          }), e.classList.add("is-active"), (l = n.closest(".neo-alchemist--iframe-wrapper")) == null || l.scrollIntoView({
            behavior: "smooth",
            block: "start",
            inline: "center"
          }));
        });
      });
    });
    let w = 0;
    const z = {
      size: function(t) {
        const e = t.size, o = Math.max(t.height, 0), n = Array.from(r).find(
          (l) => l.getAttribute("data-size") === e
        );
        if (n instanceof HTMLIFrameElement) {
          w++, n.style.height = o + "px";
          const l = n.closest(".neo-alchemist--iframe-wrapper"), u = l == null ? void 0 : l.querySelector(".neo-alchemist--iframe-size");
          u && (u.innerHTML = n.clientWidth + "×" + o), s && w === r.length && (s.style.visibility = "");
        }
      },
      messages: function(t) {
        const e = document.querySelector(".alchemist-messages");
        if (e) {
          const o = document.createElement("div");
          o.classList.add("neo-alchemist--messages-content"), o.innerHTML = t.messages, e.appendChild(o), setTimeout(() => {
            H(o);
          }, 3e3);
        }
      },
      screenshot: function(t) {
        const e = document.getElementById("neo-alchemist-thumbnail-generate-button");
        e instanceof HTMLButtonElement && (e.innerHTML = "Image Generated <small>Save component to finish capture</small>");
        const o = document.getElementById("neo-alchemist-thumbnail-generate-data");
        o && (o.value = t.dataUrl);
      }
    }, I = document.body.clientWidth / 3;
    f.style.paddingLeft = I + "px", f.style.paddingRight = I + "px";
    const p = localStorage.getItem("neo-alchemist-scale") || "1", E = i.querySelectorAll(".neo-alchemist--scale");
    [
      { size: "full", scale: "1" },
      { size: "75", scale: "0.75" },
      { size: "50", scale: "0.5" }
    ].forEach((t) => {
      S("neo.alchemist", '.neo-alchemist--scale[data-size="' + t.size + '"]').forEach((e) => {
        p === t.scale && e.classList.add("is-active"), e.addEventListener("click", (o) => {
          o.preventDefault(), E.forEach((n) => {
            n.classList.remove("is-active");
          }), e.classList.add("is-active"), L(t.scale);
        });
      });
    }), L(p);
    function L(t) {
      const e = i.querySelector(".neo-alchemist-manage--scale");
      if (e) {
        if (a || (e.style.transformOrigin = "top left"), e.style.transform = `scale(${t})`, s) {
          const n = e.getBoundingClientRect();
          s.scrollTo({
            top: 0,
            left: n.left + s.scrollLeft,
            behavior: a ? "smooth" : "auto"
          });
        }
        a || (e.style.transition = "transform 0.2s ease-in-out");
      }
      localStorage.setItem("neo-alchemist-scale", t);
      const o = new CustomEvent("alchemistManageScale", {
        bubbles: !0,
        cancelable: !0,
        detail: {
          scale: t
        }
      });
      i.dispatchEvent(o);
    }
    function b(t) {
      let e, o, n, l;
      t.addEventListener("mousedown", u);
      function u(h) {
        s && (s.style.userSelect = "none", t.style.cursor = "grabbing", e = h.clientX, o = h.clientY, n = s.scrollLeft, l = s.scrollTop, document.addEventListener("mouseup", q), document.addEventListener("mousemove", T), r.forEach((v) => {
          v instanceof HTMLIFrameElement && (v.style.pointerEvents = "none");
        }), g.forEach((v) => {
          v.classList.remove("is-active");
        }));
      }
      function T(h) {
        if (s) {
          const v = h.clientX - e, M = h.clientY - o;
          s.style.userSelect = "", s.scrollLeft = n - v, s.scrollTop = l - M;
        }
      }
      function q() {
        t.style.cursor = "grab", document.removeEventListener("mouseup", q), document.removeEventListener("mousemove", T), r.forEach((h) => {
          h instanceof HTMLIFrameElement && (h.style.pointerEvents = "");
        });
      }
    }
    function y(t) {
      return Array.from(r).find((e) => e.getAttribute("data-size") === t);
    }
    a = !0;
  }
  function H(i, a = 500, r) {
    const s = typeof i == "string" ? document.getElementById(i) : i;
    if (!s) {
      console.error(`Element ${typeof i == "string" ? i : "provided"} not found`);
      return;
    }
    const c = window.getComputedStyle(s).opacity;
    s.style.opacity = c, window.getComputedStyle(s).position === "static" && (s.style.position = "relative"), s.style.transition = `opacity ${a}ms ease, transform ${a}ms ease`, s.style.opacity = "0", s.style.transform = "translateY(-1rem)", setTimeout(() => {
      var m;
      (m = s.parentNode) == null || m.removeChild(s);
    }, a);
  }
})(Drupal, once);
//# sourceMappingURL=component-parent.js.map
