/**
 * The routes no optional Google integration may touch, whatever an operator has
 * switched on.
 *
 * Google's AdSense requirement for the privacy-policy URL configured in Privacy
 * & Messaging is that the page host neither the Funding Choices consent-message
 * tag nor any other script requiring consent. That is a property of the page,
 * not of one provider's opinion about it — so it is one list, checked by
 * everything that could load Google, rather than three components each
 * remembering the same rule.
 *
 * `/terms` and `/cookies` join `/privacy` because they are the same kind of
 * document, the operator may point the account at any of them, and keeping all
 * three clean is simpler than keeping the distinction straight.
 *
 * The same set lives in `backend/config/frontend-routes.json` as `googleFree`,
 * which is what makes the CSP for these routes strict as well;
 * `google-free-routes.test.js` fails if the two drift apart.
 */
export const GOOGLE_FREE_ROUTES = Object.freeze(["/privacy", "/cookies", "/terms"]);

/** Exact matches only, on the same normalisation the other route checks use. */
export function isGoogleFreeRoute(pathname) {
  if (typeof pathname !== "string") return false;
  const [withoutQuery] = pathname.split(/[?#]/);
  const normalised = withoutQuery.length > 1 ? withoutQuery.replace(/\/+$/, "") : withoutQuery;

  return GOOGLE_FREE_ROUTES.includes(normalised.toLowerCase());
}
