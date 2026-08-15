import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ShareComicModal } from "./ShareComicModal";
import { api } from "@/lib/api";
import { SHARE_RESPONSIBILITY_NOTICE } from "@/lib/sharing";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { post: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() } }));

const renderModal = (props = {}) => render(
  <MemoryRouter>
    <ShareComicModal
      isOpen
      onClose={() => {}}
      comicId={7}
      comicTitle="Sandman"
      {...props}
    />
  </MemoryRouter>
);

const sendButton = () => screen.getByRole("button", { name: /send invitation/i });
const acknowledgement = () => screen.getByRole("checkbox", { name: /necessary rights or authorization/i });

describe("ShareComicModal", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.post).mockReset();
    vi.mocked(api.post).mockResolvedValue({ invitationUrl: "https://example.test/share/invitation/abc" });
  });

  it("keeps a successful invitation distinct from a failed refresh", async () => {
    const user = userEvent.setup();
    const onShared = vi.fn().mockRejectedValue(new Error("refresh failed"));
    renderModal({ onShared });

    await user.type(screen.getByLabelText(/recipient email/i), "jane@example.com");
    await user.click(acknowledgement());
    await user.click(sendButton());

    // The invitation exists, so the one-time link is still offered and nothing
    // tells the sender to try again.
    expect(await screen.findByDisplayValue("https://example.test/share/invitation/abc"))
      .toBeInTheDocument();
    expect(toast).not.toHaveBeenCalledWith(expect.objectContaining({
      title: "Could not share comic",
    }));
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      description: expect.stringContaining("could not refresh"),
    }));
  });

  it("states that the sender is responsible for what they share", () => {
    renderModal();

    // Asserted against the exported copy rather than a paraphrase, so the
    // notice cannot be watered down in the component without this failing.
    expect(screen.getByText(SHARE_RESPONSIBILITY_NOTICE)).toBeInTheDocument();
    expect(screen.getByText(SHARE_RESPONSIBILITY_NOTICE))
      .toHaveTextContent("Explicit content (18+)");
  });

  it("starts with the acknowledgement unticked", () => {
    renderModal();

    expect(acknowledgement()).not.toBeChecked();
    expect(sendButton()).toBeDisabled();
  });

  it("keeps Send disabled while only one of the two conditions is met", async () => {
    const user = userEvent.setup();
    renderModal();

    await user.type(screen.getByLabelText(/recipient email/i), "jane@example.com");
    // A well-formed address is not consent.
    expect(sendButton()).toBeDisabled();

    await user.clear(screen.getByLabelText(/recipient email/i));
    await user.click(acknowledgement());
    expect(acknowledgement()).toBeChecked();
    // And consent is not an address.
    expect(sendButton()).toBeDisabled();
  });

  it("enables Send only once the address is valid and the box is ticked", async () => {
    const user = userEvent.setup();
    renderModal();

    await user.type(screen.getByLabelText(/recipient email/i), "jane@example.com");
    await user.click(acknowledgement());

    expect(sendButton()).toBeEnabled();
  });

  it("sends the acknowledgement with the invitation", async () => {
    const user = userEvent.setup();
    renderModal();

    await user.type(screen.getByLabelText(/recipient email/i), "  jane@example.com  ");
    await user.click(acknowledgement());
    await user.click(sendButton());

    expect(api.post).toHaveBeenCalledWith(
      "/api/shares/comics/7/invitations",
      { email: "jane@example.com", senderResponsibilityAccepted: true }
    );
  });

  it("cannot send without the acknowledgement even if the button is reached anyway", async () => {
    const user = userEvent.setup();
    renderModal();

    await user.type(screen.getByLabelText(/recipient email/i), "jane@example.com");
    // Firing the click on a disabled button is what a stale render or a
    // programmatic caller would do; the handler has to refuse on its own.
    await user.click(sendButton());

    expect(api.post).not.toHaveBeenCalled();
  });

  it("unticks the acknowledgement when the modal is opened for another comic", async () => {
    const user = userEvent.setup();
    const { rerender } = renderModal();

    await user.click(acknowledgement());
    expect(acknowledgement()).toBeChecked();

    // The acknowledgement is about this comic going to this person. Carrying it
    // into the next share would record an agreement nobody made.
    rerender(
      <MemoryRouter>
        <ShareComicModal isOpen onClose={() => {}} comicId={8} comicTitle="Preacher" />
      </MemoryRouter>
    );

    expect(acknowledgement()).not.toBeChecked();
    expect(sendButton()).toBeDisabled();
  });
  it("unticks the acknowledgement when the same comic is shared again", async () => {
    const user = userEvent.setup();
    const { rerender } = renderModal();

    await user.click(acknowledgement());
    expect(acknowledgement()).toBeChecked();

    const at = (isOpen) => (
      <MemoryRouter>
        <ShareComicModal isOpen={isOpen} onClose={() => {}} comicId={7} comicTitle="Sandman" />
      </MemoryRouter>
    );

    // Closing and immediately reopening for the *same* comic is the case the
    // old delayed wipe could not cover: it cleared the fields 300ms after the
    // close, and reopening first cancelled that timer, leaving the agreement
    // ticked for a share nobody had agreed to. Reopening is a new form now, so
    // there is no timer left to lose the race.
    rerender(at(false));
    rerender(at(true));

    expect(acknowledgement()).not.toBeChecked();
    expect(sendButton()).toBeDisabled();
  });

  it("does not carry a typed recipient into the next share", async () => {
    const user = userEvent.setup();
    const { rerender } = renderModal();

    await user.type(screen.getByLabelText(/email/i), "someone@example.test");

    const at = (isOpen) => (
      <MemoryRouter>
        <ShareComicModal isOpen={isOpen} onClose={() => {}} comicId={7} comicTitle="Sandman" />
      </MemoryRouter>
    );
    rerender(at(false));
    rerender(at(true));

    expect(screen.getByLabelText(/email/i)).toHaveValue("");
  });
});
