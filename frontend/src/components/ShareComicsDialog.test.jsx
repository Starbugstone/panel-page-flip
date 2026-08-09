import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ShareComicsDialog } from "./ShareComicsDialog";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

const comics = [
  { id: 1, title: "Batman #1", author: "Writer A", canShare: true, explicitContent: false },
  { id: 2, title: "Superman #1", author: "Writer B", canShare: true, explicitContent: false },
  { id: 3, title: "Received Comic", author: "Writer C", canShare: false, explicitContent: false },
];

const loadPicker = () => {
  vi.mocked(api.get).mockImplementation((url) => {
    if (url === "/api/comics?ownership=mine") return Promise.resolve({ comics });
    if (url === "/api/shares/recent-recipients") {
      return Promise.resolve({ recipients: [{ email: "jane@example.com" }] });
    }
    return Promise.reject(new Error(`Unexpected GET ${url}`));
  });
};

describe("ShareComicsDialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    loadPicker();
  });

  it("only offers owned shareable comics and sender-owned recipient history", async () => {
    render(
      <ShareComicsDialog
        isOpen
        onClose={vi.fn()}
        sharedByMe={[]}
        onShared={vi.fn()}
      />
    );

    expect(await screen.findByText("Batman #1")).toBeInTheDocument();
    expect(screen.getByText("Superman #1")).toBeInTheDocument();
    expect(screen.queryByText("Received Comic")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "jane@example.com" })).toBeInTheDocument();

    expect(api.get).toHaveBeenCalledWith("/api/comics?ownership=mine");
    expect(api.get).toHaveBeenCalledWith("/api/shares/recent-recipients");
  });

  it("marks live shares for the selected recipient instead of creating duplicates", async () => {
    render(
      <ShareComicsDialog
        isOpen
        onClose={vi.fn()}
        initialRecipient="jane@example.com"
        sharedByMe={[{
          comicId: 1,
          recipients: [{
            id: 10,
            recipientEmail: "jane@example.com",
            status: "accepted",
            isExpired: false,
          }],
        }]}
        onShared={vi.fn()}
      />
    );

    await screen.findByText("Batman #1");
    expect(screen.getByText("Already shared")).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: "Select Batman #1" })).toBeDisabled();
    expect(screen.getByRole("checkbox", { name: "Select Superman #1" })).not.toBeDisabled();
  });

  it("submits several comics through the bulk endpoint with the acknowledgement", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    const onShared = vi.fn().mockResolvedValue(undefined);
    vi.mocked(api.post).mockResolvedValue({
      created: 2,
      total: 2,
      results: [
        { comicId: 1, status: "created" },
        { comicId: 2, status: "created" },
      ],
    });

    render(
      <ShareComicsDialog
        isOpen
        onClose={onClose}
        initialRecipient="jane@example.com"
        initialComicIds={[1, 2]}
        sharedByMe={[]}
        onShared={onShared}
      />
    );

    await screen.findByRole("checkbox", { name: "Select Batman #1" });
    await user.click(screen.getByRole("checkbox", { name: "I understand" }));
    await user.click(screen.getByRole("button", { name: "Send 2 invitations" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/invitations/bulk",
      {
        comicIds: [1, 2],
        email: "jane@example.com",
        senderResponsibilityAccepted: true,
      }
    ));
    await waitFor(() => expect(onShared).toHaveBeenCalled());
    expect(onClose).toHaveBeenCalled();
    // One email, however many comics went into it — the wording must not
    // promise the recipient a message each.
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "2 comics shared",
      description: "One invitation email was sent to jane@example.com.",
    }));
  });

  it("reports comics the server refused instead of calling them skipped", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    vi.mocked(api.post).mockResolvedValue({
      created: 1,
      total: 2,
      results: [
        { comicId: 1, status: "created" },
        {
          comicId: 2,
          status: "skipped",
          message: "An invitation is already pending for that person. Resend it instead.",
        },
      ],
    });

    render(
      <ShareComicsDialog
        isOpen
        onClose={onClose}
        initialRecipient="jane@example.com"
        initialComicIds={[1, 2]}
        sharedByMe={[]}
        onShared={vi.fn().mockResolvedValue(undefined)}
      />
    );

    await screen.findByRole("checkbox", { name: "Select Batman #1" });
    await user.click(screen.getByRole("checkbox", { name: "I understand" }));
    await user.click(screen.getByRole("button", { name: "Send 2 invitations" }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Comic shared",
      description: expect.stringContaining("1 comic was left out: An invitation is already pending"),
    })));
    expect(onClose).toHaveBeenCalled();
  });

  it("keeps the dialog open and explains why when nothing was created", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    vi.mocked(api.post).mockResolvedValue({
      created: 0,
      total: 1,
      results: [{
        comicId: 1,
        status: "skipped",
        message: "This comic is already shared with that person.",
      }],
    });

    render(
      <ShareComicsDialog
        isOpen
        onClose={onClose}
        initialRecipient="jane@example.com"
        initialComicIds={[1]}
        sharedByMe={[]}
        onShared={vi.fn()}
      />
    );

    await screen.findByRole("checkbox", { name: "Select Batman #1" });
    await user.click(screen.getByRole("checkbox", { name: "I understand" }));
    await user.click(screen.getByRole("button", { name: "Send invitation" }));

    expect(await screen.findByText("This comic is already shared with that person."))
      .toBeInTheDocument();
    expect(onClose).not.toHaveBeenCalled();
    expect(toast).not.toHaveBeenCalled();
  });

  it("closes after a successful share even if refreshing the sharing list fails", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    const onShared = vi.fn().mockRejectedValue(new Error("refresh failed"));
    vi.mocked(api.post).mockResolvedValue({
      created: 1,
      total: 1,
      results: [{ comicId: 1, status: "created" }],
    });

    render(
      <ShareComicsDialog
        isOpen
        onClose={onClose}
        initialRecipient="jane@example.com"
        initialComicIds={[1]}
        sharedByMe={[]}
        onShared={onShared}
      />
    );

    await screen.findByRole("checkbox", { name: "Select Batman #1" });
    await user.click(screen.getByRole("checkbox", { name: "I understand" }));
    await user.click(screen.getByRole("button", { name: "Send invitation" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Invitation sent",
      description: expect.stringContaining("could not refresh"),
    }));
  });
});
