(function(q, E) {
  q.behaviors.neoAlchemistComponentParent = {
    scale: 1,
    attach: function() {
      E("neo.alchemist.component.parent", ".neo-alchemist-manage").forEach((o) => {
        B(o);
      });
    },
    scrollElementIntoView: function(o, l = document.documentElement, a = 0, i = "smooth") {
      const c = l.getBoundingClientRect(), f = o instanceof DOMRect ? o : o.getBoundingClientRect(), r = typeof a == "number" ? { top: a, bottom: a, left: a, right: a } : { top: 0, bottom: 0, left: 0, right: 0, ...a }, d = l === document.documentElement, g = f.top - (d ? 0 : c.top), L = f.bottom - (d ? 0 : c.top), b = d ? 0 : r.top, S = (d ? window.innerHeight : c.height) - r.bottom, z = f.left - (d ? 0 : c.left), y = f.right - (d ? 0 : c.left), T = 0, v = d ? window.innerWidth : c.width;
      let t = l.scrollTop, e = l.scrollLeft, s = !1;
      (g < b || L > S) && (t += g - r.top, s = !0), z < T ? (e += z - r.left, s = !0) : y > v && (e += y - v + r.right, s = !0), s && l.scrollTo({
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
        if (typeof L[e.type] != "function")
          return;
        L[e.type](e);
      }
    });
    let l = !1;
    const a = o.querySelectorAll("iframe"), i = o.querySelector(".neo-alchemist-manage--wrapper"), c = document.querySelector(".alchemist-messages"), f = o.querySelector(".neo-alchemist-manage--drag");
    f && T(f);
    const r = document.getElementById("neo-alchemist-thumbnail-generate-button");
    r && r.addEventListener("click", (t) => {
      t.preventDefault(), r.setAttribute("disabled", "disabled");
      const e = v("desktop");
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
      E("neo.alchemist", ".neo-alchemist-manage--size-" + t.id, o).forEach((e) => {
        const s = o.querySelector(".neo-alchemist-manage--wrapper"), n = o.querySelector(".neo-alchemist-manage--form");
        t.active && (e.classList.add("is-active"), s.style.height = t.contentHeight, n.style.height = t.formHeight), s.style.transition = "all 500ms", n.style.transition = "all 500ms", e.addEventListener("click", (m) => {
          m.preventDefault(), o.querySelectorAll(".neo-alchemist--sizing").forEach((w) => {
            w.classList.remove("is-active");
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
      E("neo.alchemist", '.neo-alchemist--focus[data-size="' + t.size + '"]').forEach((e) => {
        t.active && e.classList.add("is-active"), e.addEventListener("click", (s) => {
          var m;
          s.preventDefault();
          const n = v(t.size);
          n && (d.forEach((h) => {
            h.classList.remove("is-active");
          }), e.classList.add("is-active"), (m = n.closest(".neo-alchemist--iframe-wrapper")) == null || m.scrollIntoView({
            behavior: "smooth",
            block: "start",
            inline: "center"
          }));
        });
      });
    });
    let g = 0;
    const L = {
      size: function(t) {
        const e = t.size, s = Math.max(t.height, 0), n = Array.from(a).find(
          (m) => m.getAttribute("data-size") === e
        );
        if (n instanceof HTMLIFrameElement) {
          g++, n.style.height = s + "px";
          const m = n.closest(".neo-alchemist--iframe-wrapper"), h = m == null ? void 0 : m.querySelector(".neo-alchemist--iframe-size");
          h && (h.innerHTML = n.clientWidth + "×" + s), i && g === a.length && (i.style.visibility = "");
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
    }, b = document.body.clientWidth / 3;
    f.style.paddingLeft = b + "px", f.style.paddingRight = b + "px";
    const S = localStorage.getItem("neo-alchemist-scale") || "1", z = o.querySelectorAll(".neo-alchemist--scale");
    [
      { size: "full", scale: "1" },
      { size: "75", scale: "0.75" },
      { size: "50", scale: "0.5" }
    ].forEach((t) => {
      E("neo.alchemist", '.neo-alchemist--scale[data-size="' + t.size + '"]').forEach((e) => {
        S === t.scale && e.classList.add("is-active"), e.addEventListener("click", (s) => {
          s.preventDefault(), z.forEach((n) => {
            n.classList.remove("is-active");
          }), e.classList.add("is-active"), y(t.scale);
        });
      });
    }), y(S);
    function y(t) {
      const e = o.querySelector(".neo-alchemist-manage--scale");
      if (e) {
        if (l || (e.style.transformOrigin = "top left"), e.style.transform = `scale(${t})`, i) {
          const n = e.getBoundingClientRect();
          i.scrollTo({
            top: 0,
            left: n.left + i.scrollLeft,
            behavior: l ? "smooth" : "auto"
          });
        }
        l || (e.style.transition = "transform 0.2s ease-in-out");
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
    function T(t) {
      let e, s, n, m;
      t.addEventListener("mousedown", h);
      function h(u) {
        i && (i.style.userSelect = "none", t.style.cursor = "grabbing", e = u.clientX, s = u.clientY, n = i.scrollLeft, m = i.scrollTop, document.addEventListener("mouseup", H), document.addEventListener("mousemove", w), a.forEach((p) => {
          p instanceof HTMLIFrameElement && (p.style.pointerEvents = "none");
        }), d.forEach((p) => {
          p.classList.remove("is-active");
        }));
      }
      function w(u) {
        if (i) {
          const p = u.clientX - e, M = u.clientY - s;
          i.style.userSelect = "", i.scrollLeft = n - p, i.scrollTop = m - M;
        }
      }
      function H() {
        t.style.cursor = "grab", document.removeEventListener("mouseup", H), document.removeEventListener("mousemove", w), a.forEach((u) => {
          u instanceof HTMLIFrameElement && (u.style.pointerEvents = "");
        });
      }
    }
    function v(t) {
      return Array.from(a).find((e) => e.getAttribute("data-size") === t);
    }
    l = !0;
  }
  function I(o, l = 500, a) {
    const i = typeof o == "string" ? document.getElementById(o) : o;
    if (!i) {
      console.error(`Element ${typeof o == "string" ? o : "provided"} not found`);
      return;
    }
    const c = window.getComputedStyle(i).opacity;
    i.style.opacity = c, window.getComputedStyle(i).position === "static" && (i.style.position = "relative"), i.style.transition = `opacity ${l}ms ease, transform ${l}ms ease`, i.style.opacity = "0", i.style.transform = "translateY(-1rem)", setTimeout(() => {
      var r;
      (r = i.parentNode) == null || r.removeChild(i);
    }, l);
  }
})(Drupal, once);
//# sourceMappingURL=component-parent.js.map
