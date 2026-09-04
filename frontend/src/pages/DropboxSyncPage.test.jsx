import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import DropboxSyncPage from "./DropboxSyncPage";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast }) }));

const renderPage = () => render(
  <MemoryRouter>
    <DropboxSyncPage />
  </MemoryRouter>
);

describe("DropboxSyncPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("describes one-way Dropbox imports using the correct app-folder path", async () => {
    vi.mocked(api.get).mockImplementation((path) => {
      if (path === "/api/dropbox/status") {
        return Promise.resolve({ configured: true, connected: true, user: "reader@example.com", lastSync: null });
      }

      return Promise.resolve({ files: [] });
    });

    renderPage();

    expect(await screen.findByRole("heading", { name: "Dropbox Import" })).toBeInTheDocument();
    expect(screen.getByText("Import comics from your Dropbox app folder into Panel Page Flip.")).toBeInTheDocument();
    expect(screen.getByText("Manage your Dropbox connection and imports")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Import new comics" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "How to organize files" }).parentElement)
      .toHaveClass("page-actions");

    fireEvent.click(screen.getByRole("button", { name: "How to organize files" }));

    expect(await screen.findByRole("heading", { name: "Dropbox File Organization Guide" })).toBeInTheDocument();
    expect(document.body).toHaveTextContent("Dropbox app folder");
    expect(document.body).not.toHaveTextContent("Apps/StarbugStoneComics");
    expect(document.body.textContent).not.toMatch(/\bsync(?:ed|ing)?\b/i);
  });

  it("uses the API error message when refreshing files fails", async () => {
    let refreshShouldFail = false;
    vi.mocked(api.get).mockImplementation((path) => {
      if (path === "/api/dropbox/status") {
        return Promise.resolve({ configured: true, connected: true, user: null, lastSync: null });
      }
      if (refreshShouldFail) {
        return Promise.reject(new Error("Dropbox is temporarily unavailable"));
      }

      return Promise.resolve({ files: [] });
    });

    renderPage();
    await screen.findByRole("button", { name: "Refresh Files" });
    refreshShouldFail = true;
    fireEvent.click(screen.getByRole("button", { name: "Refresh Files" }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith({
      title: "Refresh Failed",
      description: "Dropbox is temporarily unavailable",
      variant: "destructive",
    }));
  });

  it("renders the local connection state while Dropbox files are still loading", async () => {
    let resolveFiles;
    vi.mocked(api.get).mockImplementation((path) => {
      if (path === "/api/dropbox/status") {
        return Promise.resolve({ configured: true, connected: true, user: null, lastSync: null });
      }

      return new Promise((resolve) => { resolveFiles = resolve; });
    });

    renderPage();

    expect(await screen.findByText("Connected to Dropbox")).toBeInTheDocument();
    expect(screen.getByText("Loading Dropbox files...")).toBeInTheDocument();

    resolveFiles({ files: [] });
    await waitFor(() => expect(screen.queryByText("Loading Dropbox files...")).not.toBeInTheDocument());
  });

  it("uses a useful fallback when a refresh error has no message", async () => {
    let refreshShouldFail = false;
    vi.mocked(api.get).mockImplementation((path) => {
      if (path === "/api/dropbox/status") {
        return Promise.resolve({ configured: true, connected: true, user: null, lastSync: null });
      }
      if (refreshShouldFail) return Promise.reject({});

      return Promise.resolve({ files: [] });
    });

    renderPage();
    await screen.findByRole("button", { name: "Refresh Files" });
    refreshShouldFail = true;
    fireEvent.click(screen.getByRole("button", { name: "Refresh Files" }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      description: "Could not refresh Dropbox files. Please try again.",
    })));
  });

  it("does not advance the last import after a partial bulk-import failure", async () => {
    vi.mocked(api.get).mockImplementation((path) => {
      if (path === "/api/dropbox/status") {
        return Promise.resolve({ configured: true, connected: true, user: null, lastSync: null });
      }

      return Promise.resolve({ files: [] });
    });
    vi.mocked(api.post).mockResolvedValue({
      message: "Dropbox import partially completed: 2 imported, 1 failed.",
      newFiles: 2,
      failedFiles: 1,
    });

    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Import new comics" }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith({
      title: "Import Incomplete",
      description: "Dropbox import partially completed: 2 imported, 1 failed.",
      variant: "destructive",
    }));
    expect(screen.getByText("Import partially completed: 2 imported, 1 failed")).toBeInTheDocument();
    expect(screen.queryByText(/^Last import:/)).not.toBeInTheDocument();
  });

  it("records the last import after a fully successful bulk import", async () => {
    vi.mocked(api.get).mockImplementation((path) => {
      if (path === "/api/dropbox/status") {
        return Promise.resolve({ configured: true, connected: true, user: null, lastSync: null });
      }

      return Promise.resolve({ files: [] });
    });
    vi.mocked(api.post).mockResolvedValue({ newFiles: 1, failedFiles: 0 });

    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Import new comics" }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith({
      title: "Import Complete",
      description: "1 new comic has been imported from Dropbox.",
    }));
    expect(screen.getByText("Import completed: 1 new comic added")).toBeInTheDocument();
    expect(screen.getByText(/^Last import:/)).toBeInTheDocument();
  });

  it("explains an unavailable integration instead of offering a dead connect action", async () => {
    vi.mocked(api.get).mockResolvedValue({ configured: false, connected: false, user: null, lastSync: null });

    renderPage();

    expect(await screen.findByText("Dropbox imports are not enabled on this server.")).toBeInTheDocument();
    expect(screen.getByText(/administrator must configure Dropbox before accounts can connect/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Connect to Dropbox" })).not.toBeInTheDocument();
    expect(document.body).not.toHaveTextContent("Apps/StarbugStoneComics");
  });

  it("keeps a failed status check distinct from an unconfigured server and retries it", async () => {
    let statusAttempts = 0;
    vi.mocked(api.get).mockImplementation((path) => {
      if (path !== "/api/dropbox/status") return Promise.resolve({ files: [] });

      statusAttempts += 1;
      if (statusAttempts === 1) return Promise.reject(new Error("Network unavailable"));

      return Promise.resolve({ configured: true, connected: false, user: null, lastSync: null });
    });

    renderPage();

    expect(await screen.findByRole("alert")).toHaveTextContent("Could not check Dropbox connection");
    expect(screen.queryByText("Dropbox imports are not enabled on this server.")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Connect to Dropbox" })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Retry status check" }));

    expect(await screen.findByRole("button", { name: "Connect to Dropbox" })).toBeInTheDocument();
    expect(statusAttempts).toBe(2);
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});
