import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import BulkUploadComic from "./BulkUploadComic";
import EmailVerification from "./EmailVerification";
import ForgotPassword from "./ForgotPassword";
import NotFound from "./NotFound";
import UploadComic from "./UploadComic";

vi.mock("@/lib/api", () => ({ api: { post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: vi.fn() }) }));
vi.mock("@/components/UploadComicForm", () => ({ default: () => <div>single upload form</div> }));
vi.mock("@/components/BulkUploadQueue", () => ({ default: () => <div>bulk upload queue</div> }));

const renderAt = (path, element) => render(
  <MemoryRouter initialEntries={[path]}>{element}</MemoryRouter>
);

describe("basic route pages", () => {
  beforeEach(() => vi.clearAllMocks());

  it("wraps the single and bulk upload experiences", () => {
    const single = renderAt("/upload", <UploadComic />);
    expect(screen.getByText("single upload form")).toBeInTheDocument();
    single.unmount();

    renderAt("/upload/bulk/session", <BulkUploadComic />);
    expect(screen.getByText("bulk upload queue")).toBeInTheDocument();
  });

  it("submits a password reset request without revealing account existence", async () => {
    vi.mocked(api.post).mockResolvedValue({});
    const user = userEvent.setup();
    renderAt("/forgot-password", <ForgotPassword />);

    await user.type(screen.getByLabelText("Email"), "reader@example.test");
    await user.click(screen.getByRole("button", { name: "Send Reset Link" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/forgot-password",
      { email: "reader@example.test" },
      { notifyUnauthorized: false },
    ));
    expect(screen.getByText(/If an account exists with the email/)).toBeInTheDocument();
  });

  it("renders the verification result carried by the redirect URL", () => {
    renderAt(
      "/email-verification?status=verification-success&message=Already%20verified",
      <EmailVerification />,
    );

    expect(screen.getByText("Email Verified!")).toBeInTheDocument();
    expect(screen.getByText("Already verified")).toBeInTheDocument();
  });

  it("offers a way home from an unknown route", () => {
    renderAt("/missing", <NotFound />);

    expect(screen.getByRole("heading", { name: "404" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Return to Home" })).toHaveAttribute("href", "/");
  });
});
