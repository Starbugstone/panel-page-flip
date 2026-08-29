/**
 * Stands in for the browser's image loader, which jsdom does not provide.
 *
 * Images settle on their own by default, because the reader keeps asking for
 * pages until they do — the cache entry it writes while a page is in flight is
 * itself state, so a page that never arrives re-runs the loading effect for as
 * long as the test is willing to wait. Tests choose the outcome per URL through
 * `policy` rather than by holding requests open.
 */
export class FakeImage {
  constructor() {
    this.onload = null;
    this.onerror = null;
    this._src = "";
    FakeImage.instances.push(this);
  }

  set src(value) {
    this._src = value;
    // A macrotask, so a test can change the policy up to the moment it renders.
    setTimeout(() => {
      const outcome = FakeImage.policy(value);
      if (outcome === "load") this.onload?.();
      if (outcome === "error") this.onerror?.();
      // "hold" leaves the request outstanding for the test to settle by hand.
    }, 0);
  }

  get src() {
    return this._src;
  }

  static reset() {
    FakeImage.instances = [];
    FakeImage.policy = () => "load";
  }

  /** Fail only the pages whose URL contains `fragment`; everything else loads. */
  static failing(fragment) {
    FakeImage.policy = (src) => (src.includes(fragment) ? "error" : "load");
  }

  /** The requested URLs, in order, for asserting what was fetched and when. */
  static requestedUrls() {
    return FakeImage.instances.map(({ src }) => src);
  }
}
FakeImage.reset();
