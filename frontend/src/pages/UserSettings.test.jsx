import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import UserSettings from "./UserSettings";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({
  api: {
    get: vi.fn(() => Promise.resolve({ tags: [] })),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: vi.fn() }) }));
vi.mock("@/hooks/use-tags", () => ({ useTags: () => ({ fetchTags: vi.fn() }) }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ logout: vi.fn() }) }));
vi.mock("@/components/AccountSettingsCard", () => ({ AccountSettingsCard: () => null }));
vi.mock("@/components/UserMetadataCredentials", () => ({ UserMetadataCredentials: () => null }));

describe("UserSettings account deletion", () => {
  it("describes all sharing data removed with the account", async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><UserSettings /></MemoryRouter>);

    await user.click(screen.getByRole("button", { name: "Delete my account" }));

    expect(await screen.findByText(
      /sharing relationships, codes, and invitations/
    )).toBeInTheDocument();
    expect(api.delete).not.toHaveBeenCalled();
  });
});
