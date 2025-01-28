(function(a, s) {
  const e = document.getElementById("neo-alchemist--messages");
  e && (setTimeout(() => {
    e.classList.add("transition-all"), e.classList.remove("opacity-0", "-translate-y-full");
  }, 100), e.querySelector(".kint-rich") ? e.classList.remove("fixed") : setTimeout(() => {
    e == null || e.classList.add("opacity-0", "-translate-y-full");
  }, 4e3)), a.behaviors.neoAlchemistComponentPreview = {
    attach: function() {
      s("neo.alchemist.disable", "[data-component-id] a").forEach((t) => {
        t.setAttribute("aria-disabled", "true"), t.addEventListener("click", (i) => {
          i.preventDefault();
        });
      });
    }
  };
})(Drupal, once);
//# sourceMappingURL=component-preview.js.map
