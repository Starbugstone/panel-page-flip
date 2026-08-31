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

describe("AdminDropbox in bulk", () => {
  const connected = (id, name) => ({
    id,
    name,
    email: `${name.toLowerCase()}@example.com`,
    lastSyncedAt: null,
    dropboxComicCount: 2,
  });

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({
      users: [connected(7, "Reader"), connected(8, "Writer")],
    });
    vi.mocked(api.post).mockResolvedValue({});
  });

  const box = (name) => screen.getByRole("checkbox", { name: `Select ${name}` });

  it("disconnects every ticked account through the endpoint the row button uses", async () => {
    const user = userEvent.setup();
    render(<AdminDropbox />);
    await screen.findByRole("heading", { name: "Dropbox Imports" });

    await user.click(screen.getByRole("checkbox", { name: "Select all accounts" }));
    await user.click(screen.getByRole("button", { name: /Disconnect selected/ }));
    await user.click(screen.getByRole("button", { name: "Disconnect" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(api.post.mock.calls.map(([path]) => path))
      .toEqual(["/api/admin/dropbox-users/7/disconnect", "/api/admin/dropbox-users/8/disconnect"]);
    expect(toast).toHaveBeenCalledWith({ title: "2 accounts disconnected" });
  });

  it("runs the import for every ticked account", async () => {
    const user = userEvent.setup();
    render(<AdminDropbox />);
    await screen.findByRole("heading", { name: "Dropbox Imports" });

    await user.click(box("Reader"));
    await user.click(screen.getByRole("button", { name: /Force import selected/ }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/admin/dropbox-users/7/sync", {}));
    expect(toast).toHaveBeenCalledWith({ title: "1 import completed" });
  });

  it("reports the accounts an import failed for without hiding the ones it did not", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockImplementation((path) => (
      path.startsWith("/api/admin/dropbox-users/8")
        ? Promise.reject(new Error("The Dropbox token has expired."))
        : Promise.resolve({})
    ));
    render(<AdminDropbox />);
    await screen.findByRole("heading", { name: "Dropbox Imports" });

    await user.click(screen.getByRole("checkbox", { name: "Select all accounts" }));
    await user.click(screen.getByRole("button", { name: /Force import selected/ }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith({
      title: "1 of 2 imports completed",
      description: "Writer: The Dropbox token has expired.",
      variant: "destructive",
    }));
  });
});

