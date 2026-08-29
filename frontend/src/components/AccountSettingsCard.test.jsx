import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AccountSettingsCard } from "./AccountSettingsCard";
import { api } from "@/lib/api";

const mocks = vi.hoisted(() => ({
  toast: vi.fn(),
  checkAuth: vi.fn().mockResolvedValue(undefined),
  user: { id: 1, email: "reader@example.com", username: "TestReader1234" },
}));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), put: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/hooks/use-auth", () => ({
  useAuth: () => ({ user: mocks.user, checkAuth: mocks.checkAuth }),
}));

const GIB = 1024 ** 3;

const stubStorage = (usage = {
  comicCount: 25,
  storageUsedBytes: 8.3 * GIB,
  storageQuotaBytes: 10 * GIB,
  unmeasuredComicCount: 0,
}) => {
  vi.mocked(api.get).mockImplementation((url) => (
    url === "/api/me/storage"
      ? Promise.resolve(usage)
      : Promise.reject(new Error(`Unexpected GET ${url}`))
  ));
};

const usernameField = () => screen.getByLabelText("Username");
const saveButton = () => screen.getByRole("button", { name: /change username/i });

describe("the account settings card", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.user = { id: 1, email: "reader@example.com", username: "TestReader1234" };
    stubStorage();
  });

  describe("storage", () => {
    /**
     * The whole point: an upload refused for want of space used to be the first
     * an account heard that it had a quota at all.
     */
    it("shows how much of the quota is gone", async () => {
      render(<AccountSettingsCard />);

      expect(await screen.findByText("83.0%")).toBeInTheDocument();
      expect(screen.getByText("25 comics", { exact: false })).toBeInTheDocument();
    });

    it("says comics shared with you are not yours to pay for", async () => {
      render(<AccountSettingsCard />);

      expect(await screen.findByText(/do not count against your quota/i)).toBeInTheDocument();
    });

    it("says so rather than showing an empty bar when the figures do not arrive", async () => {
      vi.mocked(api.get).mockRejectedValue(new Error("nope"));
      render(<AccountSettingsCard />);

      expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
      expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
    });
  });

  describe("the username", () => {
    it("starts on the one the account already has", async () => {
      render(<AccountSettingsCard />);

      await waitFor(() => expect(usernameField()).toHaveValue("TestReader1234"));
      expect(saveButton()).toBeDisabled();
    });

    it("saves a new one and refreshes the account behind it", async () => {
      const user = userEvent.setup();
      vi.mocked(api.put).mockResolvedValue({ username: "PanelFan" });
      render(<AccountSettingsCard />);
      await waitFor(() => expect(usernameField()).toHaveValue("TestReader1234"));

      await user.clear(usernameField());
      await user.type(usernameField(), "PanelFan");
      await user.click(saveButton());

      await waitFor(() => expect(api.put).toHaveBeenCalledWith(
        "/api/users/username",
        { username: "PanelFan" }
      ));
      expect(mocks.checkAuth).toHaveBeenCalled();
      expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({
        title: "Username changed",
        description: expect.stringContaining("Your U- code still works"),
      }));
      expect(screen.getByText(/your U- code stays the same/i)).toBeInTheDocument();
    });

    /** The @ is how a username is written, not part of it. */
    it("sends the handle without its prefix", async () => {
      const user = userEvent.setup();
      vi.mocked(api.put).mockResolvedValue({ username: "PanelFan" });
      render(<AccountSettingsCard />);
      await waitFor(() => expect(usernameField()).toHaveValue("TestReader1234"));

      await user.clear(usernameField());
      await user.type(usernameField(), "@PanelFan");
      await user.click(saveButton());

      await waitFor(() => expect(api.put).toHaveBeenCalledWith(
        "/api/users/username",
        { username: "PanelFan" }
      ));
    });

    it("refuses a name the server would refuse, before asking it", async () => {
      const user = userEvent.setup();
      render(<AccountSettingsCard />);
      await waitFor(() => expect(usernameField()).toHaveValue("TestReader1234"));

      await user.clear(usernameField());
      await user.type(usernameField(), "no spaces here");

      expect(await screen.findByText(/letters, numbers, hyphens and underscores/i)).toBeInTheDocument();
      expect(saveButton()).toBeDisabled();
      expect(api.put).not.toHaveBeenCalled();
    });

    it("reports a refusal from the server rather than pretending it saved", async () => {
      const user = userEvent.setup();
      vi.mocked(api.put).mockRejectedValue(new Error("That username is already taken."));
      render(<AccountSettingsCard />);
      await waitFor(() => expect(usernameField()).toHaveValue("TestReader1234"));

      await user.clear(usernameField());
      await user.type(usernameField(), "PanelFan");
      await user.click(saveButton());

      await waitFor(() => expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({
        title: "Could not change your username",
        description: "That username is already taken.",
        variant: "destructive",
      })));
    });
  });
});
