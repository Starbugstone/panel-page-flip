import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import AdminUserDetails from "./AdminUserDetails";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() } }));
// Stable across renders on purpose: the page's load effect lists `toast` in its
// dependencies, so a fresh function per render would re-fetch for ever.
const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { id: 1, roles: ["ROLE_ADMIN"] } }) }));

// The comics and tags tabs each load a list of their own. Nothing here is about
// them.
vi.mock("@/components/AdminComicsList", () => ({ AdminComicsList: () => null }));
vi.mock("@/components/AdminTagsList", () => ({ AdminTagsList: () => null }));

const account = {
  id: 7,
  email: "reader@example.com",
  name: "Test Reader",
  username: "QuietFalcon7314",
  roles: ["ROLE_USER"],
  isEmailVerified: true,
  comicCount: 0,
  tagCount: 0,
  storageUsedBytes: 0,
  storageQuotaBytes: 10 * 1024 ** 3,
  unmeasuredComicCount: 0,
};

const renderPage = () => render(
  <MemoryRouter initialEntries={["/admin/users/7"]}>
    <Routes>
      <Route path="/admin/users/:userId" element={<AdminUserDetails />} />
    </Routes>
  </MemoryRouter>,
);

const openAccountTab = async (user) => {
  await user.click(await screen.findByRole("tab", { name: "Account" }));
};

describe("AdminUserDetails", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({ user: account });
    vi.mocked(api.post).mockResolvedValue({ message: "User code replaced." });
  });

  it("shows the same storage figures the user list does", async () => {
    vi.mocked(api.get).mockResolvedValue({
      user: { ...account, comicCount: 40, storageUsedBytes: 8.3 * 1024 ** 3, unmeasuredComicCount: 0 },
    });
    renderPage();

    expect(await screen.findByText("8.30 GiB")).toBeInTheDocument();
    expect(screen.getByRole("progressbar")).toHaveAccessibleName(
      "Storage used: 8.30 GiB of 10.00 GiB, 83.0%."
    );
  });

  it("flags storage as incomplete when sizes are missing, here as in the list", async () => {
    vi.mocked(api.get).mockResolvedValue({
      user: { ...account, comicCount: 5, storageUsedBytes: 6.4 * 1024 ** 3, unmeasuredComicCount: 2 },
    });
    renderPage();

    expect(await screen.findByRole("progressbar")).toHaveAccessibleName(
      /Measured storage used.*2 comics have no stored file-size metadata/
    );
  });

  it("rotates the user code through the route the backend serves", async () => {
    const user = userEvent.setup();
    renderPage();
    await openAccountTab(user);

    await user.click(screen.getByRole("button", { name: "Replace user code" }));
    await user.click(within(screen.getByRole("alertdialog")).getByRole("button", { name: "Replace their code" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/users/7/user-code/rotate", {}));
  });

  it("never puts the replacement code in front of the administrator", async () => {
    const user = userEvent.setup();
    renderPage();
    await openAccountTab(user);
    await user.click(screen.getByRole("button", { name: "Replace user code" }));
    await user.click(within(screen.getByRole("alertdialog")).getByRole("button", { name: "Replace their code" }));

    await waitFor(() => expect(api.post).toHaveBeenCalled());
    expect(screen.queryByText(/U-[0-9A-Z]{4}/)).not.toBeInTheDocument();
  });

  /**
   * Every accepted request mints another code, and the dialog stays open until
   * this one settles — so a second press replaces the code the user is at that
   * moment reading off their own Sharing page.
   */
  it("cannot be pressed twice while a rotation is in flight", async () => {
    const user = userEvent.setup();
    let release;
    vi.mocked(api.post).mockReturnValue(new Promise((resolve) => { release = resolve; }));

    renderPage();
    await openAccountTab(user);

    await user.click(screen.getByRole("button", { name: "Replace user code" }));
    const confirm = within(screen.getByRole("alertdialog"))
      .getByRole("button", { name: "Replace their code" });
    await user.click(confirm);

    await waitFor(() => expect(
      within(screen.getByRole("alertdialog")).getByRole("button", { name: "Replacing…" })
    ).toBeDisabled());
    expect(api.post).toHaveBeenCalledTimes(1);

    release({ message: "User code replaced." });
  });
});
