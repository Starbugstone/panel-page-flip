const COOKIE_NAME = "XSRF-TOKEN";

/**
 * The token Symfony put in the XSRF-TOKEN cookie, ready to be sent back.
 *
 * Split on the first `=` only, and decode: a cookie value is percent-encoded on
 * the wire, and only the name is guaranteed to be free of `=`. Symfony's
 * current token happens to be URL-safe base64 with no padding, so neither
 * detail shows today — but the header is compared byte for byte on the server,
 * so a token that ever gains a `=` or a `%` would fail every unsafe request
 * with a 403 that looks nothing like a cookie-parsing bug.
 */
export function getCsrfToken() {
  const cookies = (typeof document === "undefined" ? "" : document.cookie || "").split(";");

  for (const cookie of cookies) {
    const trimmed = cookie.trim();
    const separator = trimmed.indexOf("=");
    if (separator === -1) continue;

    if (trimmed.slice(0, separator) !== COOKIE_NAME) continue;

    const raw = trimmed.slice(separator + 1);
    try {
      return decodeURIComponent(raw);
    } catch {
      // A malformed percent-escape is not a token; sending it raw would only
      // trade a readable 403 for an unreadable one.
      return raw;
    }
  }

  return "";
}

export function getCsrfHeaders() {
  const token = getCsrfToken();
  return token ? { "X-XSRF-TOKEN": token } : {};
}
