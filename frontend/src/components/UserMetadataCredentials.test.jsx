import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { UserMetadataCredentials } from "./UserMetadataCredentials";
import { api } from "@/lib/api";

const mocks = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));

const state = (overrides = {}) => ({
  configured: { metron: false, comicvine: false },
  updatedAt: null,
  metadataApiEnabled: true,
  personalCredentialsEnabled: true,
  providers: [
    { key: "metron", label: "Metron", available: false, origin: null, message: "No Metron token is configured." },
    { key: "comicvine", label: "Comic Vine", available: false, origin: null, message: "No Comic Vine API key is configured." },
  ],
  ...overrides,
});

describe("UserMetadataCredentials", () => {
  beforeEach(() => {
    mocks.toast.mockClear();
    vi.mocked(api.get).mockReset().mockResolvedValue(state());
    vi.mocked(api.put).mockReset().mockResolvedValue(state({ configured: { metron: true, comicvine: false } }));
    vi.mocked(api.post).mockReset().mockResolvedValue({ result: { status: "ok", message: "Metron accepted the token." } });
  });

  /** Both providers take a personal token, and neither takes a password. */
  it("offers a token field for each provider and never a password", async () => {
    render(<UserMetadataCredentials />);
    await screen.findByLabelText(/metron api token/i);

    expect(screen.getByLabelText(/comic vine api key/i)).toBeInTheDocument();
    expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();
  });

  /**
   * A browser fills a saved site login into anything that looks like one, and a
   * token nobody chose would be sent straight to the provider.
   */
  it("keeps the browser's saved logins out", async () => {
    render(<UserMetadataCredentials />);
    await screen.findByLabelText(/metron api token/i);

    for (const label of [/metron api token/i, /comic vine api key/i]) {
      const field = screen.getByLabelText(label);
      expect(field, String(label)).toHaveAttribute("autocomplete", "new-password");
      expect(field, String(label)).toHaveAttribute("readonly");
      expect(field, String(label)).toHaveAttribute("data-lpignore", "true");
    }
  });

  it("sends only what was typed", async () => {
    const user = userEvent.setup();
    render(<UserMetadataCredentials />);

    const field = await screen.findByLabelText(/metron api token/i);
    await user.click(field);
    await user.type(field, "typed-by-hand");
    await user.click(screen.getByRole("button", { name: /save tokens/i }));

    await waitFor(() => expect(api.put).toHaveBeenCalledWith(
      "/api/me/metadata-credentials",
      { metronToken: "typed-by-hand" }
    ));
  });

  /** The server never sends a token back, so the field never shows one. */
  it("reports a stored token without displaying it", async () => {
    vi.mocked(api.get).mockResolvedValue(state({ configured: { metron: true, comicvine: false } }));

    render(<UserMetadataCredentials />);

    expect(await screen.findByText("Configured")).toBeInTheDocument();
    expect(screen.getByLabelText(/metron api token/i)).toHaveValue("");
  });

  /** Which provider a search would use, and why not when none would. */
  it("says why no provider would answer", async () => {
    render(<UserMetadataCredentials />);

    expect(await screen.findByText(/no metron token is configured/i)).toBeInTheDocument();
  });

  it("says when a personal token is the one being used", async () => {
    vi.mocked(api.get).mockResolvedValue(state({
      configured: { metron: true, comicvine: false },
      providers: [{ key: "metron", label: "Metron", available: true, origin: "personal", message: "" }],
    }));

    render(<UserMetadataCredentials />);

    expect(await screen.findByText(/ready — your token/i)).toBeInTheDocument();
  });

  describe("when an administrator has withdrawn something", () => {
    it("explains that external lookups are off for this account", async () => {
      vi.mocked(api.get).mockResolvedValue(state({ metadataApiEnabled: false }));

      render(<UserMetadataCredentials />);

      expect(await screen.findByText(/turned off external metadata lookups for your account/i)).toBeInTheDocument();
    });

    /**
     * Switching personal tokens off stops them being used; it does not delete
     * them, and it must not trap somebody's token on this server.
     */
    it("stops new tokens being added but still allows removing one", async () => {
      vi.mocked(api.get).mockResolvedValue(state({
        personalCredentialsEnabled: false,
        configured: { metron: true, comicvine: false },
      }));

      render(<UserMetadataCredentials />);

      expect(await screen.findByText(/does not accept personal provider tokens/i)).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /save tokens/i })).toBeDisabled();
      expect(screen.getByLabelText(/metron api token/i)).toBeDisabled();
      // The one thing that stays available.
      expect(screen.getByRole("button", { name: /^remove$/i })).toBeEnabled();
    });
  });

  it("reports what a test said without echoing the token", async () => {
    const user = userEvent.setup();
    render(<UserMetadataCredentials />);

    await user.click((await screen.findAllByRole("button", { name: /^test$/i }))[0]);

    expect(await screen.findByText(/metron accepted the token/i)).toBeInTheDocument();
  });

  it("survives a panel that cannot load", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("nope"));

    render(<UserMetadataCredentials />);

    expect(await screen.findByText("nope")).toBeInTheDocument();
  });
});
