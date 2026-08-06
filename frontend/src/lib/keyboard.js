/**
 * Whether a keyboard event landed somewhere the user is entering text.
 *
 * Global shortcuts have to check this. A reader that turns the page on every
 * ArrowLeft would move the comic while someone is editing the page number, and
 * a shortcut that focuses the search box would swallow a typed "/".
 */
export function isTypingTarget(target) {
  if (!target || typeof target !== "object") return false;

  const tagName = typeof target.tagName === "string" ? target.tagName.toUpperCase() : "";
  if (tagName === "INPUT" || tagName === "TEXTAREA" || tagName === "SELECT") return true;

  return target.isContentEditable === true;
}
