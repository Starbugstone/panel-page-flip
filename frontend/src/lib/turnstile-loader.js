const TURNSTILE_SCRIPT_ORIGIN = "https://challenges.cloudflare.com";
export const TURNSTILE_SCRIPT_URL = `${TURNSTILE_SCRIPT_ORIGIN}/turnstile/v0/api.js?render=explicit`;

let loadingPromise = null;

export function loadTurnstile() {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  if (loadingPromise) return loadingPromise;

  loadingPromise = new Promise((resolve, reject) => {
    let script = document.querySelector("script[data-panel-turnstile]");

    const loaded = () => {
      if (!window.turnstile) {
        loadingPromise = null;
        reject(new Error("Turnstile loaded without exposing its browser API."));
        return;
      }

      script.dataset.loaded = "true";
      resolve(window.turnstile);
    };
    const failed = () => {
      script.remove();
      loadingPromise = null;
      reject(new Error("Turnstile could not be loaded."));
    };

    if (script) {
      if (script.dataset.loaded === "true") {
        failed();
        return;
      }
      script.addEventListener("load", loaded, { once: true });
      script.addEventListener("error", failed, { once: true });
      return;
    }

    script = document.createElement("script");
    script.src = TURNSTILE_SCRIPT_URL;
    script.async = true;
    script.defer = true;
    script.dataset.panelTurnstile = "true";
    script.addEventListener("load", loaded, { once: true });
    script.addEventListener("error", failed, { once: true });
    document.head.appendChild(script);
  });

  return loadingPromise;
}
