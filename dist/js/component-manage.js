(function(l, s, r) {
  l.behaviors.neoAlchemistComponentManage = {
    attach: function() {
      r && r(!0), s("neo.alchemist", "#neo-alchemist--messages").forEach((e) => {
        setTimeout(() => {
          e.classList.add("transition-all"), e.classList.remove("opacity-0", "-translate-y-full");
        }, 100), e.querySelector(".kint-rich") || setTimeout(() => {
          e == null || e.classList.add("opacity-0", "-translate-y-full");
        }, 4e3);
      }), [
        { id: "expand", iframeHeight: "0%", formHeight: "100%", hideIframe: !0, hideForm: !1, active: !1 },
        { id: "split", iframeHeight: "50%", formHeight: "50%", hideIframe: !1, hideForm: !1, active: !0 },
        { id: "contract", iframeHeight: "100%", formHeight: "0%", hideIframe: !1, hideForm: !0, active: !1 }
      ].forEach((e) => {
        s("neo.alchemist", "#neo-alchemist--size-" + e.id).forEach((i) => {
          const t = document.getElementById("neo-alchemist--iframe-form-wrapper") || document.getElementById("neo-alchemist--iframe-wrapper"), a = document.getElementById("neo-alchemist--form") || document.getElementById("neo-alchemist--iframe");
          e.active && (i.classList.add("is-active"), t.style.height = e.iframeHeight, a.style.height = e.formHeight), t.style.transition = "all 500ms", a.style.transition = "all 500ms", i.addEventListener("click", (c) => {
            c.preventDefault(), document.querySelectorAll(".neo-alchemist--sizing").forEach((o) => {
              o.classList.remove("is-active");
            }), i.classList.add("is-active"), t.style.height = e.iframeHeight, t.style.transform = e.hideIframe ? "scale(0.5)" : "", t.style.opacity = e.hideIframe ? "0" : "", a.style.height = e.formHeight, a.style.transform = e.hideForm ? "scale(0.5)" : "", a.style.opacity = e.hideForm ? "0" : "";
          });
        });
      }), [
        { id: "sm", width: "440px", active: !1 },
        { id: "md", width: "768px", active: !1 },
        { id: "lg", width: "100%", active: !0 }
      ].forEach((e) => {
        s("neo.alchemist", "#neo-alchemist--resize-" + e.id).forEach((i) => {
          const t = document.getElementById("neo-alchemist--iframe-form") || document.getElementById("neo-alchemist--iframe");
          e.active && i.classList.add("is-active"), i.addEventListener("click", (a) => {
            a.preventDefault(), t && (document.querySelectorAll(".neo-alchemist--resize").forEach((c) => {
              c.classList.remove("is-active");
            }), i.classList.add("is-active"), t.style.maxWidth = e.width);
          });
        });
      });
    }
  };
})(Drupal, once, Drupal.displace);
//# sourceMappingURL=component-manage.js.map
