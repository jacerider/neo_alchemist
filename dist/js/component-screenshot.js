(function(o) {
  const s = new URLSearchParams(window.location.search).get("id"), c = new URLSearchParams(window.location.search).get("size");
  window.addEventListener("message", function(r) {
    const n = r.data;
    if (typeof n.type == "string" && n.type === "screenshot") {
      const e = document.querySelector(".neo-alchemist-preview");
      if (!e)
        return;
      e.style.width = "1024px";
      const a = this.document.querySelectorAll("[data-component-id]");
      a.forEach((t) => {
        t.style.margin = "0px";
      }), o(e).then((t) => {
        e.style.width = "", a.forEach((i) => {
          i.style.margin = "";
        }), window.parent.postMessage({
          type: "screenshot",
          id: s,
          size: c,
          dataUrl: t.toDataURL()
        }, "*");
      });
    }
  });
})(html2canvas);
//# sourceMappingURL=component-screenshot.js.map
