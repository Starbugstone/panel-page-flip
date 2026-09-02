import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { PendingInvitationCard } from "./PendingInvitationCard";
import { ReceivedShareCard } from "./ReceivedShareCard";
import { RedeemCodePanel } from "./RedeemCodePanel";
import { SharedComicGroup } from "./SharedComicGroup";
import { SharingIdentityPanel } from "./SharingIdentityPanel";

const share = {
  id: 1,
  status: "pending",
  comicTitle: "A comic shared with a reader",
  comicAuthor: "Writer",
  ownerName: "Owner",
  explicitContent: false,
  requiresAdultConfirmation: false,
  isDead: false,
  canAnswer: true,
  canRead: false,
  canRemove: false,
  canRestore: false,
};

describe("mobile sharing controls", () => {
  it("moves invitation actions below the cover and details", () => {
    render(
      <PendingInvitationCard
        share={share}
        busy={false}
        onConfirmAdult={vi.fn()}
        onAccept={vi.fn()}
        onDecline={vi.fn()}
      />,
    );

    const actions = screen.getByRole("button", { name: "Add to my collection" }).parentElement;
    expect(actions).toHaveClass("w-full", "sm:w-auto");
    expect(actions.parentElement).toHaveClass("flex-col", "sm:flex-row");
  });

  it("moves collection actions below the cover and details", () => {
    render(
      <ReceivedShareCard
        share={{ ...share, status: "accepted", canRead: true, canRemove: true }}
        busy={false}
        onConfirmAdult={vi.fn()}
        onRead={vi.fn()}
        onRemove={vi.fn()}
        onRestore={vi.fn()}
        onForget={vi.fn()}
        showActions
      />,
    );

    const actions = screen.getByRole("button", { name: "Read" }).parentElement;
    expect(actions).toHaveClass("w-full", "sm:w-auto");
    expect(actions.parentElement).toHaveClass("flex-col", "sm:flex-row");
  });

  it("gives a shared comic's owner actions their own mobile row", () => {
    render(
      <SharedComicGroup
        group={{
          comicId: 4,
          title: "A comic shared by the reader",
          author: "Writer",
          explicitContent: false,
          recipients: [],
        }}
        busyShareId={null}
        onShare={vi.fn()}
        onStopSharing={vi.fn()}
        onResend={vi.fn()}
        onRevoke={vi.fn()}
        onDelete={vi.fn()}
      />,
    );

    const actions = screen.getByRole("button", { name: "Share this comic" }).parentElement;
    expect(actions).toHaveClass("w-full", "sm:w-auto");
    expect(actions.parentElement).toHaveClass("flex-col", "sm:flex-row");
  });

  it("separates the account code from its actions on a phone", () => {
    render(
      <SharingIdentityPanel
        identity={{ username: "Reader", userCode: "U-AAAA-BBBB-CCCC" }}
        loadFailed={false}
        copied={false}
        isRotating={false}
        onCopy={vi.fn()}
        onRotate={vi.fn()}
      />,
    );

    const code = screen.getByLabelText("Your user code");
    expect(code.parentElement).toHaveClass("grid", "grid-cols-2", "sm:flex");
    expect(code).toHaveClass("col-span-2", "min-w-0", "break-all", "sm:flex-1");
    expect(screen.getByRole("button", { name: "Copy your user code" })).toHaveClass("w-full", "sm:w-auto");
  });

  it("stacks the redeem field and action on a phone", () => {
    render(
      <RedeemCodePanel
        value=""
        onChange={vi.fn()}
        isRedeeming={false}
        error={null}
        onRedeem={vi.fn()}
      />,
    );

    const field = screen.getByLabelText("Sharing code");
    expect(field.parentElement.parentElement).toHaveClass("flex-col", "sm:flex-row", "sm:items-end");
    expect(screen.getByRole("button", { name: "Redeem" })).toHaveClass("w-full", "sm:w-auto");
  });
});
