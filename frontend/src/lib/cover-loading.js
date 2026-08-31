/**
 * How many cover requests the library allows itself to have in flight.
 *
 * Every cover is a full Symfony request — session, voter, file response — so a
 * grid of eighty comics scrolled quickly is eighty of those at once, and the
 * server answers the ones it cannot serve with an error the browser renders as
 * a broken image. The cap is what turns a burst into a queue; four keeps a
 * screenful arriving quickly without ever being the reason a request fails.
 */
export const COVER_REQUEST_LIMIT = 4;

/** The initial request plus three retries. Beyond that it is asking a broken thing to be less broken. */
export const COVER_MAX_ATTEMPTS = 4;

const RETRY_BASE_MS = 600;
const RETRY_CEILING_MS = 8000;

/**
 * How long to wait before asking for a cover that just failed.
 *
 * Jittered, because covers fail in bursts: a saturated server refuses a
 * screenful together, and a fixed backoff would have that whole screenful come
 * back together too — the same burst again, one delay later.
 */
export function coverRetryDelay(attempt, random = Math.random) {
  const backoff = Math.min(RETRY_BASE_MS * 2 ** Math.max(attempt, 0), RETRY_CEILING_MS);

  return Math.round(backoff * (0.5 + random() * 0.5));
}

/**
 * The URL to request for a given attempt.
 *
 * A retry has to be a different URL. The first attempt failed at the server,
 * and a browser that has anything at all for the original URL — a cached error
 * page, a truncated response — will hand that back rather than ask again.
 */
export function coverUrlForAttempt(url, attempt) {
  if (!url || attempt <= 0) return url;

  return `${url}${url.includes("?") ? "&" : "?"}retry=${attempt}`;
}

/**
 * Permission to have one request outstanding, handed out to at most `limit`
 * holders at a time.
 *
 * Deliberately not a request queue: it does not know what a cover is or how one
 * is fetched. The browser still does the fetching, with everything that gets us
 * — caching, decoding, `alt` text on failure. This only decides who is allowed
 * to start, which is the part the browser gets wrong when three hundred images
 * become eligible in the same frame.
 *
 * First in, first out. A card that leaves the viewport releases its ticket
 * before it is granted, so the queue drains towards what is actually on screen
 * rather than towards wherever the reader was scrolling a second ago.
 */
export function createRequestSlots({ limit = COVER_REQUEST_LIMIT } = {}) {
  const waiting = [];
  let active = 0;

  const pump = () => {
    while (active < limit && waiting.length > 0) {
      const ticket = waiting.shift();
      ticket.state = "granted";
      active += 1;
      ticket.settle(true);
    }
  };

  // Idempotent: a card releases on unmount as well as when its image settles,
  // and a release that freed a slot twice would raise the limit for everybody.
  const release = (ticket) => {
    if (ticket.state === "granted") {
      ticket.state = "released";
      active -= 1;
      pump();
      return;
    }

    if (ticket.state === "waiting") {
      ticket.state = "released";
      waiting.splice(waiting.indexOf(ticket), 1);
      ticket.settle(false);
    }
  };

  return {
    /**
     * @returns {{granted: Promise<boolean>, release: () => void}} `granted`
     * resolves true when the holder may start, and false when the ticket was
     * released before its turn came.
     */
    acquire() {
      const ticket = { state: "waiting", settle: () => {} };
      ticket.granted = new Promise((resolve) => { ticket.settle = resolve; });
      waiting.push(ticket);
      pump();

      return { granted: ticket.granted, release: () => release(ticket) };
    },
    get activeCount() { return active; },
    get waitingCount() { return waiting.length; },
  };
}

/** One cap for the whole application, since one server is what everything is sharing. */
export const coverSlots = createRequestSlots();
