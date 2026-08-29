import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminDropbox } from "./AdminDropbox";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

describe("AdminDropbox", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({
      users: [{
        id: 7,
        email: "reader@example.com",
        name: "Reader",
        lastSyncedAt: null,
        dropboxComicCount: 2,
      }],
    });
    vi.mocked(api.post).mockResolvedValue({});
  });

  it("describes the one-way workflow as an import while preserving the API action", async () => {
    const user = userEvent.setup();
    render(<AdminDropbox />);

    expect(await screen.findByRole("heading", { name: "Dropbox Imports" })).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "Last import" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Force import" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/admin/dropbox-users/7/sync", {}));
    expect(toast).toHaveBeenCalledWith({ title: "Dropbox import completed" });
    expect(screen.queryByText(/Dropbox sync/i)).not.toBeInTheDocument();
  });
});
