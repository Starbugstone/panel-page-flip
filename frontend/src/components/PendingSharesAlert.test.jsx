import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import { PendingSharesAlert } from "./PendingSharesAlert";

const sharing = vi.hoisted(() => ({
  summary: { pendingInvitations: 0 },
}));

vi.mock("@/hooks/use-sharing", () => ({
  useSharing: () => sharing,
}));

describe("PendingSharesAlert", () => {
  it("describes every pending comic when more than one invitation is waiting", () => {
    sharing.summary.pendingInvitations = 3;

    render(<MemoryRouter><PendingSharesAlert /></MemoryRouter>);

    expect(screen.getByText("You have 3 comic invitations.")).toBeInTheDocument();
    expect(screen.getByText("Review them to see who shared each comic.")).toBeInTheDocument();
    expect(screen.queryByText("Somebody wants to share a comic with you.")).not.toBeInTheDocument();
  });
});
