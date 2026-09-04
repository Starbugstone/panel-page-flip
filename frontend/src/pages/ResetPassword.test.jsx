import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ResetPassword from "./ResetPassword";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast }) }));

const renderResetPassword = () => render(
  <MemoryRouter initialEntries={["/reset-password/reset-token"]}>
    <Routes>
      <Route path="/reset-password/:token" element={<ResetPassword />} />
      <Route path="/login" element={<h1>Login</h1>} />
    </Routes>
  </MemoryRouter>
);

describe("ResetPassword token validation", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("lets the reader retry a temporary validation failure", async () => {
    vi.mocked(api.get).mockRejectedValueOnce({ status: 503 }).mockResolvedValueOnce({});
    renderResetPassword();
    await userEvent.click(await screen.findByRole("button", { name: "Try again" }));
    expect(await screen.findByLabelText("New Password")).toHaveAttribute("autocomplete", "new-password");
    expect(screen.getByRole("heading", { level: 1, name: "Reset Your Password" })).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledTimes(2);
  });

  it("keeps the completion visible until the reader chooses to sign in", async () => {
    vi.mocked(api.get).mockResolvedValue({});
    vi.mocked(api.post).mockResolvedValue({});
    renderResetPassword();
    await userEvent.type(await screen.findByLabelText("New Password"), "NewPassword123!");
    await userEvent.type(screen.getByLabelText("Confirm New Password"), "NewPassword123!");
    vi.useFakeTimers();
    try {
      await act(async () => screen.getByRole("button", { name: "Reset Password" }).click());
      await act(async () => vi.advanceTimersByTime(2500));
      expect(screen.getByRole("heading", { level: 1, name: "Password Reset Complete" })).toBeInTheDocument();
      expect(screen.getByRole("link", { name: "Go to Login" })).toHaveAttribute("href", "/login");
    } finally {
      vi.useRealTimers();
    }
  });

  it("identifies the backend's invalid-or-expired token response", async () => {
    vi.mocked(api.get).mockRejectedValue({
      status: 400,
      message: "Invalid or expired token",
      data: { message: "Invalid or expired token" },
    });

    renderResetPassword();

    expect(await screen.findByRole("heading", { name: "Invalid Reset Link" })).toBeInTheDocument();
    expect(screen.getByText("This password reset link is invalid or has expired.")).toBeInTheDocument();
  });

  it("does not call a server validation failure an invalid link", async () => {
    vi.mocked(api.get).mockRejectedValue({
      status: 503,
      message: "Reset service is temporarily unavailable",
    });

    renderResetPassword();

    expect(await screen.findByRole("heading", { name: "Could Not Validate Reset Link" })).toBeInTheDocument();
    expect(screen.getByText("Reset service is temporarily unavailable")).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Invalid Reset Link" })).not.toBeInTheDocument();
  });

  it("gives a useful fallback when validation fails without a message", async () => {
    vi.mocked(api.get).mockRejectedValue({ status: 0 });

    renderResetPassword();

    expect(await screen.findByText("The reset link could not be checked. Please try again.")).toBeInTheDocument();
  });
});
