(function() {
  "use strict";

  const preloader = document.querySelector("#preloader");
  if (!preloader) return;

  const hidePreloader = () => {
    if (!preloader.isConnected) return;

    preloader.style.opacity = "0";
    preloader.style.pointerEvents = "none";

    setTimeout(() => {
      if (preloader.isConnected) {
        preloader.remove();
      }
    }, 600);
  };

  window.addEventListener("load", hidePreloader, { once: true });
  window.setTimeout(hidePreloader, 2500);
})();
