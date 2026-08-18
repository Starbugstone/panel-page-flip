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

const recipients = [
  {
    email: null,
    username: "SilverOtter4821",
    userCode: "U-7RFX-KP3M-Q82D",
    name: "Jane Reader",
    label: "Jane Reader (@SilverOtter4821)",
  },
  { email: "nobody@example.com", username: null, userCode: null, name: null, label: "nobody@example.com" },
];

const loadPicker = () => {
  vi.mocked(api.get).mockImplementation((url) => {
    if (url === "/api/comics?ownership=mine") return Promise.resolve({ comics });
    if (url === "/api/shares/recent-recipients") return Promise.resolve({ recipients });
    return Promise.reject(new Error(`Unexpected GET ${url}`));
  });
};

const open = (props = {}) => render(
  <ShareComicsDialog isOpen onClose={vi.fn()} sharedByMe={[]} onShared={vi.fn()} {...props} />
);

/** Tick the acknowledgement, which every share requires. */
const acknowledge = async (user) => {
  await user.click(screen.getByLabelText(/I confirm that I have the necessary rights/i));
};

const resolvedRecipient = {
  username: "SilverOtter4821",
  name: "Jane Reader",
  label: "Jane Reader (@SilverOtter4821)",
};

/**
 * Answer the resolve call with a known identity and the share call with a
 * successful result, so a test can do both without re-mocking in between.
 */
const answerResolveAndShare = (shareResult = { created: 1, total: 1, results: [{ comicId: 1, status: "created" }] }) => {
  vi.mocked(api.post).mockImplementation((url) => {
    if (url === "/api/users/resolve-username" || url === "/api/shares/user-code/resolve") {
      return Promise.resolve({ recipient: resolvedRecipient });
    }

    return Promise.resolve(shareResult);
  });
};

/**
 * Name a recipient the way the dialog requires: type the identifier, press
 * Check, and wait for the identity to come back.
 *
 * Not optional, and that is the point. A username or a `U-` code names an
 * account the sender cannot see — a code they cannot even read — so a typo
 * reaches a real stranger rather than failing. Sending before the identity is
 * on screen is sharing with somebody nobody has looked at.
 */
const confirmRecipient = async (user, { username, userCode }) => {
  if (userCode) {
    await user.click(screen.getByRole("tab", { name: "U- code" }));
    await user.type(screen.getByLabelText(/Their U- code/i), userCode);
  } else {
    await user.type(screen.getByLabelText(/Their username/i), username);
  }

  await user.click(screen.getByRole("button", { name: "Check" }));
  await screen.findByText(/^Sharing with/);
};

describe("ShareComicsDialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    loadPicker();
  });

  /* ------------------------------------------------------------------------ */
  /* Choosing comics                                                           */
  /* ------------------------------------------------------------------------ */

  it("only offers owned shareable comics and sender-owned recipient history", async () => {
    open();

    expect(await screen.findByText("Batman #1")).toBeInTheDocument();
    expect(screen.getByText("Superman #1")).toBeInTheDocument();
    // A comic somebody shared with you cannot be passed on.
    expect(screen.queryByText("Received Comic")).not.toBeInTheDocument();

    // Registered recipients are offered by username, never by an address the
    // sender was deliberately not given.
    expect(screen.getByRole("button", { name: "Jane Reader (@SilverOtter4821)" })).toBeInTheDocument();

    expect(api.get).toHaveBeenCalledWith("/api/comics?ownership=mine");
    expect(api.get).toHaveBeenCalledWith("/api/shares/recent-recipients");
  });

  it("marks live shares for the selected recipient instead of creating duplicates", async () => {
    open({
      initialRecipient: "jane@example.com",
      sharedByMe: [{
        comicId: 1,
        recipients: [{ id: 10, recipientEmail: "jane@example.com", status: "accepted", isExpired: false }],
      }],
    });

    await screen.findByText("Batman #1");
    expect(screen.getByText("Already shared")).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: "Select Batman #1" })).toBeDisabled();
    expect(screen.getByRole("checkbox", { name: "Select Superman #1" })).not.toBeDisabled();
  });

  /**
   * A caller that has already chosen does not ask again. Reselecting a table
   * selection in a second list is a step that can only go wrong.
   */
  it("keeps a locked selection instead of asking for it twice", async () => {
    open({ initialComicIds: [1, 2], lockSelection: true });

    await screen.findByText("Batman #1");
    expect(screen.getByText("Superman #1")).toBeInTheDocument();
    expect(screen.queryByRole("checkbox", { name: "Select Batman #1" })).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText(/Search your library/i)).not.toBeInTheDocument();
  });

  /* ------------------------------------------------------------------------ */
  /* Naming a recipient                                                        */
  /* ------------------------------------------------------------------------ */

  it("shares by exact username without ever asking for an address", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await confirmRecipient(user, { username: "SilverOtter4821" });
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Send invitation/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/invitations/bulk",
      expect.objectContaining({
        username: "SilverOtter4821",
        comicIds: [1],
        senderResponsibilityAccepted: true,
      })
    ));

    // No address anywhere in the request: that is the point of a username.
    const shareCall = vi.mocked(api.post).mock.calls
      .find(([url]) => url === "/api/shares/invitations/bulk");
    expect(shareCall[1]).not.toHaveProperty("email");
  });

  /**
   * The confirmation is a gate, not a convenience.
   *
   * A syntactically valid handle is not a person. Letting Send light up on one
   * means a mistyped character shares somebody's library with whoever happens
   * to hold that name, and the sender is never shown who that was.
   */
  it("will not send to a username until it has been checked", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await user.type(screen.getByLabelText(/Their username/i), "SilverOtter4821");
    await acknowledge(user);

    const send = screen.getByRole("button", { name: /Send invitation/i });
    expect(send).toBeDisabled();
    expect(screen.getByText(/Check who this is before sending/i)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Check" }));
    await screen.findByText(/^Sharing with/);

    expect(screen.getByRole("button", { name: /Send invitation/i })).not.toBeDisabled();
  });

  it("will not send to a U- code until it has been checked", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await user.click(screen.getByRole("tab", { name: "U- code" }));
    await user.type(screen.getByLabelText(/Their U- code/i), "u7rfxkp3mq82d");
    await acknowledge(user);

    expect(screen.getByRole("button", { name: /Send invitation/i })).toBeDisabled();
  });

  /**
   * Sharing again with somebody already shared with does not re-ask who they
   * are. The caller opened from a button naming them, and passes that identity
   * in — the same reasoning as a recent recipient, one layer up.
   */
  it("accepts an identity the caller already has", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open({
      initialUsername: "SilverOtter4821",
      initialResolved: resolvedRecipient,
    });
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await acknowledge(user);

    expect(screen.getByText(/^Sharing with/)).toHaveTextContent(
      "Sharing with Jane Reader (@SilverOtter4821)"
    );
    expect(screen.getByRole("button", { name: /Send invitation/i })).not.toBeDisabled();
    expect(api.post).not.toHaveBeenCalledWith("/api/users/resolve-username", expect.anything());
  });

  /**
   * A recent recipient arrives already confirmed, because the label beside the
   * button is the identity a Check would go and fetch — the server produced it
   * from a relationship this owner already has.
   */
  it("treats a recent recipient as already confirmed", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await user.click(screen.getByRole("button", { name: "Jane Reader (@SilverOtter4821)" }));
    await acknowledge(user);

    expect(await screen.findByText(/^Sharing with/)).toHaveTextContent(
      "Sharing with Jane Reader (@SilverOtter4821)"
    );
    expect(screen.getByRole("button", { name: /Send invitation/i })).not.toBeDisabled();

    // And no lookup was needed to get there.
    expect(api.post).not.toHaveBeenCalledWith("/api/users/resolve-username", expect.anything());
  });

  it("checks who a username belongs to before anything is offered to them", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      recipient: { username: "SilverOtter4821", name: "Jane Reader", label: "Jane Reader (@SilverOtter4821)" },
    });

    open();
    await screen.findByText("Batman #1");

    await user.type(screen.getByLabelText(/Their username/i), "SilverOtter4821");
    await user.click(screen.getByRole("button", { name: "Check" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/users/resolve-username",
      { username: "SilverOtter4821" }
    ));
    // Scoped to the confirmation line: the same label is on the recent-recipient
    // button, and matching either would pass without proving anything.
    expect(await screen.findByText(/^Sharing with/)).toHaveTextContent(
      "Sharing with Jane Reader (@SilverOtter4821)"
    );
  });

  it("forgets a checked recipient as soon as the handle is edited", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      recipient: { username: "SilverOtter4821", name: "Jane Reader", label: "Jane Reader (@SilverOtter4821)" },
    });

    open();
    await screen.findByText("Batman #1");

    const field = screen.getByLabelText(/Their username/i);
    await user.type(field, "SilverOtter4821");
    await user.click(screen.getByRole("button", { name: "Check" }));
    await screen.findByText(/^Sharing with/);

    // A changed handle is a different person until checked again. A stale name
    // sitting next to a different one is a confirmation of nothing.
    await user.type(field, "X");
    await waitFor(() => expect(screen.queryByText(/^Sharing with/)).not.toBeInTheDocument());
  });

  it("shares by U- code, sending the canonical form however it was typed", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await confirmRecipient(user, { userCode: "u7rfxkp3mq82d" });
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Send invitation/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/invitations/bulk",
      expect.objectContaining({ userCode: "U-7RFX-KP3M-Q82D" })
    ));
  });

  /**
   * A comic code pasted where a recipient goes is a real code in the wrong box,
   * not a failed guess — so it is explained rather than answered with "invalid".
   */
  it("explains a content code pasted into the recipient field", async () => {
    const user = userEvent.setup();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("tab", { name: "U- code" }));
    await user.type(screen.getByLabelText(/Their U- code/i), "C7RFXKP3MQ82D");

    expect(await screen.findByText(/This is a comic code\. Redeem it under Shared with me\./))
      .toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
  });

  it("still accepts an exact address, for somebody with no account yet", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await user.click(screen.getByRole("tab", { name: "Email address" }));
    await user.type(screen.getByLabelText(/Recipient email/i), "newcomer@example.com");
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Send invitation/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/invitations/bulk",
      expect.objectContaining({ email: "newcomer@example.com" })
    ));
  });

  /* ------------------------------------------------------------------------ */
  /* C- and G-                                                                 */
  /* ------------------------------------------------------------------------ */

  it("creates a C- code for one comic and shows it once", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      code: "C-7RFX-KP3M-Q82D",
      contentCode: { id: 4, type: "C", expiresAt: "2026-08-23T10:00:00+00:00" },
    });

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await user.click(screen.getByRole("tab", { name: "Create a code" }));
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Create comic code/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/comic-codes",
      expect.objectContaining({ comicIds: [1], maxUses: 1 })
    ));

    // The only moment it exists in a readable form: the server keeps a hash.
    expect(await screen.findByLabelText("Your new sharing code")).toHaveTextContent("C-7RFX-KP3M-Q82D");
  });

  /**
   * One comic is a C-, two or more a G-. That is the entire difference, and it
   * is decided from the selection rather than asked of the user.
   */
  it("creates a G- code as soon as the selection is a group", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      code: "G-ABCD-EFGH-JKMN",
      contentCode: { id: 5, type: "G", expiresAt: "2026-08-23T10:00:00+00:00" },
    });

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await user.click(screen.getByRole("checkbox", { name: "Select Superman #1" }));
    await user.click(screen.getByRole("tab", { name: "Create a code" }));
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Create group code/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/group-codes",
      expect.objectContaining({ comicIds: [1, 2] })
    ));
  });

  /**
   * The lifetime is an operator setting, so anything worked out in the browser
   * would be a guess that goes stale the moment somebody changes it.
   */
  it("renders the server's own expiry rather than working one out itself", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      code: "C-7RFX-KP3M-Q82D",
      contentCode: { id: 4, type: "C", expiresAt: "2026-12-25T10:00:00+00:00" },
    });

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await user.click(screen.getByRole("tab", { name: "Create a code" }));
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Create comic code/i }));

    await screen.findByLabelText("Your new sharing code");
    expect(screen.getByText(new RegExp(new Date("2026-12-25T10:00:00+00:00").getFullYear())))
      .toBeInTheDocument();
  });

  /* ------------------------------------------------------------------------ */
  /* 18+                                                                       */
  /* ------------------------------------------------------------------------ */

  it("sends the 18+ decision with the share", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await confirmRecipient(user, { username: "SilverOtter4821" });
    await user.click(screen.getByLabelText(/contain 18\+ \/ explicit content/i));
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Send invitation/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/invitations/bulk",
      expect.objectContaining({ markExplicit: true })
    ));
  });

  it("says that leaving the 18+ box unticked never clears an existing mark", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockImplementation((url) => {
      if (url === "/api/comics?ownership=mine") {
        return Promise.resolve({
          comics: [{ id: 1, title: "Adults Only", canShare: true, explicitContent: true }],
        });
      }
      if (url === "/api/shares/recent-recipients") return Promise.resolve({ recipients: [] });
      return Promise.reject(new Error(`Unexpected GET ${url}`));
    });

    open();
    await screen.findByText("Adults Only");
    await user.click(screen.getByRole("checkbox", { name: "Select Adults Only" }));

    expect(await screen.findByText(/never clears an existing mark/i)).toBeInTheDocument();
  });

  it("sends markExplicit false rather than nothing when the box is left alone", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await confirmRecipient(user, { username: "SilverOtter4821" });
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Send invitation/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/shares/invitations/bulk",
      expect.objectContaining({ markExplicit: false })
    ));
  });

  /* ------------------------------------------------------------------------ */
  /* Guards                                                                    */
  /* ------------------------------------------------------------------------ */

  it("will not send without the responsibility acknowledgement", async () => {
    const user = userEvent.setup();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await user.type(screen.getByLabelText(/Their username/i), "SilverOtter4821");

    expect(screen.getByRole("button", { name: /Send invitation/i })).toBeDisabled();
  });

  it("will not send without a recipient", async () => {
    const user = userEvent.setup();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await acknowledge(user);

    expect(screen.getByRole("button", { name: /Send invitation/i })).toBeDisabled();
  });

  it("reports what the server refused rather than claiming a share went through", async () => {
    const user = userEvent.setup();
    answerResolveAndShare({
      created: 0,
      total: 1,
      // A duplicate, which is the reachable way a batch comes back with
      // nothing created now that an unshareable comic refuses the whole
      // request before it gets this far.
      results: [{ comicId: 1, status: "skipped", message: "This comic is already shared with that person." }],
    });

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await confirmRecipient(user, { username: "SilverOtter4821" });
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Send invitation/i }));

    expect(await screen.findByText("This comic is already shared with that person.")).toBeInTheDocument();
    expect(toast).not.toHaveBeenCalled();
  });

  /**
   * The share exists; the email is queued behind it. Saying "sent" claims
   * something the response cannot know, and the notification state is where
   * delivery is actually reported.
   */
  it("says the invitation is on its way rather than that it was sent", async () => {
    const user = userEvent.setup();
    answerResolveAndShare();

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await confirmRecipient(user, { username: "SilverOtter4821" });
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Send invitation/i }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      description: expect.stringContaining("is on its way"),
    })));
  });

  it("says so when the server could not queue the notice", async () => {
    const user = userEvent.setup();
    answerResolveAndShare({
      created: 1,
      total: 1,
      results: [{ comicId: 1, status: "created", notificationState: "failed" }],
    });

    open();
    await screen.findByText("Batman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select Batman #1" }));
    await confirmRecipient(user, { username: "SilverOtter4821" });
    await acknowledge(user);
    await user.click(screen.getByRole("button", { name: /Send invitation/i }));

    // The share is real and the notice is not — telling somebody it was sent
    // leaves them waiting for an answer to a message nobody received.
    await waitFor(() => expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      description: expect.stringContaining("could not be notified"),
    })));
  });

  /**
   * A locked selection can name a comic the picker never fetched, because the
   * picker filters to what is shareable. The count has to describe the request.
   */
  it("counts the comics it will send, not the ones the library returned", async () => {
    open({ initialComicIds: [1, 2, 4], lockSelection: true });

    await screen.findByText("Batman #1");
    expect(screen.getByText(/3 comics will be offered/)).toBeInTheDocument();
  });

  /**
   * The case where the two counts diverge completely.
   *
   * Every id in the selection is one the picker never returned, so anything
   * gated on the *found* comics reads as an empty selection — the review step
   * would say "select at least one comic" over a request carrying two, and the
   * 18+ control would be disabled for the one entry point it exists to serve.
   */
  it("still reviews and can mark 18+ when the picker returned none of the selection", async () => {
    open({ initialComicIds: [41, 42], lockSelection: true });

    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics?ownership=mine"));

    expect(screen.getByText(/2 comics will be offered/)).toBeInTheDocument();
    expect(screen.queryByText(/Select at least one comic/)).not.toBeInTheDocument();
    expect(screen.getByLabelText(/contain 18\+ \/ explicit content/i)).not.toBeDisabled();
  });
});
