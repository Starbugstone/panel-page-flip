import { render, screen, waitFor } from "@testing-library/react";
import { StrictMode } from "react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BulkUploadGate from "@/pages/BulkUploadGate.jsx";
import { api } from "@/lib/api";
import { requestRewardedAd } from "@/lib/rewarded-ad";

const { adSense } = vi.hoisted(() => ({
  adSense: { scriptStatus: "idle", isLoading: false },
}));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));
vi.mock("@/lib/rewarded-ad", () => ({ requestRewardedAd: vi.fn() }));
vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({
  useAdSense: () => ({
    config: { enabled: true, client: "ca-pub-1234567890123456" },
    isActive: true,
    isLoading: adSense.isLoading,
    scriptStatus: adSense.scriptStatus,
  }),
}));

const serverSays = (session) => vi.mocked(api.get).mockResolvedValue(session);

const renderGate = (entry = "/upload/bulk") => render(
  <MemoryRouter initialEntries={[entry]}>
    <Routes>
      <Route path="/upload/bulk" element={<BulkUploadGate />} />
      <Route path="/upload/bulk/session" element={<h1>Bulk upload comics</h1>} />
      <Route path="/upload" element={<h1>Upload New Comic</h1>} />
    </Routes>
  </MemoryRouter>
);

beforeEach(() => {
  vi.clearAllMocks();
  adSense.scriptStatus = "idle";
  adSense.isLoading = false;
  vi.mocked(api.post).mockResolvedValue({ active: true, rewarded: true });
  vi.mocked(requestRewardedAd).mockResolvedValue("viewed");
});

describe("entering bulk upload", () => {
  it("goes straight to the uploader where the installation shows no advertising", async () => {
    serverSays({ active: false, gateRequired: false });

    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
  });

  it("goes straight to the uploader when no rewarded advertisement can be served", async () => {
    serverSays({ active: false, gateRequired: true });
    adSense.scriptStatus = "unavailable";

    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("goes straight to the uploader when the server could not be asked", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("Unable to reach the server"));
    adSense.scriptStatus = "ready";

    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("does not ask again part way through a batch", async () => {
    serverSays({ active: true, gateRequired: true });
    adSense.scriptStatus = "ready";

    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("waits for Google's code rather than deciding without it", async () => {
    serverSays({ active: false, gateRequired: true });
    adSense.scriptStatus = "loading";

    renderGate();

    await waitFor(() => expect(screen.getByLabelText("Preparing bulk upload")).toBeInTheDocument());
    expect(api.get).not.toHaveBeenCalled();
  });

  /**
   * The regression that made the offer unreachable.
   *
   * Until the runtime configuration lands, advertising looks switched off and
   * `scriptStatus` is "idle" rather than "loading" — so a gate that only waited
   * on the script decided immediately, resolved "open", and redirected before
   * the configuration arrived. React commits child effects before parent ones,
   * so the session request went out first and won that race on every ordinary
   * load: the rewarded choice was never offered at all.
   */
  it("waits for the runtime configuration before deciding", async () => {
    serverSays({ active: false, gateRequired: true });
    adSense.isLoading = true;
    adSense.scriptStatus = "idle";

    renderGate();

    await waitFor(() => expect(screen.getByLabelText("Preparing bulk upload")).toBeInTheDocument());
    expect(api.get).not.toHaveBeenCalled();
  });
});

describe("the rewarded choice", () => {
  beforeEach(() => {
    serverSays({ active: false, gateRequired: true });
    adSense.scriptStatus = "ready";
  });

  it("says what is being asked for and what it unlocks", async () => {
    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload" })).toBeInTheDocument();
    expect(screen.getByText(/watch a short advertisement to unlock bulk upload for this batch/i))
      .toBeInTheDocument();
    expect(screen.getByText(/one advertisement covers the whole batch/i)).toBeInTheDocument();
    expect(screen.getByText(/if no rewarded advertisement is available, bulk upload will open without one/i))
      .toBeInTheDocument();
  });

  /** Declining must be as easy to find as accepting, and must lead somewhere. */
  it("offers single upload as an equally reachable alternative", async () => {
    renderGate();

    const decline = await screen.findByRole("link", { name: "Use single upload instead" });
    await userEvent.click(decline);

    expect(await screen.findByRole("heading", { name: "Upload New Comic" })).toBeInTheDocument();
  });

  it("preserves the chosen folder when switching to single upload", async () => {
    renderGate("/upload/bulk?folder=7");

    expect(await screen.findByRole("link", { name: "Use single upload instead" }))
      .toHaveAttribute("href", "/upload?folder=7");
  });

  it("shows the advertisement before recording anything", async () => {
    renderGate();

    await userEvent.click(await screen.findByRole("button", { name: "Watch ad and continue" }));

    expect(requestRewardedAd).toHaveBeenCalled();
    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/upload/bulk/session",
      { rewarded: true }
    ));
    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  /**
   * `rewarded` is an audit note, and an audit note that says "yes" whatever
   * happened records nothing. Google confirming the view is the only thing that
   * may set it.
   */
  it("records no reward when Google had no advertisement to show", async () => {
    vi.mocked(requestRewardedAd).mockResolvedValue("unavailable");

    renderGate();
    await userEvent.click(await screen.findByRole("button", { name: "Watch ad and continue" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/upload/bulk/session",
      { rewarded: false }
    ));
    // Still opens: issue #73 rules out letting missing inventory block a batch.
    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("keeps the offer open when the advertisement is closed early", async () => {
    vi.mocked(requestRewardedAd).mockResolvedValue("dismissed");

    renderGate();
    await userEvent.click(await screen.findByRole("button", { name: "Watch ad and continue" }));

    expect(await screen.findByRole("status")).toHaveTextContent(/closed before it finished/i);
    expect(api.post).not.toHaveBeenCalled();
    // The button has to come back, or the page is a dead end.
    expect(screen.getByRole("button", { name: "Watch ad and continue" })).toBeEnabled();
  });

  /**
   * Strict Mode replays a committed effect as cleanup-then-setup while keeping
   * the component's refs. A teardown that clears "am I still mounted" without
   * the setup restoring it leaves the page believing it unmounted, and the
   * navigation after a watched advertisement is skipped — the button goes quiet
   * and the uploader never opens.
   */
  it("still opens the uploader when effects are replayed in Strict Mode", async () => {
    render(
      <StrictMode>
        <MemoryRouter initialEntries={["/upload/bulk"]}>
          <Routes>
            <Route path="/upload/bulk" element={<BulkUploadGate />} />
            <Route path="/upload/bulk/session" element={<h1>Bulk upload comics</h1>} />
          </Routes>
        </MemoryRouter>
      </StrictMode>
    );

    await userEvent.click(await screen.findByRole("button", { name: "Watch ad and continue" }));

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  /**
   * The session is bookkeeping about which batch this is, not permission to
   * upload. Losing it must not cost somebody the upload they just agreed to.
   */
  it("opens the uploader even when the batch could not be recorded", async () => {
    vi.mocked(api.post).mockRejectedValue(new Error("Unable to reach the server"));

    renderGate();
    await userEvent.click(await screen.findByRole("button", { name: "Watch ad and continue" }));

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("carries the chosen destination folder through to the uploader", async () => {
    renderGate("/upload/bulk?folder=7");

    await userEvent.click(await screen.findByRole("button", { name: "Watch ad and continue" }));

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  /**
   * The advertisement and the request that follows it both outlive the click,
   * and the page can be gone by the time they finish. Navigating anyway would
   * drag somebody who chose single upload back onto the bulk uploader.
   */
  it("does not navigate after the page has been left", async () => {
    let finishAd;
    vi.mocked(requestRewardedAd).mockReturnValue(new Promise((resolve) => { finishAd = resolve; }));

    const { unmount } = renderGate();
    await userEvent.click(await screen.findByRole("button", { name: "Watch ad and continue" }));

    unmount();
    finishAd("viewed");

    await waitFor(() => expect(api.post).toHaveBeenCalled());
    expect(screen.queryByRole("heading", { name: "Bulk upload comics" })).not.toBeInTheDocument();
  });
});
