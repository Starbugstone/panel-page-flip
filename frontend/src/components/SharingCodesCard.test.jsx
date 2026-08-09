import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SharingCodesCard } from "./SharingCodesCard";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

const liveCode = {
  id: 3,
  comicTitles: ["Batman #1"],
  comicCount: 1,
  maxUses: 5,
  usesRemaining: 3,
  timesUsed: 2,
  expiresAt: "2026-08-10T10:00:00+00:00",
  isExpired: false,
  isRevoked: false,
  isRedeemable: true,
  deadReason: null,
};

const stubGets = (codes = []) => {
  vi.mocked(api.get).mockImplementation((url) => {
    if (url === "/api/shares/my-code") {
      return Promise.resolve({ name: "Test Reader", sharingCode: "7RFX-KP3M-Q82D" });
    }
    if (url === "/api/shares/claim-codes") return Promise.resolve({ codes });
    return Promise.reject(new Error(`Unexpected GET ${url}`));
  });
};

const renderCard = (props = {}) => render(
  <SharingCodesCard onRedeemed={vi.fn().mockResolvedValue(undefined)} {...props} />
);

describe("SharingCodesCard", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    stubGets();
  });

  it("shows the account's own permanent code and never offers to change it", async () => {
    renderCard();

    expect(await screen.findByText("7RFX-KP3M-Q82D")).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledWith("/api/shares/my-code");
    // The code is an address, not a credential. Rotating it would break every
    // conversation it was ever pasted into, so nothing here offers to.
    expect(screen.queryByRole("button", { name: /generate|regenerate|new code/i }))
      .not.toBeInTheDocument();
  });

  it("lets the owner withdraw a live code before it would have expired", async () => {
    const user = userEvent.setup();
    stubGets([liveCode]);
    vi.mocked(api.delete).mockResolvedValue({ message: "Sharing code withdrawn." });

    renderCard();

    expect(await screen.findByText("Batman #1")).toBeInTheDocument();
    expect(screen.getByText(/Claimed 2 of 5 uses/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /withdraw the code for batman #1/i }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith("/api/shares/claim-codes/3"));
    // Withdrawing stops the code, not the shares it already produced.
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Sharing code withdrawn",
      description: expect.stringContaining("already claimed a comic keeps it"),
    }));
  });

  it("keeps a dead code listed and offers no way to withdraw it again", async () => {
    stubGets([{
      ...liveCode,
      usesRemaining: 0,
      timesUsed: 5,
      isRedeemable: false,
      deadReason: "used_up",
    }]);

    renderCard();

    // Kept for a month after it dies, because "how many people took it up?" is
    // asked after a code stops working, not while it still does.
    expect(await screen.findByText("Used up")).toBeInTheDocument();
    expect(screen.getByText(/Claimed 5 of 5 uses/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /withdraw/i })).not.toBeInTheDocument();
  });

  it("keeps Redeem disabled until the code is the right shape", async () => {
    const user = userEvent.setup();
    renderCard();

    const redeem = screen.getByRole("button", { name: "Redeem" });
    expect(redeem).toBeDisabled();

    await user.type(screen.getByPlaceholderText("XXXX-XXXX-XXXX"), "7RFXKP3MQ82");
    expect(redeem).toBeDisabled();

    await user.type(screen.getByPlaceholderText("XXXX-XXXX-XXXX"), "D");
    expect(redeem).toBeEnabled();
  });

  it("groups and corrects what somebody types, the way the server will read it", async () => {
    const user = userEvent.setup();
    renderCard();

    const input = screen.getByPlaceholderText("XXXX-XXXX-XXXX");
    // Lowercase, no dashes, and the letters the alphabet leaves out: somebody
    // transcribing a code by hand rather than holding the wrong one.
    await user.type(input, "7rfxkpimq82o");

    expect(input).toHaveValue("7RFX-KP1M-Q820");
  });

  it("redeems a code and reports what arrived", async () => {
    const user = userEvent.setup();
    const onRedeemed = vi.fn().mockResolvedValue(undefined);
    vi.mocked(api.post).mockResolvedValue({
      claimed: 2,
      ownerName: "Alex Owner",
      results: [
        { comicId: 1, status: "claimed" },
        { comicId: 2, status: "claimed" },
      ],
    });

    renderCard({ onRedeemed });

    await user.type(screen.getByPlaceholderText("XXXX-XXXX-XXXX"), "7RFXKP3MQ82D");
    await user.click(screen.getByRole("button", { name: "Redeem" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/claim-codes/redeem",
      { code: "7RFXKP3MQ82D" }
    ));
    expect(onRedeemed).toHaveBeenCalled();
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: "2 comics added" }));
  });

  it("says an explicit comic still needs an age confirmation", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      claimed: 2,
      ownerName: "Alex Owner",
      results: [
        { comicId: 1, status: "claimed" },
        { comicId: 2, status: "awaiting_age_confirmation" },
      ],
    });

    renderCard();

    await user.type(screen.getByPlaceholderText("XXXX-XXXX-XXXX"), "7RFXKP3MQ82D");
    await user.click(screen.getByRole("button", { name: "Redeem" }));

    // Redeeming stands in for accepting, but never for declaring an age.
    await waitFor(() => expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      description: expect.stringContaining("age confirmed"),
    })));
  });

  it("does not call a code that added nothing a success", async () => {
    const user = userEvent.setup();
    const onRedeemed = vi.fn().mockResolvedValue(undefined);
    vi.mocked(api.post).mockResolvedValue({
      claimed: 0,
      ownerName: "Alex Owner",
      results: [{ comicId: 1, status: "already_yours", message: "You already have this comic." }],
    });

    renderCard({ onRedeemed });

    await user.type(screen.getByPlaceholderText("XXXX-XXXX-XXXX"), "7RFXKP3MQ82D");
    await user.click(screen.getByRole("button", { name: "Redeem" }));

    // A spent code that changed nothing is not "0 comics added".
    expect(await screen.findByText("You already have this comic.")).toBeInTheDocument();
    expect(toast).not.toHaveBeenCalled();
    expect(screen.getByPlaceholderText("XXXX-XXXX-XXXX")).toHaveValue("7RFX-KP3M-Q82D");
  });

  it("explains a code that cannot be redeemed without clearing what was typed", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue(
      new Error("That sharing code is not valid, or has already been used up.")
    );

    renderCard();

    await user.type(screen.getByPlaceholderText("XXXX-XXXX-XXXX"), "7RFXKP3MQ82D");
    await user.click(screen.getByRole("button", { name: "Redeem" }));

    expect(await screen.findByText(/already been used up/)).toBeInTheDocument();
    expect(screen.getByPlaceholderText("XXXX-XXXX-XXXX")).toHaveValue("7RFX-KP3M-Q82D");
  });
});
