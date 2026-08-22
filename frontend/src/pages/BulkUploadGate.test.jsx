import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import BulkUploadGate from "@/pages/BulkUploadGate.jsx";
import { api } from "@/lib/api";

const { scriptStatus } = vi.hoisted(() => ({ scriptStatus: { value: "idle" } }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));
vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({
  useAdSense: () => ({
    config: { enabled: true, client: "ca-pub-1234567890123456", testMode: false },
    isLoading: false,
    scriptStatus: scriptStatus.value,
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
  scriptStatus.value = "idle";
  vi.mocked(api.post).mockResolvedValue({ active: true, rewarded: true });
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
    scriptStatus.value = "unavailable";

    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("goes straight to the uploader when the server could not be asked", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("Unable to reach the server"));
    scriptStatus.value = "ready";

    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("does not ask again part way through a batch", async () => {
    serverSays({ active: true, gateRequired: true });
    scriptStatus.value = "ready";

    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload comics" })).toBeInTheDocument();
  });

  it("waits for Google's code rather than deciding without it", async () => {
    serverSays({ active: false, gateRequired: true });
    scriptStatus.value = "loading";

    renderGate();

    await waitFor(() => expect(screen.getByLabelText("Preparing bulk upload")).toBeInTheDocument());
    expect(api.get).not.toHaveBeenCalled();
  });
});

describe("the rewarded choice", () => {
  beforeEach(() => {
    serverSays({ active: false, gateRequired: true });
    scriptStatus.value = "ready";
  });

  it("says what is being asked for and what it unlocks", async () => {
    renderGate();

    expect(await screen.findByRole("heading", { name: "Bulk upload" })).toBeInTheDocument();
    expect(screen.getByText(/watch a short advertisement to unlock bulk upload for this batch/i))
      .toBeInTheDocument();
    expect(screen.getByText(/one advertisement covers the whole batch/i)).toBeInTheDocument();
  });

  /** Declining must be as easy to find as accepting, and must lead somewhere. */
  it("offers single upload as an equally reachable alternative", async () => {
    renderGate();

    const decline = await screen.findByRole("link", { name: "Use single upload instead" });
    await userEvent.click(decline);

    expect(await screen.findByRole("heading", { name: "Upload New Comic" })).toBeInTheDocument();
  });

  it("records one batch on the server and opens the uploader", async () => {
    renderGate();

    await userEvent.click(await screen.findByRole("button", { name: "Watch ad and continue" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/upload/bulk/session",
      { rewarded: true }
    ));
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
});
