import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Login from "./Login";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));
const { login, register } = vi.hoisted(() => ({ login: vi.fn(), register: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn() } }));
vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast }) }));
vi.mock("@/hooks/use-auth.jsx", () => ({ useAuth: () => ({ login, register }) }));

const openSignup = () => render(
  <MemoryRouter initialEntries={["/login?signup=1"]}>
    <Login />
  </MemoryRouter>
);

describe("Login — the username field", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("offers a generated username so the field is never an empty box", async () => {
    vi.mocked(api.get).mockResolvedValue({ username: "SilverOtter4821" });

    openSignup();

    await waitFor(() => expect(screen.getByLabelText("Username")).toHaveValue("SilverOtter4821"));
    expect(api.get).toHaveBeenCalledWith("/api/users/username-suggestion");
  });

  it("replaces the suggestion on request", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get)
      .mockResolvedValueOnce({ username: "SilverOtter4821" })
      .mockResolvedValueOnce({ username: "QuietFalcon7314" });

    openSignup();
    await waitFor(() => expect(screen.getByLabelText("Username")).toHaveValue("SilverOtter4821"));

    await user.click(screen.getByRole("button", { name: "Generate another" }));

    await waitFor(() => expect(screen.getByLabelText("Username")).toHaveValue("QuietFalcon7314"));
  });

  /**
   * The suggestion is a default, not a correction.
   *
   * It arrives asynchronously, so on a slow connection it can land after
   * somebody has already started typing. Overwriting them at that moment is the
   * field deleting a name they chose, several characters in, for no reason they
   * can see.
   */
  it("never overwrites a username the person has already started typing", async () => {
    const user = userEvent.setup();

    let deliverSuggestion;
    vi.mocked(api.get).mockReturnValue(new Promise((resolve) => { deliverSuggestion = resolve; }));

    openSignup();

    const field = screen.getByLabelText("Username");
    await user.type(field, "MyOwnHandle");

    deliverSuggestion({ username: "SilverOtter4821" });

    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(field).toHaveValue("MyOwnHandle");
  });
});
