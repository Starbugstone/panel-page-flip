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
  type: "C",
  comicTitles: ["Batman #1"],
  comicCount: 1,
  issuedComicCount: 1,
  maxUses: 5,
  usesRemaining: 3,
  timesUsed: 2,
  expiresAt: "2026-08-10T10:00:00+00:00",
  isExpired: false,
  isRevoked: false,
  isRedeemable: true,
  deadReason: null,
  canReveal: true,
};

const stubGets = (codes = [], reveals = {}) => {
  vi.mocked(api.get).mockImplementation((url) => {
    if (url === "/api/shares/user-code") {
      return Promise.resolve({ name: "Test Reader", username: "TestReader1234", label: "Test Reader (@TestReader1234)", userCode: "U-7RFX-KP3M-Q82D" });
    }
    if (url === "/api/shares/content-codes") return Promise.resolve({ codes });
    const reveal = url.match(/^\/api\/shares\/content-codes\/(\d+)\/reveal$/);
    if (reveal) {
      const answer = reveals[reveal[1]];
      return answer instanceof Error ? Promise.reject(answer) : Promise.resolve({ code: answer });
    }
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

  it("shows the account's own code", async () => {
    renderCard();

    expect(await screen.findByText("U-7RFX-KP3M-Q82D")).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledWith("/api/shares/user-code");
  });

  it("lets its single mobile grid column shrink to the card width", async () => {
    renderCard();

    await screen.findByText("U-7RFX-KP3M-Q82D");
    const content = screen.getByRole("heading", { name: "Your identity" }).closest(".grid");
    expect(content).toHaveClass("grid-cols-1");
  });

  it("asks before replacing a code, because the old one breaks everywhere at once", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({ name: "Test Reader", username: "TestReader1234", label: "Test Reader (@TestReader1234)", userCode: "U-83AY-GXKP-SNSY" });

    renderCard();
    await screen.findByText("U-7RFX-KP3M-Q82D");

    await user.click(screen.getByRole("button", { name: /replace your user code/i }));

    // The consequence is stated before it happens, not explained afterwards.
    expect(await screen.findByText(/The old one stops working immediately/)).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Replace my code" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/shares/user-code/rotate", {}));
    // The new code replaces the old one on screen without a reload.
    expect(await screen.findByText("U-83AY-GXKP-SNSY")).toBeInTheDocument();
    expect(screen.queryByText("U-7RFX-KP3M-Q82D")).not.toBeInTheDocument();
  });

  it("keeps the old code when the rotation is cancelled", async () => {
    const user = userEvent.setup();
    renderCard();
    await screen.findByText("U-7RFX-KP3M-Q82D");

    await user.click(screen.getByRole("button", { name: /replace your user code/i }));
    await user.click(screen.getByRole("button", { name: "Cancel" }));

    expect(api.post).not.toHaveBeenCalled();
    expect(screen.getByText("U-7RFX-KP3M-Q82D")).toBeInTheDocument();
  });

  it("lets the owner withdraw a live code before it would have expired", async () => {
    const user = userEvent.setup();
    stubGets([liveCode]);
    vi.mocked(api.delete).mockResolvedValue({ message: "Sharing code withdrawn." });

    renderCard();

    expect(await screen.findByText("Batman #1")).toBeInTheDocument();
    expect(screen.getByText(/Claimed 2 of 5 uses/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /withdraw the code for batman #1/i }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith("/api/shares/content-codes/3"));
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

    await user.type(screen.getByPlaceholderText(/^C-XXXX/), "C7RFXKP3MQ82");
    expect(redeem).toBeDisabled();

    await user.type(screen.getByPlaceholderText(/^C-XXXX/), "D");
    expect(redeem).toBeEnabled();
  });

  it("groups and corrects what somebody types, the way the server will read it", async () => {
    const user = userEvent.setup();
    renderCard();

    const input = screen.getByPlaceholderText(/^C-XXXX/);
    // Lowercase, no dashes, and the letters the alphabet leaves out: somebody
    // transcribing a code by hand rather than holding the wrong one.
    await user.type(input, "c7rfxkpimq82o");

    expect(input).toHaveValue("C-7RFX-KP1M-Q820");
  });

  it("redeems a code and reports what arrived", async () => {
    const user = userEvent.setup();
    const onRedeemed = vi.fn().mockResolvedValue(undefined);
    vi.mocked(api.post).mockResolvedValue({
      claimed: 2,
      ownerLabel: "Alex Owner",
      results: [
        { comicId: 1, status: "claimed" },
        { comicId: 2, status: "claimed" },
      ],
    });

    renderCard({ onRedeemed });

    await user.type(screen.getByPlaceholderText(/^C-XXXX/), "C7RFXKP3MQ82D");
    await user.click(screen.getByRole("button", { name: "Redeem" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/content-codes/redeem",
      // Sent in the canonical form, however it was typed.
      { code: "C-7RFX-KP3M-Q82D" }
    ));
    expect(onRedeemed).toHaveBeenCalled();
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: "2 comics added" }));
  });

  it("says an explicit comic still needs an age confirmation", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      claimed: 2,
      ownerLabel: "Alex Owner",
      results: [
        { comicId: 1, status: "claimed" },
        { comicId: 2, status: "awaiting_age_confirmation" },
      ],
    });

    renderCard();

    await user.type(screen.getByPlaceholderText(/^C-XXXX/), "C7RFXKP3MQ82D");
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
      ownerLabel: "Alex Owner",
      results: [{ comicId: 1, status: "already_yours", message: "You already have this comic." }],
    });

    renderCard({ onRedeemed });

    await user.type(screen.getByPlaceholderText(/^C-XXXX/), "C7RFXKP3MQ82D");
    await user.click(screen.getByRole("button", { name: "Redeem" }));

    // A spent code that changed nothing is not "0 comics added".
    expect(await screen.findByText("You already have this comic.")).toBeInTheDocument();
    expect(toast).not.toHaveBeenCalled();
    expect(screen.getByPlaceholderText(/^C-XXXX/)).toHaveValue("C-7RFX-KP3M-Q82D");
  });

  it("explains a code that cannot be redeemed without clearing what was typed", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue(
      new Error("That sharing code is not valid, or has already been used up.")
    );

    renderCard();

    await user.type(screen.getByPlaceholderText(/^C-XXXX/), "C7RFXKP3MQ82D");
    await user.click(screen.getByRole("button", { name: "Redeem" }));

    expect(await screen.findByText(/already been used up/)).toBeInTheDocument();
    expect(screen.getByPlaceholderText(/^C-XXXX/)).toHaveValue("C-7RFX-KP3M-Q82D");
  });

  /**
   * A failed identity load must say so.
   *
   * Without it the panel sits on its placeholder code with copy and rotate
   * both disabled, which looks exactly like a slow request and gives somebody
   * nothing to act on.
   */
  it("says when the identity could not be loaded", async () => {
    vi.mocked(api.get).mockImplementation((url) => {
      if (url === "/api/shares/user-code") return Promise.reject(new Error("offline"));
      if (url === "/api/shares/content-codes") return Promise.resolve({ codes: [] });
      return Promise.reject(new Error(`Unexpected GET ${url}`));
    });

    renderCard();

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Copy your user code" })).toBeDisabled();
  });
});

/**
 * A code lives in the message its owner sent it in, and that message gets lost.
 * Before this, the only way back was to withdraw a live code and mint another.
 */
describe("reading a handed-out code back", () => {
  beforeEach(() => vi.clearAllMocks());

  it("does not show a code until it is asked for", async () => {
    stubGets([liveCode], { 3: "C-8XKM-2PQR-4TVW" });
    renderCard();

    expect(await screen.findByRole("button", { name: /show the code for Batman #1/i })).toBeInTheDocument();
    expect(screen.queryByText("C-8XKM-2PQR-4TVW")).not.toBeInTheDocument();
    expect(api.get).not.toHaveBeenCalledWith("/api/shares/content-codes/3/reveal");
  });

  it("shows it when it is", async () => {
    const user = userEvent.setup();
    stubGets([liveCode], { 3: "C-8XKM-2PQR-4TVW" });
    renderCard();

    await user.click(await screen.findByRole("button", { name: /show the code for Batman #1/i }));

    expect(await screen.findByText("C-8XKM-2PQR-4TVW")).toBeInTheDocument();
  });

  it("puts it away again without asking twice", async () => {
    const user = userEvent.setup();
    stubGets([liveCode], { 3: "C-8XKM-2PQR-4TVW" });
    renderCard();

    await user.click(await screen.findByRole("button", { name: /show the code/i }));
    await screen.findByText("C-8XKM-2PQR-4TVW");
    await user.click(screen.getByRole("button", { name: /hide the code/i }));

    await waitFor(() => expect(screen.queryByText("C-8XKM-2PQR-4TVW")).not.toBeInTheDocument());
    expect(api.get.mock.calls.filter(([url]) => url === "/api/shares/content-codes/3/reveal")).toHaveLength(1);
  });

  /** A code from before the column existed has nothing behind the button. */
  it("offers nothing to show for a code that cannot be read back", async () => {
    stubGets([{ ...liveCode, canReveal: false }]);
    renderCard();

    await screen.findByText(/Batman #1/);
    expect(screen.queryByRole("button", { name: /show the code/i })).not.toBeInTheDocument();
  });

  it("says so when the code cannot be fetched", async () => {
    const user = userEvent.setup();
    stubGets([liveCode], { 3: new Error("Rate limited.") });
    renderCard();

    await user.click(await screen.findByRole("button", { name: /show the code/i }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Could not show the code",
      variant: "destructive",
    })));
  });
});
