import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminWarnDialog } from "./AdminWarnDialog";
import { api } from "@/lib/api";

const mocks = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));

const renderDialog = (props = {}) => render(
  <AdminWarnDialog
    target={{ userId: 7 }}
    subjectLabel="Jo Reader"
    recipientLabel="Jo Reader"
    onClose={vi.fn()}
    {...props}
  />
);

const messageBox = () => screen.getByLabelText("Message");
const sendButton = () => screen.getByRole("button", { name: "Send warning" });

describe("the warn dialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.post).mockResolvedValue({ message: "Warning sent." });
  });

  it("will not send an empty message", async () => {
    renderDialog();

    expect(sendButton()).toBeDisabled();
    expect(api.post).not.toHaveBeenCalled();
  });

  it("will not send whitespace either", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.type(messageBox(), "    ");

    expect(sendButton()).toBeDisabled();
  });

  it("sends the message against the target it was opened for", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    renderDialog({ onClose });

    await user.type(messageBox(), "Tag your adult material.");
    await user.click(sendButton());

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/admin/warnings", {
      userId: 7,
      message: "Tag your adult material.",
      sendEmail: false,
    }));
    expect(onClose).toHaveBeenCalled();
  });

  it("carries the comic when that is what it was opened for", async () => {
    const user = userEvent.setup();
    renderDialog({ target: { comicId: 42 }, subjectLabel: "Doomed Comic" });

    await user.type(messageBox(), "This needs the 18+ flag.");
    await user.click(sendButton());

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/admin/warnings", {
      comicId: 42,
      message: "This needs the 18+ flag.",
      sendEmail: false,
    }));
  });

  it("asks for an emailed copy only when the box is ticked", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.type(messageBox(), "Read this.");
    await user.click(screen.getByLabelText(/also email them a copy/i));
    await user.click(sendButton());

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/admin/warnings",
      expect.objectContaining({ sendEmail: true })
    ));
  });

  /** A message typed for one account must not follow the dialog to the next. */
  it("starts empty for a new target", async () => {
    const user = userEvent.setup();
    const { rerender } = renderDialog();

    await user.type(messageBox(), "Meant for Jo.");
    expect(messageBox()).toHaveValue("Meant for Jo.");

    rerender(
      <AdminWarnDialog
        target={{ userId: 9 }}
        subjectLabel="Sam Reader"
        recipientLabel="Sam Reader"
        onClose={vi.fn()}
      />
    );

    expect(messageBox()).toHaveValue("");
  });

  it("reports a refusal rather than closing as though it worked", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    vi.mocked(api.post).mockRejectedValue(new Error("You cannot warn yourself."));
    renderDialog({ onClose });

    await user.type(messageBox(), "Note to self.");
    await user.click(sendButton());

    await waitFor(() => expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Could not send the warning",
      description: "You cannot warn yourself.",
      variant: "destructive",
    })));
    expect(onClose).not.toHaveBeenCalled();
  });

  it("says who will receive it", () => {
    renderDialog();

    expect(screen.getByText(/Jo Reader will see this the next time they sign in/)).toBeInTheDocument();
  });
});

describe("the warn dialog sending to several recipients", () => {
  const THREE = [
    { target: { userId: 7 }, label: "Jo Reader" },
    { target: { userId: 8 }, label: "Sam Reader" },
    { target: { userId: 9 }, label: "Al Reader" },
  ];

  const renderBulk = (props = {}) => render(
    <AdminWarnDialog
      targets={THREE}
      subjectLabel="3 users"
      recipientLabel="3 users"
      onClose={vi.fn()}
      {...props}
    />
  );

  const bulkSendButton = () => screen.getByRole("button", { name: "Send 3 warnings" });

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.post).mockResolvedValue({ message: "Warning sent." });
  });

  it("writes the message once and sends it to each of them", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    renderBulk({ onClose });

    await user.type(messageBox(), "Please stop.");
    await user.click(bulkSendButton());

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(3));
    expect(api.post.mock.calls.map(([, body]) => body.userId)).toEqual([7, 8, 9]);
    expect(mocks.toast).toHaveBeenCalledWith({ title: "3 warnings sent" });
    expect(onClose).toHaveBeenCalled();
  });

  /**
   * Closing on a partial run is deliberate: the warnings that landed cannot be
   * unsent, and reopening the dialog with the same list would send them twice.
   */
  it("reports a partial run and still closes", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    vi.mocked(api.post).mockImplementation((_, body) => (
      body.userId === 8
        ? Promise.reject(new Error("That account no longer exists."))
        : Promise.resolve({ message: "Warning sent." })
    ));
    renderBulk({ onClose });

    await user.type(messageBox(), "Please stop.");
    await user.click(bulkSendButton());

    await waitFor(() => expect(mocks.toast).toHaveBeenCalledWith({
      title: "2 of 3 warnings sent",
      description: "Sam Reader: That account no longer exists.",
      variant: "destructive",
    }));
    expect(onClose).toHaveBeenCalled();
  });

  it("keeps the dialog and the typed message when nothing was sent at all", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    vi.mocked(api.post).mockRejectedValue(new Error("Warnings are disabled."));
    renderBulk({ onClose });

    await user.type(messageBox(), "Please stop.");
    await user.click(bulkSendButton());

    await waitFor(() => expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Nothing was sent",
      variant: "destructive",
    })));
    expect(onClose).not.toHaveBeenCalled();
    expect(messageBox()).toHaveValue("Please stop.");
  });

  it("sends the email copy to everybody or to nobody", async () => {
    const user = userEvent.setup();
    renderBulk();

    await user.type(messageBox(), "Please stop.");
    await user.click(screen.getByLabelText("Also email them a copy"));
    await user.click(bulkSendButton());

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(3));
    expect(api.post.mock.calls.every(([, body]) => body.sendEmail === true)).toBe(true);
  });
});

