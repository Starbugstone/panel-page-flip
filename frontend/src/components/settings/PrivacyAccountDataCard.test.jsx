import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

const state = vi.hoisted(() => ({
  auth: { user: { hasPassword: true }, logout: vi.fn() },
  toast: vi.fn(),
}));

vi.mock("@/lib/api", () => ({ api: { blob: vi.fn(), delete: vi.fn() } }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => state.auth }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: state.toast }) }));

import { api } from "@/lib/api";
import { PrivacyAccountDataCard } from "./PrivacyAccountDataCard";

function renderCard(props = {}) {
  return render(
    <MemoryRouter>
      <PrivacyAccountDataCard oauthConnections={[]} {...props} />
    </MemoryRouter>
  );
}

describe("PrivacyAccountDataCard", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    state.auth.user = { hasPassword: true };
    state.auth.logout.mockResolvedValue(undefined);
  });

  it("deletes a password account only after both confirmations", async () => {
    const user = userEvent.setup();
    api.delete.mockResolvedValue({});
    renderCard();

    await user.click(screen.getByRole("button", { name: "Delete my account" }));
    const submit = screen.getByRole("button", { name: "Delete account permanently" });
    expect(submit).toBeDisabled();
    await user.type(screen.getByLabelText("Current password"), "secret");
    await user.type(screen.getByLabelText("Type DELETE to confirm"), "DELETE");
    await user.click(submit);

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith("/api/privacy/account", {
      body: { confirmation: "DELETE", currentPassword: "secret" },
    }));
    expect(state.auth.logout).toHaveBeenCalled();
  });

  it("uses a recent provider confirmation without requesting a password", () => {
    state.auth.user = { hasPassword: false };
    renderCard({ initiallyOpen: true });

    expect(screen.getByText(/provider has recently confirmed your identity/i)).toBeInTheDocument();
    expect(screen.queryByLabelText("Current password")).not.toBeInTheDocument();
  });

  it("explains when a provider-only account cannot reauthenticate", async () => {
    const user = userEvent.setup();
    state.auth.user = { hasPassword: false };
    renderCard();

    await user.click(screen.getByRole("button", { name: "Delete my account" }));

    expect(state.toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Provider reauthentication unavailable",
      variant: "destructive",
    }));
  });

  it("downloads and releases a personal data export", async () => {
    const user = userEvent.setup();
    const blob = new Blob(["{}"], { type: "application/json" });
    api.blob.mockResolvedValue(blob);
    const createObjectURL = vi.spyOn(URL, "createObjectURL").mockReturnValue("blob:export");
    const revokeObjectURL = vi.spyOn(URL, "revokeObjectURL").mockImplementation(() => {});
    const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});
    renderCard();

    await user.click(screen.getByRole("button", { name: "Download my data" }));

    expect(api.blob).toHaveBeenCalledWith("/api/privacy/export");
    expect(createObjectURL).toHaveBeenCalledWith(blob);
    expect(click).toHaveBeenCalled();
    await waitFor(() => expect(revokeObjectURL).toHaveBeenCalledWith("blob:export"));
  });
});
