import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ReportContent from "./ReportContent";
import { api } from "@/lib/api";

const { publicConfig } = vi.hoisted(() => ({
  publicConfig: {
    turnstile: { enabled: false, siteKey: null },
    legal: { operator: "Test operator", privacyEmail: null, legalEmail: "legal@example.test" },
    isLoading: false,
  },
}));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/components/config/PublicConfigProvider.jsx", () => ({
  usePublicConfig: () => publicConfig,
}));
vi.mock("@/components/security/TurnstileWidget.jsx", () => ({
  TurnstileWidget: ({ onToken, onError, resetKey }) => (
    <div data-testid="turnstile" data-reset-key={resetKey}>
      <button type="button" onClick={() => onToken("widget-token")}>Complete verification</button>
      <button type="button" onClick={() => { onToken(null); onError(); }}>Fail verification</button>
    </div>
  ),
}));

describe("ReportContent", () => {
  beforeEach(() => {
    publicConfig.turnstile = { enabled: false, siteKey: null };
    publicConfig.legal = { operator: "Test operator", privacyEmail: null, legalEmail: "legal@example.test" };
    publicConfig.isLoading = false;
    vi.mocked(api.get).mockReset().mockResolvedValue({});
    vi.mocked(api.post).mockReset().mockResolvedValue({
      message: "Your report has been received and will be reviewed.",
      reference: "CR-20260815-42",
    });
  });

  it("submits a specific notice and shows its reference", async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><ReportContent /></MemoryRouter>);

    fireEvent.change(screen.getByLabelText(/name or organization/i), { target: { value: "Example Rights Holder" } });
    fireEvent.change(screen.getByLabelText(/^email/i), { target: { value: "rights@example.com" } });
    await user.selectOptions(screen.getByLabelText(/report type/i), "copyright_ip");
    await user.selectOptions(screen.getByLabelText(/how can we identify it/i), "panel_url");
    fireEvent.change(screen.getByLabelText(/panel page flip url/i), { target: { value: "https://panel.example/read/17" } });
    fireEvent.change(screen.getByLabelText(/explain the report/i), { target: { value: "I represent the publisher and this shared edition reproduces the protected work without authorization." } });
    await user.click(screen.getByRole("checkbox", { name: /submitting it in good faith/i }));
    await user.click(screen.getByRole("button", { name: /submit report/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/content-reports",
      expect.objectContaining({
        reporterName: "Example Rights Holder",
        category: "copyright_ip",
        referenceType: "panel_url",
        reportedReference: "https://panel.example/read/17",
        goodFaithAcknowledged: true,
        website: "",
      }),
      { notifyUnauthorized: false }
    ));
    expect(await screen.findByText("CR-20260815-42")).toBeInTheDocument();
  });

  it("offers structured references without asking for internal IDs", async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><ReportContent /></MemoryRouter>);

    expect(screen.getByText(/internal database IDs are never required/i)).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText(/how can we identify it/i), "sharing_code");
    expect(screen.getByLabelText(/content sharing code/i)).toHaveAttribute("placeholder", "C-1234-5678-9ABC");
    expect(screen.queryByLabelText(/linked .* id/i)).not.toBeInTheDocument();
  });

  it("shows field validation returned by the server", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue({ data: { errors: { reporterEmail: "Provide a valid email address." } } });
    render(<MemoryRouter><ReportContent /></MemoryRouter>);

    await user.click(screen.getByRole("button", { name: /submit report/i }));

    expect(await screen.findByText("Provide a valid email address.")).toBeInTheDocument();
  });

  it("does not render or submit Turnstile metadata when the feature is disabled", async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><ReportContent /></MemoryRouter>);

    expect(screen.queryByTestId("turnstile")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /submit report/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalled());
    expect(api.post.mock.calls[0][1]).not.toHaveProperty("turnstileToken");
  });

  it("requires a token, sends it only as request metadata, and resets it after a backend attempt", async () => {
    const user = userEvent.setup();
    publicConfig.turnstile = { enabled: true, siteKey: "public-site-key" };
    vi.mocked(api.post).mockRejectedValue({ data: { errors: { reporterEmail: "Check the email." } } });
    render(<MemoryRouter><ReportContent /></MemoryRouter>);

    const submit = screen.getByRole("button", { name: /submit report/i });
    expect(submit).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/name or organization/i), { target: { value: "Preserved reporter" } });
    await user.click(screen.getByRole("button", { name: /complete verification/i }));
    expect(submit).toBeEnabled();
    await user.click(submit);

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/content-reports",
      expect.objectContaining({ turnstileToken: "widget-token" }),
      { notifyUnauthorized: false }
    ));
    expect(screen.getByLabelText(/name or organization/i)).toHaveValue("Preserved reporter");
    expect(screen.getByTestId("turnstile")).toHaveAttribute("data-reset-key", "1");
    expect(submit).toBeDisabled();
  });

  it("keeps entered data and shows the legal-email fallback when verification is unavailable", async () => {
    const user = userEvent.setup();
    const unavailable = "Anti-bot verification is temporarily unavailable. Keep your report details and try again, or email legal@example.test.";
    publicConfig.turnstile = { enabled: true, siteKey: "public-site-key" };
    vi.mocked(api.post).mockRejectedValue({ status: 503, data: { errors: { turnstile: unavailable } } });
    render(<MemoryRouter><ReportContent /></MemoryRouter>);

    fireEvent.change(screen.getByLabelText(/name or organization/i), { target: { value: "Preserved reporter" } });
    await user.click(screen.getByRole("button", { name: /complete verification/i }));
    await user.click(screen.getByRole("button", { name: /submit report/i }));

    expect(await screen.findByText(unavailable)).toBeInTheDocument();
    expect(screen.getByLabelText(/name or organization/i)).toHaveValue("Preserved reporter");
  });

  it("does not invent a blank reference or receipt for honeypot fake success", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      message: "Your report has been received and will be reviewed.",
    });
    render(<MemoryRouter><ReportContent /></MemoryRouter>);

    await user.click(screen.getByRole("button", { name: /submit report/i }));

    expect(await screen.findByRole("heading", { name: /report received/i })).toBeInTheDocument();
    expect(screen.queryByText(/your reference is/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/detailed receipt has been sent/i)).not.toBeInTheDocument();
  });
});
