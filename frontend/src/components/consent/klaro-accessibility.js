const CONTROLS = "button:not([disabled]), a[href], input:not([disabled])";

export function enhanceKlaroAccessibility(container, config) {
  const previousFocus = document.activeElement;
  const label = config.translations[config.lang].consentModal.title;

  // Klaro 0.7's notice has a dialog role; its customization panel does not,
  // and service switches can receive tabindex=-1. Complete those semantics.
  const enhance = () => {
    container.querySelector(".cookie-notice")?.setAttribute("aria-label", label);
    const modal = container.querySelector(".cookie-modal");
    if (!modal) return;
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-label", label);
    modal.setAttribute("aria-modal", "true");
    modal.querySelectorAll("input").forEach((input) => { input.tabIndex = 0; });
  };
  const observer = new MutationObserver(enhance);
  observer.observe(container, { childList: true, subtree: true });
  enhance();

  const trapFocus = (event) => {
    const modal = container.querySelector(".cookie-modal");
    if (!modal) return;
    if (event.key === "Escape") {
      event.preventDefault();
      modal.querySelector("button.hide")?.click();
      return;
    }
    if (event.key !== "Tab") return;
    const controls = [...modal.querySelectorAll(CONTROLS)];
    const first = controls[0];
    const last = controls.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  };
  container.addEventListener("keydown", trapFocus);

  return () => {
    observer.disconnect();
    container.removeEventListener("keydown", trapFocus);
    const target = previousFocus?.isConnected && previousFocus !== document.body
      ? previousFocus : document.getElementById("main-content");
    target?.focus();
  };
}
