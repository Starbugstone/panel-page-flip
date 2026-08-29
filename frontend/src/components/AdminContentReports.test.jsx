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

  /**
   * The queue row is rebuilt from the saved detail rather than refetched, so it
   * has to carry the same label the list endpoint sends. Without it a report
   * that was just linked reads as "Unresolved" — the one state it is not.
   */
  it("shows the linked target in the queue after a review is saved", { timeout: 20000 }, async () => {
    const user = userEvent.setup();
    render(<AdminContentReports />);

    await user.click(await screen.findByRole("button", { name: /review CR-20260815-42/i }));
    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/admin/content-reports/42"));
    await user.click(screen.getByRole("button", { name: /link to report/i }));
    await user.selectOptions(screen.getByLabelText(/review status/i), "action_taken");
    await user.click(screen.getByRole("button", { name: /save review/i }));

    await waitFor(() => expect(api.patch).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByText("Unresolved")).not.toBeInTheDocument());
    expect(screen.getAllByText("Linked comic").length).toBeGreaterThan(0);
  });

  it("keeps allegation details out of the queue response until Review is opened", async () => {
    render(<AdminContentReports />);

    expect(await screen.findByText("Example Publishing")).toBeInTheDocument();
    expect(screen.queryByText(report.explanation)).not.toBeInTheDocument();
    expect(api.get).toHaveBeenCalledTimes(1);
  });

  it("clears a pending target when candidate search results are replaced", async () => {
    const user = userEvent.setup();
    const replacement = {
      ...report,
      targetResolution: {
        status: "candidates",
        method: "search",
        candidates: [{ type: "user", id: 99, name: "Different owner", email: "different@example.com", source: "search" }],
      },
    };
    vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
      path === "/api/admin/content-reports/42"
        ? { report }
        : path === "/api/admin/content-reports/42?q=different"
          ? { report: replacement }
          : {
              reports: [summary],
              statuses: ["received", "under_review", "action_taken", "rejected", "closed"],
              categories: ["copyright_ip", "other_illegal"],
            }
    ));

    render(<AdminContentReports />);
    await user.click(await screen.findByRole("button", { name: /review CR-20260815-42/i }));
    await user.click(await screen.findByRole("button", { name: /link to report/i }));
    await user.selectOptions(screen.getByLabelText(/administrative action/i), "restrict_sharing");
    await user.type(screen.getByLabelText(/search target candidates/i), "different");
    await user.click(screen.getByRole("button", { name: /^search$/i }));

    expect(await screen.findByText("Different owner")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Selected" })).not.toBeInTheDocument();
    expect(screen.getByLabelText(/administrative action/i)).toHaveValue("none");
    await user.click(screen.getByRole("button", { name: /save review/i }));
    await waitFor(() => expect(api.patch).toHaveBeenCalled());
    const payload = vi.mocked(api.patch).mock.calls.at(-1)[1];
    expect(payload).not.toHaveProperty("targetType");
    expect(payload).not.toHaveProperty("targetId");
  });

  it("sends an explicit null target when an administrator unlinks a report", async () => {
    const user = userEvent.setup();
    const linkedReport = {
      ...report,
      linkedComic: { id: 17, title: "Linked comic", owner: { id: 7, name: "Comic Owner" } },
      targetSnapshot: { comicId: 17, comicTitle: "Linked comic" },
    };
    vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
      path === "/api/admin/content-reports/42"
        ? { report: linkedReport }
        : {
            reports: [{ ...summary, linkedTarget: { type: "comic", id: 17, label: "Linked comic" } }],
            statuses: ["received", "under_review", "action_taken", "rejected", "closed"],
            categories: ["copyright_ip", "other_illegal"],
          }
    ));
    vi.mocked(api.patch).mockResolvedValue({
      report: {
        ...linkedReport,
        status: "under_review",
        linkedComic: null,
        targetSnapshot: {},
      },
    });

    render(<AdminContentReports />);
    await user.click(await screen.findByRole("button", { name: /review CR-20260815-42/i }));
    await user.click(await screen.findByRole("button", { name: /unlink target/i }));

    expect(screen.getByText("No target has been confirmed.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /unlink target/i })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /save review/i }));

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith(
      "/api/admin/content-reports/42",
      expect.objectContaining({
        targetType: null,
        targetId: null,
        action: "none",
        notifyOwner: false,
      })
    ));
  });
});
