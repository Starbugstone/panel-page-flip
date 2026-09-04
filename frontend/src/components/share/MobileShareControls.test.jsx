import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import { PendingFolderInvitationCard } from "./PendingFolderInvitationCard";
import { PendingInvitationCard } from "./PendingInvitationCard";
import { ReceivedShareCard } from "./ReceivedShareCard";
import { RedeemCodePanel } from "./RedeemCodePanel";
import { SharedByMeList } from "./SharedByMeList";
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

  it("keeps a folder invitation's icon and details together above its actions", () => {
    render(
      <PendingFolderInvitationCard
        shares={[{ ...share, invitationBatchName: "Shared folder" }]}
        busy={false}
        onConfirmAdult={vi.fn()}
        onAccept={vi.fn()}
        onDecline={vi.fn()}
      />,
    );

    const actions = screen.getByRole("button", { name: "Add all to my collection" }).parentElement;
    const details = screen.getByRole("heading", { name: "Shared folder" }).parentElement;
    const mediaAndDetails = details.parentElement;

    expect(actions).toHaveClass("w-full", "sm:w-auto");
    expect(actions.parentElement).toHaveClass("flex-col", "sm:flex-row");
    expect(mediaAndDetails).toHaveClass("flex", "min-w-0", "flex-1", "gap-4");
    expect(mediaAndDetails.parentElement).toBe(actions.parentElement);
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

  it("keeps the owner's management table scrollable on a phone", () => {
    render(
      <MemoryRouter>
        <SharedByMeList
          sharedByMe={[{
            id: 1,
            comicId: 4,
            comicTitle: "A comic shared by the reader",
            comicAuthor: "Writer",
            explicitContent: false,
            recipientEmail: "reader@example.com",
            recipientLabel: "reader@example.com",
            status: "accepted",
            createdAt: "2026-09-01T12:00:00+00:00",
            canResend: false,
            canRevoke: true,
            canDelete: false,
          }]}
          byMePagination={{ page: 1, limit: 25, totalItems: 1, totalPages: 1 }}
          byMeListKey="mobile-list"
          byMeIsLoading={false}
          searchInput=""
          tableControls={{
            columnFilters: {},
            headerProps: { sort: "createdAt", direction: "DESC", onSort: vi.fn(), onFilter: vi.fn() },
          }}
          busyShareId={null}
          onSearch={vi.fn()}
          onPageChange={vi.fn()}
          onLimitChange={vi.fn()}
          onShare={vi.fn()}
          onStopSharing={vi.fn()}
          onResend={vi.fn()}
          onRevoke={vi.fn()}
          onDelete={vi.fn()}
          reload={vi.fn()}
        />
      </MemoryRouter>,
    );

    expect(screen.getByRole("table").parentElement).toHaveClass("overflow-auto");
    expect(screen.getByText("0 of 1 share selected").parentElement.parentElement)
      .toHaveClass("flex-col", "lg:flex-row");
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
