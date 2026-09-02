import { render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter, useLocation } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

const toast = vi.hoisted(() => vi.fn());
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

import { useSettingsOAuthResult } from "./use-settings-oauth-result";

function Harness() {
  const reauthenticated = useSettingsOAuthResult();
  const location = useLocation();
  return <div>{reauthenticated ? "confirmed" : "ordinary"}|{location.search}</div>;
}

function renderResult(query) {
  return render(
    <MemoryRouter initialEntries={[`/settings?${query}&provider=google`]}>
      <Harness />
    </MemoryRouter>
  );
}

describe("useSettingsOAuthResult", () => {
  beforeEach(() => vi.clearAllMocks());

  it("reports recent reauthentication and removes callback parameters", async () => {
    renderResult("oauth_reauthenticated=google");

    expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: "Identity confirmed" }));
    await waitFor(() => expect(screen.getByText("ordinary|")).toBeInTheDocument());
  });

  it("names a newly connected provider", async () => {
    renderResult("oauth_connected=google");

    expect(toast).toHaveBeenCalledWith({
      title: "Sign-in method connected",
      description: "Google can now sign in to this account.",
    });
    await waitFor(() => expect(screen.getByText("ordinary|")).toBeInTheDocument());
  });

  it("translates a provider error", async () => {
    renderResult("oauth_error=wrong_account");

    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Provider connection unsuccessful",
      description: "The provider account did not match this user.",
      variant: "destructive",
    }));
    await waitFor(() => expect(screen.getByText("ordinary|")).toBeInTheDocument());
  });
});
