(function() {
  const o = new URLSearchParams(window.location.search).get("id"), s = new URLSearchParams(window.location.search).get("size");
  window.addEventListener("message", function(c) {
    const n = c.data;
    if (typeof n.type == "string" && n.type === "screenshot") {
      const e = document.querySelector(".neo-alchemist-preview");
      if (!e)
        return;
      e.style.width = "1024px";
      const a = this.document.querySelectorAll("[data-component-id]");
      a.forEach((t) => {
        t.style.margin = "0px";
      }), html2canvas(e).then((t) => {
        e.style.width = "", a.forEach((r) => {
          r.style.margin = "";
        }), window.parent.postMessage({
          type: "screenshot",
          id: o,
          size: s,
          dataUrl: t.toDataURL()
        }, "*");
      });
    }
  });
})();
//# sourceMappingURL=component-screenshot.js.map
