import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SignInMethodsCard } from "./SignInMethodsCard";
import { api } from "@/lib/api";

const toast = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), delete: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

describe("SignInMethodsCard", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({
      hasPassword: true,
      providers: [{
        provider: "google",
        enabled: true,
        connected: true,
        email: "reader@example.com",
      }],
    });
  });

  it("shows the provider email snapshot and disconnects a connected method", async () => {
    const user = userEvent.setup();
    vi.mocked(api.delete).mockResolvedValue({ message: "Google disconnected." });
    render(<SignInMethodsCard />);

    expect(await screen.findByText("Connected as reader@example.com")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Disconnect" }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith("/api/auth/oauth/google"));
    expect(toast).toHaveBeenCalledWith({ title: "Google disconnected" });
  });
});
