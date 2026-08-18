import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminContentReports } from "./AdminContentReports";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), patch: vi.fn() } }));

const report = {
  id: 42,
  reference: "CR-20260815-42",
  status: "received",
  category: "copyright_ip",
  reporterName: "Example Rights Holder",
  reporterOrganization: "Example Publishing",
  reporterEmail: "rights@example.com",
  reportedReference: "https://panel.example/share/reference",
  explanation: "A sufficiently detailed explanation of the allegedly infringing material.",
  createdAt: "2026-08-15T12:00:00+00:00",
  resolutionCode: null,
  resolutionNote: null,
  legalHold: false,
  linkedUser: null,
  linkedComic: null,
  linkedShare: null,
};

describe("AdminContentReports", () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset().mockResolvedValue({
      reports: [report],
      statuses: ["received", "under_review", "action_taken", "rejected", "closed"],
      categories: ["copyright_ip", "other_illegal"],
    });
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
    await user.clear(screen.getByLabelText(/linked comic id/i));
    await user.type(screen.getByLabelText(/linked comic id/i), "17");
    await user.selectOptions(screen.getByLabelText(/administrative action/i), "restrict_sharing");
    await user.selectOptions(screen.getByLabelText(/review status/i), "action_taken");
    await user.type(screen.getByLabelText(/resolution note/i), "Sharing restricted while the claim is assessed.");
    await user.click(screen.getByRole("button", { name: /save review/i }));

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith(
      "/api/admin/content-reports/42",
      expect.objectContaining({
        linkedComicId: 17,
        action: "restrict_sharing",
        status: "action_taken",
      })
    ));
  });
});
