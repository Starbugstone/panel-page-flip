import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import ComicReader from "./ComicReader";
import { api } from "@/lib/api";
import { toggleFullscreen } from "@/lib/fullscreen";
import { DEFAULT_READER_PREFERENCES } from "@/lib/reader-preferences";
import { FakeImage } from "@/test/fake-image";

/**
 * Stable identities, deliberately.
 *
 * The reader takes `toast` and `updateComicProgress` as effect dependencies. A
 * mock that returns a fresh vi.fn() per call hands back a new identity on every
 * render, which re-runs those effects forever — the component is fine, the mock
 * is the loop. Hoisted so the factories below can close over one instance each.
 */
const mocks = vi.hoisted(() => ({
  catalog: [],
  toast: vi.fn(),
  updateComicProgress: vi.fn(),
}));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn(), log: vi.fn() } }));
vi.mock("@/lib/fullscreen", () => ({ toggleFullscreen: vi.fn() }));
vi.mock("@/hooks/use-comic-library.jsx", () => ({
  useComicLibrary: () => ({ updateComicProgress: mocks.updateComicProgress }),
}));


const comic = (pageCount = 3) => ({
  comic: { id: 42, title: "Sandman", pageCount, readingProgress: null },
});

const savedPreferences = (patch) => ({
  ...DEFAULT_READER_PREFERENCES,
  settings: { ...DEFAULT_READER_PREFERENCES.settings, ...patch },
});

/**
 * An account that has chosen paged reading.
 *
 * The shipped default is continuous scroll, so most of this file has to ask for
 * pages rather than assume them — a test about page turns that silently stopped
 * rendering a paged reader would pass for the wrong reason.
 */
const PAGED_PREFERENCES = savedPreferences({ mode: "single" });

function ReaderRoute() {
  const location = useLocation();
  return (
    <>
      <span data-testid="reader-return-location">{location.state?.libraryReturnTo || "none"}</span>
      <ComicReader />
    </>
  );
}

const renderReader = (initialEntry = "/read/42") => render(
  <MemoryRouter initialEntries={[initialEntry]}>
    <Routes>
      <Route path="/read/:comicId" element={<ReaderRoute />} />
    </Routes>
  </MemoryRouter>
);

const page = (n) => screen.findByAltText(new RegExp(`page ${n} of Sandman`, "i"));
// Exactly "Go to page": the thumbnail strip's buttons are labelled "Go to page
// 3" and a loose match would find them too.
const pageBox = () => screen.getByLabelText("Go to page");


/**
 * A touchscreen, as far as the reader can tell: a coarse pointer, no hover, and
 * a viewport of the shape being tested. jsdom lays nothing out, so the page
 * surface is given a width by hand — tap zones are fractions of it.
 */
function useScreen({ width, height, coarsePointer = true, touchCapable = coarsePointer }) {
  window.innerWidth = width;
  window.innerHeight = height;
  window.matchMedia = (query) => ({
    matches: query === "(pointer: coarse)"
      ? coarsePointer
      : query === "(any-pointer: coarse)" ? touchCapable : !coarsePointer,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  });
}

const surface = () => document.querySelector("[data-page-fit]");

function measuredSurface(width = 400) {
  const element = surface();
  Object.defineProperty(element, "clientWidth", { value: width, configurable: true });
  return element;
}

function measureZoomGeometry({ width = 400, height = 400, contentWidth = 400, contentHeight = 800 } = {}) {
  const element = measuredSurface(width);
  Object.defineProperty(element, "clientHeight", { value: height, configurable: true });
  const artwork = element.querySelector('[data-reader-artwork="true"]');
  Object.defineProperty(artwork, "offsetWidth", { value: contentWidth, configurable: true });
  Object.defineProperty(artwork, "offsetHeight", { value: contentHeight, configurable: true });
  return { element, artwork };
}

function touch(type, { id = 1, x = 0, y = 0, time = 0 } = {}) {
  const event = new Event(type, { bubbles: true });
  Object.assign(event, { pointerId: id, clientX: x, clientY: y, pointerType: "touch" });
  Object.defineProperty(event, "timeStamp", { value: time });
  return event;
}

const gesture = (element, ...events) => act(() => {
  events.forEach((event) => element.dispatchEvent(event));
});

const pageControls = () => screen.getByRole("group", { name: "Reader page controls" });

describe("ComicReader", () => {
  beforeEach(() => {
    FakeImage.reset();
    mocks.toast.mockClear();
    mocks.catalog = [{ id: 42, title: "Sandman" }];
    mocks.updateComicProgress.mockClear();
    vi.stubGlobal("Image", FakeImage);
    vi.mocked(api.get).mockReset();
    vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
      path === "/api/reader/preferences"
        ? { preferences: PAGED_PREFERENCES }
        : path === "/api/comics" ? { comics: mocks.catalog } : comic()
    ));
    vi.mocked(api.post).mockResolvedValue({ progress: { currentPage: 1, revision: 1 } });
    vi.mocked(api.put).mockReset();
    vi.mocked(api.put).mockImplementation((_path, body) => Promise.resolve(body));
    vi.mocked(api.delete).mockReset();
    vi.mocked(api.delete).mockResolvedValue({ preferences: DEFAULT_READER_PREFERENCES });
    vi.mocked(toggleFullscreen).mockClear();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    delete window.matchMedia;
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

      await user.click(screen.getByRole("button", { name: /^next/i }));

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

    it("has an explicit Go action for a typed page", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.clear(pageBox());
      await user.type(pageBox(), "3");
      await user.click(screen.getByRole("button", { name: "Go to typed page" }));

      expect(await page(3)).toBeInTheDocument();
      expect(pageBox()).toHaveValue(3);
    });
  });

  describe("shared navigation and logical progress", () => {
    it("opens the next comic alphabetically from the final page", async () => {
      mocks.catalog = [
        { id: 7, title: "Zeta", coverImagePath: "/api/comics/cover/1/7/zeta.jpg" },
        { id: 42, title: "Sandman" },
        { id: 3, title: "Alpha" },
      ];
      const user = userEvent.setup();
      renderReader({
        pathname: "/read/42",
        state: { libraryReturnTo: "/dashboard?view=reading" },
      });
      await page(1);

      await user.click(screen.getByRole("button", { name: "Next page" }));
      await user.click(screen.getByRole("button", { name: "Next page" }));

      const nextComic = screen.getByRole("button", { name: "Read next comic: Zeta" });
      expect(nextComic).toBeEnabled();
      expect(nextComic).toHaveTextContent("Next comic");
      expect(nextComic).toHaveTextContent("Zeta");
      expect(nextComic.querySelector("img")).toHaveAttribute("src", "/api/comics/cover/1/7/zeta.jpg");
      await user.click(nextComic);

      await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/7"));
      expect(screen.getByTestId("reader-return-location")).toHaveTextContent("/dashboard?view=reading");
    });

    it("loads the full library for ranking even when the reader was opened directly", async () => {
      renderReader();

      await page(1);
      await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics"));
    });

    it("uses the same next and previous operations for keyboard navigation", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.keyboard("{ArrowRight}");
      expect(await page(2)).toBeInTheDocument();

      await user.keyboard("{ArrowLeft}");
      expect(await page(1)).toBeInTheDocument();

      expect(screen.getByRole("button", { name: "Previous page" })).toHaveAttribute("aria-keyshortcuts", "ArrowLeft");
      expect(screen.getByRole("button", { name: "Next page" })).toHaveAttribute("aria-keyshortcuts", "ArrowRight");
    });

    it("does not steal arrow keys from the page input", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(pageBox());
      await user.keyboard("{ArrowRight}");

      expect(await page(1)).toBeInTheDocument();
    });

    it("restores and saves the underlying comic page number", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences"
          ? { preferences: PAGED_PREFERENCES }
          : { comic: { ...comic().comic, readingProgress: { currentPage: 2, revision: 7 } } }
      ));

      renderReader();

      expect(await page(2)).toBeInTheDocument();
      await waitFor(() => expect(api.post).toHaveBeenCalledWith(
        "/api/comics/42/progress",
        expect.objectContaining({ currentPage: 2, revision: 8 }),
        expect.objectContaining({ keepalive: true })
      ));
    });
  });

  describe("reader settings", () => {
    it("loads a saved fit without reloading comic metadata", async () => {
      const saved = savedPreferences({ mode: "single", fit: "width" });
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: saved } : comic()
      ));

      const { container } = renderReader();
      await page(1);

      await waitFor(() => expect(container.querySelector("[data-page-fit]")).toHaveAttribute("data-page-fit", "width"));
      expect(api.get.mock.calls.filter(([path]) => path === "/api/comics/42")).toHaveLength(1);
    });

    it("uses the zoom slider to widen pages in continuous mode", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences"
          ? { preferences: savedPreferences({ mode: "continuous" }) }
          : comic()
      ));
      const user = userEvent.setup();
      renderReader();
      await waitFor(() => expect(document.querySelector('[data-reader-mode="continuous"]')).toBeInTheDocument());

      await user.click(screen.getByRole("button", { name: "Reader settings" }));
      const slider = screen.getByRole("slider", { name: "Zoom level" });
      expect(slider).toBeEnabled();
      fireEvent.change(slider, { target: { value: "175" } });

      await waitFor(() => expect(document.querySelector('[data-reader-mode="continuous"]')).toHaveAttribute("data-continuous-zoom", "1.75"));
      // CSS derives every page's width from this one property on the scroller.
      expect(document.querySelector('[data-reader-mode="continuous"]').style.getPropertyValue("--reader-page-zoom")).toBe("1.75");
    });

    it("widens continuous pages without throwing away where the reader was", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences"
          ? { preferences: savedPreferences({ mode: "continuous" }) }
          : comic(12)
      ));
      const user = userEvent.setup();
      renderReader();
      await waitFor(() => expect(document.querySelector('[data-reader-mode="continuous"]')).toBeInTheDocument());

      const scroller = document.querySelector('[data-reader-mode="continuous"]');
      scroller.scrollTop = 4000;

      await user.click(screen.getByRole("button", { name: "Reader settings" }));
      fireEvent.change(screen.getByRole("slider", { name: "Zoom level" }), { target: { value: "175" } });
      await waitFor(() => expect(scroller).toHaveAttribute("data-continuous-zoom", "1.75"));

      expect(scroller.scrollTop).toBe(4000);
    });

    it("changes fit immediately, persists it, and resets naturally", async () => {
      const user = userEvent.setup();
      const { container } = renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /reader settings/i }));
      await user.click(screen.getByRole("combobox", { name: /page size/i }));
      await user.click(await screen.findByRole("option", { name: /fit width/i }));

      expect(container.querySelector("[data-page-fit]")).toHaveAttribute("data-page-fit", "width");
      await waitFor(() => expect(api.put).toHaveBeenCalledWith(
        "/api/reader/preferences",
        { preferences: expect.objectContaining({ settings: expect.objectContaining({ fit: "width" }) }) },
        { keepalive: true }
      ));
      expect(api.get.mock.calls.filter(([path]) => path === "/api/comics/42")).toHaveLength(1);

      await user.click(screen.getByRole("button", { name: /reset defaults/i }));
      await waitFor(() => expect(api.delete).toHaveBeenCalledWith(
        "/api/reader/preferences",
        { keepalive: true }
      ));
      // Reset means the shipped defaults, which read continuously — so the
      // paged surface the fit was chosen on goes back with the fit.
      await waitFor(() => expect(container.querySelector("[data-reader-mode='continuous']")).toBeInTheDocument());
    });

    it("falls back safely when saved settings are stale", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences"
          ? { preferences: { schemaVersion: 99, settings: { fit: "stretch" } } }
          : comic()
      ));

      const { container } = renderReader();
      await page(1);

      // A schema this build does not understand is discarded whole, so every
      // setting comes from the defaults rather than the recognisable half of a
      // stale envelope being kept.
      expect(container.querySelector("[data-reader-mode='continuous']")).toBeInTheDocument();
    });

    it("reads continuously until an account says otherwise", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: DEFAULT_READER_PREFERENCES } : comic()
      ));

      const { container } = renderReader();

      await screen.findByAltText(/page 1 of Sandman/i);
      expect(container.querySelector("[data-reader-mode='continuous']")).toBeInTheDocument();
      expect(container.querySelector("[data-page-fit]")).toBeNull();
    });

    it("can hide the progress indicator without affecting navigation", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      expect(screen.getByRole("progressbar")).toBeInTheDocument();

      await user.click(screen.getByRole("button", { name: /reader settings/i }));
      await user.click(screen.getByRole("switch", { name: /show progress bar/i }));

      expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
      expect(screen.getByRole("button", { name: /^next/i })).toBeEnabled();
    });

    it("cannot be changed before the saved values arrive", async () => {
      const user = userEvent.setup();
      let resolvePreferences;
      vi.mocked(api.get).mockImplementation((path) => (
        path === "/api/reader/preferences"
          ? new Promise((resolve) => { resolvePreferences = resolve; })
          : Promise.resolve(comic())
      ));

      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /reader settings/i }));
      const wakeLock = screen.getByRole("switch", { name: /keep screen awake/i });
      expect(wakeLock).toBeDisabled();

      // Toggling here would send the placeholder defaults as the user's whole
      // preference set, wiping whatever the pending request is about to return.
      await user.click(wakeLock);
      expect(api.put).not.toHaveBeenCalled();

      resolvePreferences({
        preferences: savedPreferences({ mode: "single", wakeLock: false }),
      });

      await waitFor(() => expect(screen.getByRole("switch", { name: /keep screen awake/i })).toBeEnabled());
      expect(screen.getByRole("switch", { name: /keep screen awake/i })).not.toBeChecked();
      expect(api.put).not.toHaveBeenCalled();
    });
  });

  describe("fullscreen and preload regressions", () => {
    it("keeps fullscreen available through an explicitly named button", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /enter fullscreen/i }));

      expect(toggleFullscreen).toHaveBeenCalledWith(document);
    });

    it("does not request the same preload URL twice", async () => {
      renderReader();
      await page(1);

      await waitFor(() => expect(FakeImage.instances.length).toBeGreaterThanOrEqual(3));
      const pageUrls = FakeImage.instances.map(({ src }) => src).filter((src) => src.includes("/pages/"));
      expect(new Set(pageUrls).size).toBe(pageUrls.length);
    });
  });

  describe("how much page it asks for", () => {
    const pageRequests = () => FakeImage.instances
      .map(({ src }) => src)
      .filter((src) => src.includes("/pages/") && !src.includes("variant=thumb"));

    it("asks for a bounded size rather than the source scan", async () => {
      renderReader();
      await page(1);

      pageRequests().forEach((src) => {
        expect(src).toContain("variant=reader-");
        expect(src).not.toContain("variant=original");
      });
    });

    it("moves up a size when the reader zooms in", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      expect(pageRequests().every((src) => src.includes("variant=reader-medium"))).toBe(true);

      await user.click(screen.getByRole("button", { name: /zoom in/i }));

      await waitFor(() => expect(pageRequests().some((src) => src.includes("variant=reader-large"))).toBe(true));
    });

    it("keeps showing the page it has while a larger one is on its way", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      // Nothing settles from here on, so the only image available is the one
      // already on screen: a zoom must not blank the reader back to a skeleton.
      FakeImage.policy = () => "hold";
      await user.click(screen.getByRole("button", { name: /zoom in/i }));

      expect(await page(1)).toBeInTheDocument();
    });

    it("upgrades only the visible artwork when zooming", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /zoom in/i }));
      await waitFor(() => expect(pageRequests().some((src) => src.includes("reader-large"))).toBe(true));

      const upgradedPages = pageRequests()
        .filter((src) => src.includes("reader-large"))
        .map((src) => src.match(/\/pages\/(\d+)/)?.[1]);
      expect(new Set(upgradedPages)).toEqual(new Set(["1"]));
    });
  });

  describe("facing pages and reading direction", () => {
    beforeEach(() => useScreen({ width: 1180, height: 820 }));

    it("keeps the cover alone, then advances by a real two-page spread", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: savedPreferences({ mode: "double" }) } : comic(5)
      ));
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /^next/i }));

      expect(await page(2)).toBeInTheDocument();
      expect(await page(3)).toBeInTheDocument();
      expect(screen.getByText("Showing pages 2–3")).toBeInTheDocument();
      expect(screen.getByRole("progressbar")).toHaveAccessibleName("Pages 2–3 of 5");
    });

    it("offers visible page-turn zones in two-page mode", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: savedPreferences({ mode: "double" }) } : comic(5)
      ));
      const user = userEvent.setup();
      renderReader();
      await page(1);

      expect(screen.getByRole("button", { name: "Left edge: previous page" })).toBeDisabled();
      await user.click(screen.getByRole("button", { name: "Right edge: next page" }));

      expect(await page(2)).toBeInTheDocument();
      expect(await page(3)).toBeInTheDocument();
    });

    it("keeps buttons, arrow keys, and manual jumps on the same spread navigation model", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: savedPreferences({ mode: "double" }) } : comic(7)
      ));
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: "Next page" }));
      expect(await page(2)).toBeInTheDocument();
      expect(await page(3)).toBeInTheDocument();

      await user.keyboard("{ArrowRight}");
      expect(await page(4)).toBeInTheDocument();
      expect(await page(5)).toBeInTheDocument();

      await user.keyboard("{ArrowLeft}");
      expect(await page(2)).toBeInTheDocument();
      expect(await page(3)).toBeInTheDocument();

      await user.clear(pageBox());
      await user.type(pageBox(), "6");
      await user.click(screen.getByRole("button", { name: "Go to typed page" }));
      expect(await page(6)).toBeInTheDocument();
      expect(await page(7)).toBeInTheDocument();
      expect(pageBox()).toHaveValue(6);
    });

    it("restores the second logical page of a pair without moving progress", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences"
          ? { preferences: savedPreferences({ mode: "double" }) }
          : { comic: { ...comic(5).comic, readingProgress: { currentPage: 3, revision: 4 } } }
      ));
      renderReader();

      expect(await page(2)).toBeInTheDocument();
      expect(await page(3)).toBeInTheDocument();
      expect(pageBox()).toHaveValue(3);
      await waitFor(() => expect(api.post).toHaveBeenCalledWith(
        "/api/comics/42/progress",
        expect.objectContaining({ currentPage: 3 }),
        expect.anything()
      ));
    });

    it("reverses visual placement for RTL without changing source page numbers", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences"
          ? { preferences: savedPreferences({ mode: "double", direction: "rtl" }) }
          : comic(5)
      ));
      const user = userEvent.setup();
      renderReader();
      await page(1);
      await user.click(screen.getByRole("button", { name: /^next/i }));
      await page(3);

      const displayed = [...surface().querySelectorAll("img")].map((image) => image.alt);
      expect(displayed).toEqual(["Page 3 of Sandman", "Page 2 of Sandman"]);
      expect(pageBox()).toHaveValue(2);
    });

    it("shows a failed side inline without hiding the readable side", async () => {
      FakeImage.failing("/pages/3");
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: savedPreferences({ mode: "double" }) } : comic(5)
      ));
      const user = userEvent.setup();
      renderReader();
      await page(1);
      await user.click(screen.getByRole("button", { name: /^next/i }));

      expect(await page(2)).toBeInTheDocument();
      expect(await screen.findByText(/error loading page 3/i)).toBeInTheDocument();
    });

    it("leaves detected wide scans alone and resumes pairing afterwards", async () => {
      vi.mocked(api.get).mockImplementation((path) => {
        if (path === "/api/reader/preferences") return Promise.resolve({ preferences: savedPreferences({ mode: "double" }) });
        if (path.includes("/pages?")) return Promise.resolve({
          complete: true,
          pages: Array.from({ length: 5 }, (_, index) => ({
            page: index + 1,
            width: index === 1 ? 1800 : 800,
            height: 1200,
            aspectRatio: index === 1 ? 1.5 : 2 / 3,
          })),
        });
        return Promise.resolve(comic(5));
      });
      const user = userEvent.setup();
      renderReader();
      await page(1);
      await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/42/pages?from=1"));
      await user.click(screen.getByRole("button", { name: /^next/i }));

      expect(await page(2)).toBeInTheDocument();
      expect(screen.queryByAltText(/page 3/i)).not.toBeInTheDocument();
    });
  });

  describe("continuous reading", () => {
    beforeEach(() => useScreen({ width: 390, height: 844 }));

    it("loads only pages near the viewport and keeps far-page geometry stable", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: savedPreferences({ mode: "continuous" }) } : comic(40)
      ));
      renderReader();
      await screen.findByAltText(/page 1 of Sandman/i);

      const reader = document.querySelector("[data-reader-mode='continuous']");
      expect(reader.querySelectorAll("img").length).toBeLessThanOrEqual(3);
      expect(reader.querySelector("[data-continuous-page='39'] img")).toBeNull();
      expect(reader.querySelector("[data-continuous-page='39']")).toHaveTextContent("Page 40");
    });

    it("jumps to a logical page without enabling paged swipe navigation", async () => {
      const scrollIntoView = vi.fn();
      HTMLElement.prototype.scrollIntoView = scrollIntoView;
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: savedPreferences({ mode: "continuous" }) } : comic(12)
      ));
      const user = userEvent.setup();
      renderReader();
      await screen.findByAltText(/page 1 of Sandman/i);

      await user.clear(pageBox());
      await user.type(pageBox(), "8");
      await user.tab();
      await waitFor(() => expect(pageBox()).toHaveValue(8));
      expect(await screen.findByAltText(/page 8 of Sandman/i)).toBeInTheDocument();
      expect(scrollIntoView).toHaveBeenCalled();

      const reader = document.querySelector("[data-reader-mode='continuous']");
      gesture(reader,
        touch("pointerdown", { x: 320, y: 400, time: 0 }),
        touch("pointermove", { x: 200, y: 402, time: 40 }),
        touch("pointerup", { x: 80, y: 404, time: 90 }));
      expect(pageBox()).toHaveValue(8);
    });
  });

  describe("slow requested pages", () => {
    it("keeps the previous artwork visible while the next page is arriving", async () => {
      FakeImage.policy = (src) => src.includes("/pages/1") ? "load" : "hold";
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /^next/i }));

      await screen.findByText("Loading page 2…");
      expect(surface().querySelector("img").src).toContain("/pages/1");
      expect(pageBox()).toHaveValue(2);
    });

    it("stops showing stale artwork once the requested page has failed", async () => {
      FakeImage.policy = (src) => src.includes("/pages/1") ? "load" : "error";
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /^next/i }));

      expect(await screen.findByText(/error loading page 2/i)).toBeInTheDocument();
      expect(surface().querySelector("img")).toBeNull();
    });
  });

  describe("the thumbnail navigator", () => {
    it("stays out of the way until it is asked for", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      expect(screen.queryByRole("group", { name: /page thumbnails/i })).not.toBeInTheDocument();

      await user.click(screen.getByRole("button", { name: /show page thumbnails/i }));

      expect(screen.getByRole("group", { name: /page thumbnails/i })).toBeInTheDocument();
    });

    it("turns to the page whose thumbnail was clicked", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /show page thumbnails/i }));
      await user.click(screen.getByRole("button", { name: "Go to page 3" }));

      expect(await page(3)).toBeInTheDocument();
      expect(pageBox()).toHaveValue(3);
    });
  });

  describe("page geometry", () => {
    it("asks the server what shape the pages are, without downloading them", async () => {
      renderReader();
      await page(1);

      await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/42/pages?from=1"));
      expect(FakeImage.instances.map(({ src }) => src)).not.toContain("/api/comics/42/pages?from=1");
    });

    it("reads perfectly well when the server cannot say", async () => {
      vi.mocked(api.get).mockImplementation((path) => {
        if (path === "/api/reader/preferences") return Promise.resolve({ preferences: PAGED_PREFERENCES });
        if (path.includes("/pages?")) return Promise.reject(new Error("no manifest here"));
        return Promise.resolve(comic());
      });

      renderReader();

      expect(await page(1)).toBeInTheDocument();
    });
  });


  describe("reading with a finger", () => {
    beforeEach(() => useScreen({ width: 390, height: 844 }));

    it("turns the page on a swipe", async () => {
      renderReader();
      await page(1);
      const surfaceElement = measuredSurface();

      gesture(surfaceElement,
        touch("pointerdown", { x: 320, y: 400, time: 0 }),
        touch("pointermove", { x: 240, y: 402, time: 40 }),
        touch("pointerup", { x: 160, y: 404, time: 90 }));

      expect(await page(2)).toBeInTheDocument();
    });

    it("goes back when the swipe goes the other way", async () => {
      renderReader();
      await page(1);
      const surfaceElement = measuredSurface();

      gesture(surfaceElement,
        touch("pointerdown", { x: 300, y: 400, time: 0 }),
        touch("pointermove", { x: 200, y: 402, time: 40 }),
        touch("pointerup", { x: 80, y: 404, time: 90 }));
      await page(2);

      gesture(surfaceElement,
        touch("pointerdown", { x: 80, y: 400, time: 200 }),
        touch("pointermove", { x: 200, y: 402, time: 240 }),
        touch("pointerup", { x: 300, y: 404, time: 290 }));

      expect(await page(1)).toBeInTheDocument();
    });

    it("pans a zoomed page instead of turning it", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      await user.click(screen.getByRole("button", { name: /zoom in/i }));
      const surfaceElement = measuredSurface();

      gesture(surfaceElement,
        touch("pointerdown", { x: 320, y: 400, time: 0 }),
        touch("pointermove", { x: 240, y: 402, time: 40 }),
        touch("pointerup", { x: 160, y: 404, time: 90 }));

      expect(await page(1)).toBeInTheDocument();
      expect(pageBox()).toHaveValue(1);
    });

    it("never changes page from an edge tap while zoomed", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      await user.click(screen.getByRole("button", { name: /zoom in/i }));
      const surfaceElement = measuredSurface();

      gesture(surfaceElement,
        touch("pointerdown", { x: 380, y: 400, time: 0 }),
        touch("pointerup", { x: 380, y: 400, time: 40 }));
      await act(() => new Promise((resolve) => setTimeout(resolve, 350)));

      expect(pageBox()).toHaveValue(1);
      expect(await page(1)).toBeInTheDocument();
    });

    it("reverses physical tap navigation in right-to-left mode", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: savedPreferences({ mode: "single", direction: "rtl" }) } : comic()
      ));
      renderReader();
      await page(1);
      const surfaceElement = measuredSurface();

      gesture(surfaceElement,
        touch("pointerdown", { x: 10, y: 400, time: 0 }),
        touch("pointerup", { x: 10, y: 400, time: 40 }));
      await act(() => new Promise((resolve) => setTimeout(resolve, 350)));

      expect(await page(2)).toBeInTheDocument();
    });

    it("turns the page from the tap zone at the edge", async () => {
      renderReader();
      await page(1);
      const surfaceElement = measuredSurface();

      gesture(surfaceElement,
        touch("pointerdown", { x: 380, y: 400, time: 0 }),
        touch("pointerup", { x: 380, y: 400, time: 40 }));
      // The tap is held back until it is clear that no second tap is coming.
      await act(() => new Promise((resolve) => setTimeout(resolve, 350)));

      expect(await page(2)).toBeInTheDocument();
    });

    it("gives the screen to the artwork when the middle is tapped", async () => {
      renderReader();
      await page(1);
      const surfaceElement = measuredSurface();
      expect(pageControls()).not.toHaveClass("reader-chrome-hidden");

      gesture(surfaceElement,
        touch("pointerdown", { x: 200, y: 400, time: 0 }),
        touch("pointerup", { x: 200, y: 400, time: 40 }));
      await act(() => new Promise((resolve) => setTimeout(resolve, 350)));

      expect(pageControls()).toHaveClass("reader-chrome-hidden");
    });

    /**
     * Reported from real reading: while zoomed in, any stray click threw the
     * zoom away. Dragging was already exempt — the pan hook swallows the click
     * after 4px of movement — so it was the clicks *between* drags, made to
     * follow a panel, that cost the reader the view they had set up.
     *
     * What a zoomed click now does instead is toggle the chrome, which is what
     * a tap has always done. That it no longer *leaves* the zoom is pinned on
     * mouseClickAction directly, in reader-gestures.test.js: jsdom lays nothing
     * out, so zoomToFit has no geometry to work from and the transform cannot
     * be observed changing here either way.
     *
     * Clicks go in as native events rather than through userEvent: the pan hook
     * calls preventDefault on pointerdown to stop the browser starting its own
     * image drag, and userEvent honours that by withholding the click the real
     * browser still sends.
     */
    it("toggles the controls from a single click on a zoomed page", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      measureZoomGeometry();
      await user.click(screen.getByRole("button", { name: /zoom in/i }));
      expect(pageControls()).not.toHaveClass("reader-chrome-hidden");

      fireEvent.click(surface(), { clientX: 200, clientY: 400 });

      expect(pageControls()).toHaveClass("reader-chrome-hidden");
      expect(surface()).toHaveAttribute("data-page-zoomed", "true");
    });

    /**
     * A double click arrives as click, click, dblclick — so the controls are
     * toggled twice and end up exactly where they started, leaving the double
     * click to mean only "zoom out". This pins that netting-out: a change that
     * made one of the two clicks stop toggling would leave the reader's
     * controls flipped every time they zoomed back out.
     */
    it("does not toggle the controls when the click turns out to be a double click", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      measureZoomGeometry();
      await user.click(screen.getByRole("button", { name: /zoom in/i }));
      expect(pageControls()).not.toHaveClass("reader-chrome-hidden");

      const surfaceElement = surface();
      fireEvent.click(surfaceElement, { clientX: 200, clientY: 400 });
      fireEvent.click(surfaceElement, { clientX: 200, clientY: 400 });
      fireEvent.dblClick(surfaceElement, { clientX: 200, clientY: 400 });

      expect(pageControls()).not.toHaveClass("reader-chrome-hidden");
    });

    /** A fitted page has no double-click meaning, so its clicks must not be delayed. */
    it("still turns the page immediately from a mouse click when not zoomed", async () => {
      renderReader();
      await page(1);
      const surfaceElement = measuredSurface();
      Object.defineProperty(surfaceElement, "getBoundingClientRect", {
        value: () => ({ left: 0, top: 0, width: 400, height: 400 }),
        configurable: true,
      });

      fireEvent.click(surfaceElement, { clientX: 390, clientY: 200 });

      expect(await page(2)).toBeInTheDocument();
    });

    it("releases a pointer-focused control when the reader returns to the artwork", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      const zoom = screen.getByRole("button", { name: /zoom in/i });
      await user.click(zoom);
      expect(zoom).toHaveFocus();

      const surfaceElement = measuredSurface();
      gesture(surfaceElement, touch("pointerdown", { x: 200, y: 400, time: 0 }));

      expect(zoom).not.toHaveFocus();
      gesture(surfaceElement, touch("pointercancel", { x: 200, y: 400, time: 10 }));
    });

    it("offers an explicit mouse page zone without changing touch tap handling", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);

      expect(screen.getByRole("button", { name: "Left edge: previous page" })).toBeDisabled();
      await user.click(screen.getByRole("button", { name: "Right edge: next page" }));

      expect(await page(2)).toBeInTheDocument();
    });

    it("keeps the selected zoom and page-turn gutters when changing pages", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      measureZoomGeometry();

      await user.click(screen.getByRole("button", { name: "Reader settings" }));
      fireEvent.change(screen.getByRole("slider", { name: "Zoom level" }), { target: { value: "175" } });
      expect(surface()).toHaveAttribute("data-page-zoomed", "true");

      const nextZone = screen.getByRole("button", { name: "Right edge: next page" });
      expect(nextZone).toBeVisible();
      await user.click(nextZone);

      const nextArtwork = await page(2);
      expect(surface()).toHaveAttribute("data-page-zoomed", "true");
      expect(nextArtwork.style.transform).toContain("scale(1.75)");
      // 800 tall at 1.75x in a 400 viewport: the top of the page, not the middle.
      expect(nextArtwork.style.transform).toContain("translate3d(0px, 500px, 0)");
      expect(screen.getByRole("button", { name: "Right edge: next page" })).toBeVisible();
    });

    it("uses the wheel to move vertically without changing the zoom", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      const { element, artwork } = measureZoomGeometry();

      await user.click(screen.getByRole("button", { name: /zoom in/i }));
      const beforeWheel = artwork.style.transform;
      fireEvent.wheel(element, { deltaY: 120 });

      expect(beforeWheel).toContain("translate3d(0px, 400px, 0)");
      expect(artwork.style.transform).toContain("translate3d(0px, 280px, 0)");
      expect(artwork.style.transform).toContain("scale(2)");
    });

    it("restores the zoom level selected in settings from the toolbar button", async () => {
      const user = userEvent.setup();
      renderReader();
      await page(1);
      measureZoomGeometry();

      await user.click(screen.getByRole("button", { name: "Reader settings" }));
      fireEvent.change(screen.getByRole("slider", { name: "Zoom level" }), { target: { value: "275" } });
      await user.click(screen.getByRole("button", { name: /zoom out/i }));
      await user.click(screen.getByRole("button", { name: /zoom in/i }));

      expect((await page(1)).style.transform).toContain("scale(2.75)");
    });

    it("keeps page-turn buttons in gutters outside the artwork surface", async () => {
      renderReader();
      await page(1);

      const frame = document.querySelector("[data-reader-turn-zones]");
      expect(frame).toContainElement(surface());
      expect(surface()).not.toContainElement(screen.getByRole("button", { name: "Left edge: previous page" }));
      expect(surface()).not.toContainElement(screen.getByRole("button", { name: "Right edge: next page" }));
    });

    it("releases the normal desktop width cap while zoomed", async () => {
      const user = userEvent.setup();
      useScreen({ width: 1440, height: 900, coarsePointer: false });
      renderReader();
      await page(1);

      expect(surface().closest(".reader-stage")).toHaveClass("max-w-4xl");
      await user.click(screen.getByRole("button", { name: /zoom in/i }));

      expect(surface().closest(".reader-stage")).toHaveClass("max-w-none");
      expect(surface().closest(".reader-stage")).not.toHaveClass("max-w-4xl");
    });
  });

  describe("turning the device", () => {
    it("keeps the page that was being read", async () => {
      const user = userEvent.setup();
      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /^next/i }));
      await page(2);

      useScreen({ width: 844, height: 390 });
      act(() => { window.dispatchEvent(new Event("resize")); });

      expect(await page(2)).toBeInTheDocument();
      expect(pageBox()).toHaveValue(2);
    });
  });

  describe("hybrid input", () => {
    it("keeps touch gestures and comfortable controls when the primary pointer is a mouse", async () => {
      useScreen({ width: 1280, height: 800, coarsePointer: false, touchCapable: true });
      renderReader();
      await page(1);

      expect(document.querySelector(".reader-root")).toHaveAttribute("data-touch-capable", "true");
      const surfaceElement = measuredSurface(1280);
      gesture(surfaceElement,
        touch("pointerdown", { x: 1100, y: 400, time: 0 }),
        touch("pointermove", { x: 850, y: 402, time: 40 }),
        touch("pointerup", { x: 600, y: 404, time: 90 }));

      expect(await page(2)).toBeInTheDocument();
    });
  });

  describe("turning the device, continued", () => {
    it("drops a zoom that was framed against the viewport before the turn", async () => {
      const user = userEvent.setup();
      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);

      await user.click(screen.getByRole("button", { name: /zoom in/i }));
      expect(surface()).toHaveAttribute("data-page-zoomed", "true");

      useScreen({ width: 844, height: 390 });
      act(() => { window.dispatchEvent(new Event("resize")); });

      expect(surface()).toHaveAttribute("data-page-zoomed", "false");
    });
  });

  describe("suggesting a fit rather than imposing one", () => {
    it("offers fit width on a phone held upright", async () => {
      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);

      expect(await screen.findByRole("status")).toHaveTextContent(/fit width/i);
      expect(screen.getByRole("status")).toHaveTextContent(/this phone in portrait/i);
    });

    it("says nothing to a reader who has already chosen how pages are sized", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences"
          ? { preferences: savedPreferences({ mode: "single", fit: "original" }) }
          : comic()
      ));

      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);
      await waitFor(() => expect(surface()).toHaveAttribute("data-page-fit", "original"));

      expect(screen.queryByRole("button", { name: /use it here/i })).not.toBeInTheDocument();
    });

    it("leaves a desktop alone, which is already reading the way it should", async () => {
      useScreen({ width: 1440, height: 900, coarsePointer: false });
      renderReader();
      await page(1);

      expect(screen.queryByRole("button", { name: /use it here/i })).not.toBeInTheDocument();
    });

    it("records an accepted suggestion against this screen, not against the account", async () => {
      const user = userEvent.setup();
      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);
      await screen.findByRole("button", { name: /use it here/i });

      await user.click(screen.getByRole("button", { name: /use it here/i }));

      await waitFor(() => expect(api.put).toHaveBeenCalledWith(
        "/api/reader/preferences",
        expect.objectContaining({
          preferences: expect.objectContaining({
            // The account default is untouched: every other screen reads as before.
            settings: expect.objectContaining({ fit: "contain" }),
            overrides: [{ context: { device: "phone", orientation: "portrait" }, settings: { fit: "width" } }],
          }),
        }),
        expect.anything()
      ));
    });

    it("stops asking once it has been dismissed", async () => {
      const user = userEvent.setup();
      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);
      await screen.findByRole("button", { name: /dismiss suggestion/i });

      await user.click(screen.getByRole("button", { name: /dismiss suggestion/i }));

      expect(screen.queryByRole("button", { name: /use it here/i })).not.toBeInTheDocument();
      await waitFor(() => expect(api.put).toHaveBeenCalledWith(
        "/api/reader/preferences",
        expect.objectContaining({ preferences: expect.objectContaining({ dismissedSuggestions: ["fit:phone:portrait"] }) }),
        expect.anything()
      ));
    });

    it("offers two-page mode once on a tablet in landscape", async () => {
      useScreen({ width: 1180, height: 820 });
      renderReader();
      await page(1);

      expect(await screen.findByRole("button", { name: /use two pages/i })).toBeInTheDocument();
    });

    it("gets the mode suggestion out of the way while thumbnails are open", async () => {
      const user = userEvent.setup();
      useScreen({ width: 1180, height: 820 });
      renderReader();
      await page(1);
      await screen.findByRole("button", { name: /use two pages/i });

      await user.click(screen.getByRole("button", { name: /show page thumbnails/i }));

      expect(screen.getByRole("group", { name: /page thumbnails/i })).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /use two pages/i })).not.toBeInTheDocument();
    });

    it("gets the fit suggestion out of the way while settings are open", async () => {
      const user = userEvent.setup();
      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);
      await screen.findByRole("button", { name: /use it here/i });

      await user.click(screen.getByRole("button", { name: "Reader settings" }));

      expect(screen.getByRole("dialog", { name: "Reader settings" })).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /use it here/i })).not.toBeInTheDocument();
    });
  });

  describe("fullscreen layout", () => {
    it("keeps single-page artwork inside the same control-safe stage", async () => {
      renderReader();
      await page(1);
      Object.defineProperty(document, "fullscreenElement", { configurable: true, value: document.documentElement });

      act(() => document.dispatchEvent(new Event("fullscreenchange")));

      expect(document.querySelector(".reader-root")).toHaveAttribute("data-fullscreen", "true");
      expect(surface()).not.toHaveClass("fullscreen-container");
      expect(surface().closest(".reader-stage-fullscreen")).toHaveClass("reader-stage-controls-visible");

      Object.defineProperty(document, "fullscreenElement", { configurable: true, value: null });
      act(() => document.dispatchEvent(new Event("fullscreenchange")));
    });
  });

  describe("keeping the page clear of the bottom controls", () => {
    afterEach(() => vi.restoreAllMocks());

    /**
     * The bar is not one fixed height — a zoom badge, a spread's page range and
     * a coarse pointer's larger buttons all grow it — so the stage reserves what
     * it measures. Reserving a constant is what buried the foot of the page.
     */
    const withMeasuredControls = (height) => vi.spyOn(HTMLElement.prototype, "getBoundingClientRect")
      .mockImplementation(function measured() {
        const box = this.classList?.contains("reader-controls") ? height : 0;
        return { height: box, width: 0, top: 0, right: 0, bottom: box, left: 0, x: 0, y: 0 };
      });

    it("reserves the height the controls actually take", async () => {
      withMeasuredControls(104);
      renderReader();
      await page(1);

      await waitFor(() => expect(document.querySelector(".reader-root"))
        .toHaveStyle({ "--reader-controls-height": "104px" }));
    });

    it("leaves the fallback reservation standing when nothing can be measured", async () => {
      withMeasuredControls(0);
      renderReader();
      await page(1);

      expect(document.querySelector(".reader-root").style.getPropertyValue("--reader-controls-height")).toBe("");
    });
  });

  describe("handing a screen back to the account default", () => {
    it("does not leave a zoom behind that was measured against the old fit", async () => {
      const user = userEvent.setup();
      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);

      await user.click(await screen.findByRole("button", { name: /use it here/i }));
      await waitFor(() => expect(surface()).toHaveAttribute("data-page-fit", "width"));

      await user.click(screen.getByRole("button", { name: /zoom in/i }));
      expect(surface()).toHaveAttribute("data-page-zoomed", "true");

      await user.click(screen.getByRole("button", { name: /reader settings/i }));
      await user.click(await screen.findByRole("switch", { name: /different page size here/i }));

      await waitFor(() => expect(surface()).toHaveAttribute("data-page-fit", "contain"));
      expect(surface()).toHaveAttribute("data-page-zoomed", "false");
    });
  });

  describe("how much it holds in memory", () => {
    const cachedPages = () => new Set(FakeImage.instances
      .map(({ src }) => src.match(/\/pages\/(\d+)/)?.[1])
      .filter(Boolean));

    it("reads further ahead on a desktop than on a phone", async () => {
      vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
        path === "/api/reader/preferences" ? { preferences: PAGED_PREFERENCES } : comic(12)
      ));

      useScreen({ width: 1440, height: 900, coarsePointer: false });
      renderReader();
      await page(1);
      await waitFor(() => expect(cachedPages().size).toBeGreaterThan(3));
      const onDesktop = cachedPages().size;

      cleanup();
      FakeImage.reset();
      useScreen({ width: 390, height: 844 });
      renderReader();
      await page(1);
      // Nothing more is coming once the window is full; give the queue a moment
      // to prove it has stopped rather than racing it.
      await act(() => new Promise((resolve) => setTimeout(resolve, 200)));

      expect(cachedPages().size).toBeLessThan(onDesktop);
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
