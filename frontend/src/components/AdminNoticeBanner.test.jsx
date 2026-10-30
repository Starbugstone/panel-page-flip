import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminNoticeBanner } from "./AdminNoticeBanner";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));

const notice = (overrides = {}) => ({
  id: 1,
  message: "Please tag adult material before you share it.",
  subject: "account",
  subjectLabel: null,
  createdAt: "2026-08-20T09:00:00+00:00",
  ...overrides,
});

const stubNotices = (warnings) => {
  vi.mocked(api.get).mockResolvedValue({ warnings });
};

describe("the administrator notice banner", () => {
  beforeEach(() => vi.clearAllMocks());

  it("shows nothing when there is nothing waiting", async () => {
    stubNotices([]);
    const { container } = render(<AdminNoticeBanner accountId={1} />);

    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/me/warnings"));
    expect(container).toBeEmptyDOMElement();
  });

  it("asks for nothing at all when nobody is signed in", () => {
    render(<AdminNoticeBanner accountId={null} />);

    expect(api.get).not.toHaveBeenCalled();
  });

  it("shows the message it was sent", async () => {
    stubNotices([notice()]);
    render(<AdminNoticeBanner accountId={1} />);

    expect(await screen.findByText(/please tag adult material/i)).toBeInTheDocument();
    expect(screen.getByText("A notice about your account")).toBeInTheDocument();
  });

  /** A message about one comic has to say which, especially once it is gone. */
  it("names the comic a notice is about", async () => {
    stubNotices([notice({ subject: "comic", subjectLabel: "Doomed Comic" })]);
    render(<AdminNoticeBanner accountId={1} />);

    expect(await screen.findByText(/A notice about your comic “Doomed Comic”/)).toBeInTheDocument();
  });

  it("names the shared comic a notice is about", async () => {
    stubNotices([notice({ subject: "share", subjectLabel: "Shared Comic" })]);
    render(<AdminNoticeBanner accountId={1} />);

    expect(await screen.findByText(/A notice about a comic you shared: “Shared Comic”/))
      .toBeInTheDocument();
  });

  it("dismisses one notice without touching the others", async () => {
    const user = userEvent.setup();
    stubNotices([notice(), notice({ id: 2, message: "A second thing." })]);
    vi.mocked(api.post).mockResolvedValue({ message: "Notice dismissed." });
    render(<AdminNoticeBanner accountId={1} />);

    await screen.findByText(/please tag adult material/i);
    await user.click(screen.getAllByRole("button", { name: /dismiss notice/i })[0]);

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/me/warnings/1/acknowledge", {}));
    await waitFor(() => expect(screen.queryByText(/please tag adult material/i)).not.toBeInTheDocument());
    expect(screen.getByText("A second thing.")).toBeInTheDocument();
  });

  /**
   * A notice the server still considers open comes back on the next page load,
   * so hiding it optimistically would only make it flicker.
   */
  it("leaves a notice on screen when dismissing it fails", async () => {
    const user = userEvent.setup();
    stubNotices([notice()]);
    vi.mocked(api.post).mockRejectedValue(new Error("nope"));
    render(<AdminNoticeBanner accountId={1} />);

    await screen.findByText(/please tag adult material/i);
    await user.click(screen.getByRole("button", { name: /dismiss notice/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalled());
    expect(screen.getByText(/please tag adult material/i)).toBeInTheDocument();
  });

  /** An error where a warning would go reads as the warning. */
  it("shows nothing at all when the notices cannot be fetched", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("offline"));
    const { container } = render(<AdminNoticeBanner accountId={1} />);

    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  /** Signing out and back in as somebody else stays in the app, in one tab. */
  it("takes one account's notices off screen as soon as another signs in", async () => {
    stubNotices([notice({ message: "For the first account." })]);
    const { rerender } = render(<AdminNoticeBanner accountId={1} />);
    await screen.findByText("For the first account.");

    rerender(<AdminNoticeBanner accountId={null} />);
    vi.mocked(api.get).mockReturnValue(new Promise(() => {}));
    rerender(<AdminNoticeBanner accountId={2} />);

    expect(screen.queryByText("For the first account.")).not.toBeInTheDocument();
  });

  it("goes straight from one account's notices to the next", async () => {
    stubNotices([notice({ message: "For the first account." })]);
    const { rerender } = render(<AdminNoticeBanner accountId={1} />);
    await screen.findByText("For the first account.");

    stubNotices([notice({ id: 9, message: "For the second account." })]);
    rerender(<AdminNoticeBanner accountId={2} />);

    expect(screen.queryByText("For the first account.")).not.toBeInTheDocument();
    expect(await screen.findByText("For the second account.")).toBeInTheDocument();
  });

  it("keeps the next account clear when its own notices cannot be fetched", async () => {
    stubNotices([notice({ message: "For the first account." })]);
    const { container, rerender } = render(<AdminNoticeBanner accountId={1} />);
    await screen.findByText("For the first account.");

    vi.mocked(api.get).mockRejectedValue(new Error("offline"));
    rerender(<AdminNoticeBanner accountId={2} />);

    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2));
    expect(container).toBeEmptyDOMElement();
  });

  it("drops a reply that arrives after its account has signed out", async () => {
    let answerFirstAccount;
    vi.mocked(api.get).mockReturnValueOnce(new Promise((resolve) => { answerFirstAccount = resolve; }));
    const { container, rerender } = render(<AdminNoticeBanner accountId={1} />);

    stubNotices([]);
    rerender(<AdminNoticeBanner accountId={2} />);
    await act(async () => answerFirstAccount({ warnings: [notice({ message: "For the first account." })] }));

    expect(container).toBeEmptyDOMElement();
  });

  it("does not let a dismissal from the last account touch the next one's notices", async () => {
    const user = userEvent.setup();
    stubNotices([notice({ message: "For the first account." })]);
    let finishDismissal;
    vi.mocked(api.post).mockReturnValue(new Promise((resolve) => { finishDismissal = resolve; }));
    const { rerender } = render(<AdminNoticeBanner accountId={1} />);
    await screen.findByText("For the first account.");
    await user.click(screen.getByRole("button", { name: /dismiss notice/i }));

    // The same id on purpose: the guard is the account, not the notice number.
    stubNotices([notice({ message: "For the second account." })]);
    rerender(<AdminNoticeBanner accountId={2} />);
    await screen.findByText("For the second account.");
    await act(async () => finishDismissal({}));

    expect(screen.getByText("For the second account.")).toBeInTheDocument();
  });
});
