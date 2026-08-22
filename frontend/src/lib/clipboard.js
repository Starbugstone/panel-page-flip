/**
 * Copying text, on the origins this application is actually served from.
 *
 * `navigator.clipboard` exists only in a secure context. A self-hosted install
 * reached over plain HTTP — a LAN address, a reverse proxy nobody has put a
 * certificate on yet — has no `navigator.clipboard` at all, so every "Copy
 * code" button on it failed with "Could not copy the code" and no way forward.
 * That is exactly the deployment this project is for.
 *
 * So the async API is tried first and the old selection-based command is the
 * fallback. `execCommand` is deprecated and still implemented everywhere,
 * which is the right trade for a button whose alternative is not working.
 */

/**
 * Put `text` on the clipboard.
 *
 * @param {string} text
 * @returns {Promise<boolean>} whether it actually got there.
 */
export async function copyText(text) {
  if (typeof text !== "string" || text === "") return false;

  if (navigator?.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // Permission refused, or a secure-context check that the feature
      // detection above did not catch. The fallback below may still work.
    }
  }

  return copyBySelection(text);
}

/**
 * The pre-`navigator.clipboard` way: put the text in a field, select it, and
 * ask the document to copy the selection.
 *
 * The textarea has to be in the document and focusable for the selection to
 * exist, so it is positioned off screen rather than hidden — `display: none`
 * and `visibility: hidden` cannot hold a selection.
 */
function copyBySelection(text) {
  if (typeof document === "undefined" || typeof document.execCommand !== "function") return false;

  const field = document.createElement("textarea");
  field.value = text;
  field.setAttribute("readonly", "");
  field.setAttribute("aria-hidden", "true");
  field.style.position = "fixed";
  field.style.top = "-9999px";
  field.style.opacity = "0";

  // Restoring focus matters: the copy button is usually inside a dialog, and
  // leaving focus on a textarea that is about to be removed drops it to the
  // body and closes the ring around the dialog.
  const previouslyFocused = document.activeElement;
  document.body.appendChild(field);

  try {
    field.select();
    field.setSelectionRange(0, text.length);
    return document.execCommand("copy");
  } catch {
    return false;
  } finally {
    field.remove();
    if (previouslyFocused instanceof HTMLElement) previouslyFocused.focus();
  }
}
