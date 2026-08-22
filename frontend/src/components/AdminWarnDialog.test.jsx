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
