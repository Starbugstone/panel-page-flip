import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminOverview } from "./AdminOverview";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
// One identity for the whole run: the loading effect depends on `toast`, so a
// fresh function per render would refetch for ever.
const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

describe("AdminOverview", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("reports installation storage in the units the per-user figures use", async () => {
    vi.mocked(api.get).mockResolvedValue({
      stats: { totalUsers: 3, verifiedUsers: 2, totalComics: 40, storageUsed: 42 * 1024 ** 3, recentUsers: [] },
    });

    render(<AdminOverview />);

    // Both numbers divide by 1024; an admin comparing this tile with a row of
    // the user list must not have to wonder whether they are the same unit.
    expect(await screen.findByText("42.00 GiB")).toBeInTheDocument();
  });

  it("shows nothing rather than NaN before the stats arrive", async () => {
    vi.mocked(api.get).mockResolvedValue({ stats: null });

    render(<AdminOverview />);

    expect(await screen.findByText("0 B")).toBeInTheDocument();
  });

  describe("orphan cleanup", () => {
    const missingFolder = {
      orphanedComics: [],
      orphanedCovers: [],
      totals: { orphanedComics: 0, orphanedCovers: 0 },
      error: "Comics directory \"/data/comics\" does not exist.",
    };
    const dryRun = {
      orphanedComics: [],
      orphanedCovers: [],
      totals: { orphanedComics: 2, orphanedCovers: 1 },
    };

    const renderOverview = async () => {
      vi.mocked(api.get).mockResolvedValue({ stats: null });
      render(<AdminOverview />);
      return screen.findByRole("button", { name: "Dry run" });
    };
    const quarantineButton = () => screen.getByRole("button", { name: "Quarantine files" });

    it("reports a cleanup the server refused as a failure, not as an empty success", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockResolvedValue({ cleanup: missingFolder });
      await user.click(await renderOverview());

      expect(await screen.findByRole("alert")).toHaveTextContent("does not exist");
      expect(screen.queryByText(/Found 0 orphan comics/)).not.toBeInTheDocument();
      expect(toast).toHaveBeenCalledWith({
        title: "Cleanup failed",
        description: missingFolder.error,
        variant: "destructive",
      });
      expect(toast).not.toHaveBeenCalledWith({ title: "Cleanup dry-run completed" });
      expect(quarantineButton()).toBeDisabled();
    });

    it("offers to quarantine only after a dry run that worked", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockResolvedValue({ cleanup: dryRun });
      const dryRunButton = await renderOverview();
      expect(quarantineButton()).toBeDisabled();

      await user.click(dryRunButton);

      expect(await screen.findByText(/Found 2 orphan comics and 1 orphan covers/)).toBeInTheDocument();
      expect(toast).toHaveBeenCalledWith({ title: "Cleanup dry-run completed" });
      expect(quarantineButton()).toBeEnabled();
    });

    it("withdraws the offer when a later dry run fails", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockResolvedValueOnce({ cleanup: dryRun });
      const dryRunButton = await renderOverview();
      await user.click(dryRunButton);
      await screen.findByText(/Found 2 orphan comics/);

      vi.mocked(api.post).mockRejectedValueOnce(new Error("Server unavailable"));
      await user.click(dryRunButton);

      expect(await screen.findByRole("button", { name: "Dry run" })).toBeEnabled();
      expect(toast).toHaveBeenCalledWith({
        title: "Cleanup failed",
        description: "Server unavailable",
        variant: "destructive",
      });
      expect(quarantineButton()).toBeDisabled();
    });

    it("asks for a fresh dry run once files have been quarantined", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockResolvedValueOnce({ cleanup: dryRun });
      await user.click(await renderOverview());
      await screen.findByText(/Found 2 orphan comics/);

      vi.mocked(api.post).mockResolvedValueOnce({
        cleanup: { ...dryRun, quarantined: { orphanedComics: 2, orphanedCovers: 1 } },
      });
      await user.click(quarantineButton());

      expect(await screen.findByText(/Quarantined 2 comics and 1 covers/)).toBeInTheDocument();
      expect(api.post).toHaveBeenLastCalledWith("/api/admin/cleanup/apply", {});
      expect(quarantineButton()).toBeDisabled();
    });
  });
});
