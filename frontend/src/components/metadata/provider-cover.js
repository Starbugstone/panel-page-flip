/**
 * The hosts a provider's cover may be drawn from. Each must also be in `img-src`
 * in `backend/config/csp.json`; the two are checked against each other in
 * `provider-cover.test.js`.
 *
 * Comic Vine is deliberately absent. Its image host has never been seen in a real
 * response (see docs/metadata-enrichment.md), so its records show no cover until
 * one has, rather than allowing a guess.
 */
const ALLOWED_COVER_HOSTS = new Set(["static.metron.cloud"]);

/**
 * A provider's cover address if it is safe to hand to an `<img>`, otherwise null.
 *
 * The address arrives from a third party, so it is parsed rather than matched as
 * text: `https://static.metron.cloud.example/` and
 * `https://static.metron.cloud@example/` both begin the right way. A host the
 * policy does not allow would only ever render as a broken image, and a browser
 * would still announce the attempt.
 */
export function displayableCoverUrl(url) {
  if (typeof url !== "string") return null;

  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return null;
  }

  const allowed = parsed.protocol === "https:"
    && parsed.port === ""
    && parsed.username === ""
    && parsed.password === ""
    && ALLOWED_COVER_HOSTS.has(parsed.hostname);

  return allowed ? parsed.href : null;
}
