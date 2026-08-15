import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ReportContent from "./ReportContent";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));

describe("ReportContent", () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset().mockResolvedValue({ legalEmail: "legal@example.test" });
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
    fireEvent.change(screen.getByLabelText(/identify the material/i), { target: { value: "https://panel.example/share/example" } });
    fireEvent.change(screen.getByLabelText(/explain the report/i), { target: { value: "I represent the publisher and this shared edition reproduces the protected work without authorization." } });
    await user.click(screen.getByRole("checkbox", { name: /submitting it in good faith/i }));
    await user.click(screen.getByRole("button", { name: /submit report/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/content-reports",
      expect.objectContaining({
        reporterName: "Example Rights Holder",
        category: "copyright_ip",
        goodFaithAcknowledged: true,
        website: "",
      }),
      { notifyUnauthorized: false }
    ));
    expect(await screen.findByText("CR-20260815-42")).toBeInTheDocument();
  });

  it("shows field validation returned by the server", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue({ data: { errors: { reporterEmail: "Provide a valid email address." } } });
    render(<MemoryRouter><ReportContent /></MemoryRouter>);

    await user.click(screen.getByRole("button", { name: /submit report/i }));

    expect(await screen.findByText("Provide a valid email address.")).toBeInTheDocument();
  });
});
