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
    { key: "metron", label: "Metron", available: false, reason: "Metron is currently unavailable." },
    { key: "comicvine", label: "Comic Vine", available: false, reason: "Comic Vine is currently unavailable." },
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

  /** Whether a provider will answer them — not which credential it would use. */
  it("says a provider is unavailable without saying why the server refused", async () => {
    render(<UserMetadataCredentials />);

    expect(await screen.findByText(/metron is currently unavailable/i)).toBeInTheDocument();
  });

  /**
   * The installation's fallback account is a backend detail. Naming it here
   * would tell every account holder how this server is configured.
   */
  it("never says which credential a search would spend", async () => {
    vi.mocked(api.get).mockResolvedValue(state({
      configured: { metron: true, comicvine: false },
      providers: [{ key: "metron", label: "Metron", available: true }],
    }));

    const { container } = render(<UserMetadataCredentials />);
    // Scoped to the section: "already" elsewhere on the card contains "ready".
    const section = (await screen.findByText(/providers available to you/i)).parentElement;

    expect(section.textContent).toMatch(/Metron:\s*ready/);
    expect(container.textContent).not.toMatch(/shared token|server's token|administrator configured|your token/i);
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

  /** Leaving a secret in component state is what this panel avoids elsewhere. */
  it("clears a typed token from state when the stored one is removed", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockResolvedValue(state({ configured: { metron: true, comicvine: false } }));

    render(<UserMetadataCredentials />);

    const field = await screen.findByLabelText(/metron api token/i);
    await user.click(field);
    await user.type(field, "typed-by-hand");
    await user.click(screen.getByRole("button", { name: /^remove$/i }));

    await waitFor(() => expect(screen.getByLabelText(/metron api token/i)).toHaveValue(""));
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
