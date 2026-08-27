import { render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AdSenseProvider, useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import { api } from "@/lib/api";
import { keepRouteAdFree, loadAdSenseScript } from "@/lib/adsense-loader";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));
vi.mock("@/lib/adsense-loader", () => ({
  loadAdSenseScript: vi.fn(() => Promise.resolve("ready")),
  keepRouteAdFree: vi.fn(() => vi.fn()),
}));

const CLIENT = "ca-pub-1234567890123456";

const serverSays = (adsense) => vi.mocked(api.get).mockResolvedValue({ adsense });

function Probe() {
  const { config, scriptStatus } = useAdSense();

  return <span data-testid="probe">{`${config.enabled ? "on" : "off"}:${scriptStatus}`}</span>;
}

const renderAt = (pathname) => render(
  <MemoryRouter initialEntries={[pathname]}>
    <AdSenseProvider>
      <Routes>
        <Route path="*" element={<Probe />} />
      </Routes>
    </AdSenseProvider>
  </MemoryRouter>
);

beforeEach(() => vi.clearAllMocks());
afterEach(() => {
  vi.mocked(loadAdSenseScript).mockResolvedValue("ready");
  vi.mocked(keepRouteAdFree).mockReturnValue(vi.fn());
});

describe("loading Google's site code", () => {
  it("loads it on a page this application owns", async () => {
    serverSays({ enabled: true, client: CLIENT });

    renderAt("/login");

    await waitFor(() => expect(loadAdSenseScript).toHaveBeenCalledWith(CLIENT));
    await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent("on:ready"));
  });

  /**
   * The point of the whole feature. A library, a reader or a comic's details
   * must never so much as fetch Google's script.
   */
  it.each(["/dashboard", "/read/12", "/upload/bulk/session", "/settings", "/sharing"])(
    "never loads it on %s",
    async (pathname) => {
      serverSays({ enabled: true, client: CLIENT });

      renderAt(pathname);

      await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/public-config", expect.anything()));
      expect(loadAdSenseScript).not.toHaveBeenCalled();
    }
  );

  it("loads nothing where the installation shows no advertising", async () => {
    serverSays({ enabled: false, client: null });

    renderAt("/");

    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(loadAdSenseScript).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent("off:idle"));
  });

  it("loads nothing when the configuration could not be read", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("Unable to reach the server"));

    renderAt("/");

    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(loadAdSenseScript).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent("off:idle"));
  });

  it("reports a blocked script without breaking the page", async () => {
    serverSays({ enabled: true, client: CLIENT });
    vi.mocked(loadAdSenseScript).mockResolvedValue("unavailable");

    renderAt("/upload");

    await waitFor(() => expect(screen.getByTestId("probe")).toHaveTextContent("on:unavailable"));
  });
});

describe("keeping placed advertising off content pages", () => {
  it("watches the page when the route is not one advertising may appear on", async () => {
    serverSays({ enabled: true, client: CLIENT });

    renderAt("/read/12");

    await waitFor(() => expect(keepRouteAdFree).toHaveBeenCalled());
  });

  /**
   * Auto Ads arrive after the navigation commits, so the guard has to outlive
   * the commit — a sweep that only ran once would find an empty page and the
   * advertisement that follows would stay beside the artwork.
   */
  it("keeps watching until the route changes, then stops", async () => {
    const stopWatching = vi.fn();
    vi.mocked(keepRouteAdFree).mockReturnValue(stopWatching);
    serverSays({ enabled: true, client: CLIENT });

    const { unmount } = renderAt("/read/12");

    await waitFor(() => expect(keepRouteAdFree).toHaveBeenCalled());
    expect(stopWatching).not.toHaveBeenCalled();

    unmount();
    expect(stopWatching).toHaveBeenCalled();
  });

  it("leaves an ad-safe page alone", async () => {
    serverSays({ enabled: true, client: CLIENT });

    renderAt("/login");

    await waitFor(() => expect(loadAdSenseScript).toHaveBeenCalled());
    expect(keepRouteAdFree).not.toHaveBeenCalled();
  });
});
