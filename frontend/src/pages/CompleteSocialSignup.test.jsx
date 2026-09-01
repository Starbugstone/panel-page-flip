import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import CompleteSocialSignup from "./CompleteSocialSignup";
import { api } from "@/lib/api";

const mocks = vi.hoisted(() => ({ toast: vi.fn(), checkAuth: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ checkAuth: mocks.checkAuth }) }));

const renderPage = () => render(
  <MemoryRouter initialEntries={["/complete-social-signup"]}>
    <Routes>
      <Route path="/complete-social-signup" element={<CompleteSocialSignup />} />
      <Route path="/dashboard" element={<p>Dashboard reached</p>} />
    </Routes>
  </MemoryRouter>
);

describe("CompleteSocialSignup", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({
      provider: "google",
      email: "reader@example.com",
      name: "Reader",
      suggestedUsername: "SilverOtter4821",
    });
  });

  it("keeps the provider identity server-side and submits only onboarding fields", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({ requiresVerification: false, redirect: "/dashboard" });
    renderPage();

    expect(await screen.findByText(/Continue with Google as reader@example.com/)).toBeInTheDocument();
    expect(screen.getByLabelText("Username")).toHaveValue("SilverOtter4821");
    await user.click(screen.getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: "Create account and continue" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/auth/oauth/complete-registration",
      { username: "SilverOtter4821", agreeTerms: true },
      { notifyUnauthorized: false },
    ));
    expect(mocks.checkAuth).toHaveBeenCalled();
    expect(await screen.findByText("Dashboard reached")).toBeInTheDocument();
  });

  it("does not submit until the legal acknowledgement is accepted", async () => {
    renderPage();

    expect(await screen.findByRole("button", { name: "Create account and continue" })).toBeDisabled();
    expect(api.post).not.toHaveBeenCalled();
  });
});
