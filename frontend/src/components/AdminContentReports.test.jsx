import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminContentReports } from "./AdminContentReports";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), patch: vi.fn() } }));

const summary = {
  id: 42,
  reference: "CR-20260815-42",
  status: "received",
  category: "copyright_ip",
  reporterDisplay: "Example Publishing",
  createdAt: "2026-08-15T12:00:00+00:00",
  reviewedAt: null,
  linkedTarget: null,
};

const report = {
  ...summary,
  reporterName: "Example Rights Holder",
  reporterOrganization: "Example Publishing",
  reporterRole: "Rights manager",
  reporterEmail: "rights@example.com",
  referenceType: "comic_reference",
  reportedReference: "https://panel.example/share/reference",
  reportedContentTitle: "Linked comic",
  reportedAccountReference: "comic-owner",
  sourceContext: "Shared invitation received by the reporter.",
  explanation: "A sufficiently detailed explanation of the allegedly infringing material.",
  resolutionCode: null,
  resolutionNote: null,
  legalHold: false,
  linkedUser: null,
  linkedComic: null,
  linkedShare: null,
  targetSnapshot: {},
  targetResolution: {
    status: "candidates",
    method: "search",
    candidates: [{ type: "comic", id: 17, title: "Linked comic", owner: { id: 7, name: "Comic Owner" }, source: "search" }],
  },
};

describe("AdminContentReports", () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset().mockImplementation((path) => Promise.resolve(
      path === "/api/admin/content-reports/42"
        ? { report }
        : {
            reports: [summary],
            statuses: ["received", "under_review", "action_taken", "rejected", "closed"],
            categories: ["copyright_ip", "other_illegal"],
          }
    ));
    vi.mocked(api.patch).mockReset().mockResolvedValue({
      report: { ...report, status: "action_taken", linkedComic: { id: 17, title: "Linked comic", sharingRestricted: true, quarantined: false } },
    });
  });

  // Slower than the 5s default: the review form is a dozen fields and this types
  // its way through every one of them. It is one user journey rather than a
  // dozen assertions, so the extra room is cheaper than splitting it up.
  it("shows the queue and applies a targeted restriction", { timeout: 20000 }, async () => {
    const user = userEvent.setup();
    render(<AdminContentReports />);

    await user.click(await screen.findByRole("button", { name: /review CR-20260815-42/i }));
    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/admin/content-reports/42"));
    expect(screen.getByText("rights@example.com")).toBeInTheDocument();
    expect(screen.queryByLabelText(/linked comic id/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /link to report/i }));
    await user.selectOptions(screen.getByLabelText(/administrative action/i), "restrict_sharing");
    await user.selectOptions(screen.getByLabelText(/review status/i), "action_taken");
    await user.type(screen.getByLabelText(/resolution note/i), "Sharing restricted while the claim is assessed.");
    await user.click(screen.getByRole("button", { name: /save review/i }));

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith(
      "/api/admin/content-reports/42",
      expect.objectContaining({
        targetType: "comic",
        targetId: 17,
        action: "restrict_sharing",
        status: "action_taken",
      })
    ));
  });

  it("keeps allegation details out of the queue response until Review is opened", async () => {
    render(<AdminContentReports />);

    expect(await screen.findByText("Example Publishing")).toBeInTheDocument();
    expect(screen.queryByText(report.explanation)).not.toBeInTheDocument();
    expect(api.get).toHaveBeenCalledTimes(1);
  });
});
