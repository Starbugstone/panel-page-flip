import { render, screen } from "@testing-library/react";
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
});
