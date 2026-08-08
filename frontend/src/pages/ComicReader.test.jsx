import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import ComicReader from "./ComicReader";
import { api } from "@/lib/api";

/**
 * Stable identities, deliberately.
 *
 * The reader takes `toast` and `updateComicProgress` as effect dependencies. A
 * mock that returns a fresh vi.fn() per call hands back a new identity on every
 * render, which re-runs those effects forever — the component is fine, the mock
 * is the loop. Hoisted so the factories below can close over one instance each.
 */
const mocks = vi.hoisted(() => ({ toast: vi.fn(), updateComicProgress: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn(), log: vi.fn() } }));
vi.mock("@/lib/fullscreen", () => ({ toggleFullscreen: vi.fn() }));
vi.mock("@/hooks/use-comic-library.jsx", () => ({
  useComicLibrary: () => ({ updateComicProgress: mocks.updateComicProgress }),
}));

/**
 * Stands in for the browser's image loader, which jsdom does not provide.
 *
 * Images settle on their own by default, because the reader keeps asking for
 * pages until they do — the cache entry it writes while a page is in flight is
 * itself state, so a page that never arrives re-runs the loading effect for as
 * long as the test is willing to wait. Tests choose the outcome per URL through
 * `policy` rather than by holding requests open.
 */
class FakeImage {
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
}
FakeImage.reset();

const comic = (pageCount = 3) => ({
  comic: { id: 42, title: "Sandman", pageCount, readingProgress: null },
});

const renderReader = () => render(
  <MemoryRouter initialEntries={["/read/42"]}>
    <Routes>
      <Route path="/read/:comicId" element={<ComicReader />} />
    </Routes>
  </MemoryRouter>
);

const page = (n) => screen.findByAltText(new RegExp(`page ${n} of Sandman`, "i"));
const pageBox = () => screen.getByLabelText(/go to page/i);

describe("ComicReader", () => {
  beforeEach(() => {
    FakeImage.reset();
    mocks.toast.mockClear();
    mocks.updateComicProgress.mockClear();
    vi.stubGlobal("Image", FakeImage);
    vi.mocked(api.get).mockReset();
    vi.mocked(api.get).mockResolvedValue(comic());
    vi.mocked(api.post).mockResolvedValue({ progress: { currentPage: 1, revision: 1 } });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  describe("what the cache entry for a page decides", () => {
    it("shows the page once its image arrives", async () => {
      renderReader();

      expect(await page(1)).toBeInTheDocument();
      expect(screen.queryByText(/error loading page 1/i)).not.toBeInTheDocument();
    });

    it("offers a retry when the image fails, rather than waiting forever", async () => {
      FakeImage.failing("/pages/1");
      renderReader();

      expect(await screen.findByText(/error loading page 1/i)).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /retry/i })).toBeInTheDocument();
      expect(screen.queryByAltText(/page 1 of Sandman/i)).not.toBeInTheDocument();
    });

    it("shows the page when a retry succeeds", async () => {
      const user = userEvent.setup();
      FakeImage.failing("/pages/1");
      renderReader();
      await screen.findByText(/error loading page 1/i);

      FakeImage.policy = () => "load";
      await user.click(screen.getByRole("button", { name: /retry/i }));

      expect(await page(1)).toBeInTheDocument();
      expect(screen.queryByText(/error loading page 1/i)).not.toBeInTheDocument();
    });
  });

  describe("forcing a page to come from the server again", () => {
    /**
     * The reload deletes the cache entry and re-requests the page. What the
     * reader shows afterwards is now read entirely from that entry rather than
     * from loading flags kept beside it, so these two pin the outcome of both
     * paths: a failure has to surface as the retry panel, and a success as the
     * page, without anything having to remember to set a flag to match.
     */
    it("leaves a failed reload showing the failure, not an endless spinner", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      FakeImage.policy = () => "error";
      await user.click(screen.getByTitle(/reload/i));

      expect(await screen.findByText(/error loading page 1/i)).toBeInTheDocument();
    });

    it("shows the page again when the reload succeeds", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(screen.getByTitle(/reload/i));

      expect(await page(1)).toBeInTheDocument();
      expect(screen.queryByText(/error loading page 1/i)).not.toBeInTheDocument();
    });
  });

  describe("the jump-to-page box", () => {
    it("follows the page when it is turned by other means", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      expect(pageBox()).toHaveValue(1);

      await user.click(screen.getByLabelText(/next page/i));

      await waitFor(() => expect(pageBox()).toHaveValue(2));
    });

    it("keeps what is being typed while the page stays put", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.clear(pageBox());

      // An empty box is a half-typed number, not page zero, and has to survive
      // as typed until it is committed.
      expect(pageBox()).toHaveValue(null);
      expect(await page(1)).toBeInTheDocument();
    });

    it("goes to the typed page, clamped to the last one", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.clear(pageBox());
      await user.type(pageBox(), "500");
      await user.tab();

      // Echoes what it settled on rather than leaving a number that does not
      // match the page being shown.
      await waitFor(() => expect(pageBox()).toHaveValue(3));
      expect(await page(3)).toBeInTheDocument();
    });
  });

  describe("a comic that cannot be read", () => {
    it("says so rather than showing an empty reader", async () => {
      vi.mocked(api.get).mockRejectedValue(Object.assign(new Error("nope"), { status: 404 }));

      renderReader();

      expect(await screen.findByText(/not found|could not|problem/i)).toBeInTheDocument();
    });
  });
});
