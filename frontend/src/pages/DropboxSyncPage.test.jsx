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
        return Promise.resolve({ connected: true, user: "reader@example.com", lastSync: null });
      }

      return Promise.resolve({ files: [] });
    });

    renderPage();

    expect(await screen.findByRole("heading", { name: "Dropbox Import" })).toBeInTheDocument();
    expect(screen.getByText("Import comics from your Dropbox app folder into Panel Page Flip.")).toBeInTheDocument();
    expect(screen.getByText("Manage your Dropbox connection and imports")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Import new comics" })).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "How to organize files" }));

    expect(await screen.findByRole("heading", { name: "Dropbox File Organization Guide" })).toBeInTheDocument();
    expect(document.body).toHaveTextContent("Apps/StarbugStoneComics");
    expect(document.body).not.toHaveTextContent("Applications/StarbugStoneComics");
    expect(document.body.textContent).not.toMatch(/\bsync(?:ed|ing)?\b/i);
  });

  it("uses the API error message when refreshing files fails", async () => {
    let refreshShouldFail = false;
    vi.mocked(api.get).mockImplementation((path) => {
      if (path === "/api/dropbox/status") {
        return Promise.resolve({ connected: true, user: null, lastSync: null });
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

  it("uses a useful fallback when a refresh error has no message", async () => {
    let refreshShouldFail = false;
    vi.mocked(api.get).mockImplementation((path) => {
      if (path === "/api/dropbox/status") {
        return Promise.resolve({ connected: true, user: null, lastSync: null });
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
});
