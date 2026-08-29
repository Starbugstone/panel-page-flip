import { render, screen } from "@testing-library/react";
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
    </Routes>
  </MemoryRouter>
);

describe("ResetPassword token validation", () => {
  beforeEach(() => {
    vi.clearAllMocks();
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
