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

describe("Login — registration copy", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockImplementation((url) => Promise.resolve(
      url === "/api/auth/providers" ? {} : { username: "SilverOtter4821" }
    ));
  });

  it("uses Panel Page Flip branding throughout the page", async () => {
    openSignup();

    expect(await screen.findByText("Welcome to Panel Page Flip")).toBeInTheDocument();
    expect(screen.getByText(/Panel Page Flip is your personal multi-format comic library/i)).toBeInTheDocument();
    expect(screen.queryByText(/Comic Reader/i)).not.toBeInTheDocument();
  });

  it("places password-policy feedback with the password field", async () => {
    const user = userEvent.setup();
    openSignup();

    const name = screen.getByLabelText("Name");
    const password = screen.getByLabelText("Password");
    await user.type(password, "weak");

    const hint = screen.getByText(/Password must include:/i);
    expect(password.parentElement).toContainElement(hint);
    expect(name.parentElement).not.toContainElement(hint);
  });

  it("tells a newly registered person to verify their email before logging in", async () => {
    const user = userEvent.setup();
    register.mockResolvedValue({});
    openSignup();

    await waitFor(() => expect(screen.getByLabelText("Username")).toHaveValue("SilverOtter4821"));
    await user.type(screen.getByLabelText("Name"), "Reader One");
    await user.type(screen.getByLabelText("Email"), "reader@example.com");
    await user.type(screen.getByLabelText("Password"), "StrongPassword!123");
    await user.click(screen.getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: "Create account" }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      description: expect.stringMatching(/verify your email before logging in/i),
    })));
  });
});

describe("Login — the username field", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("offers a generated username so the field is never an empty box", async () => {
    vi.mocked(api.get).mockImplementation((url) => Promise.resolve(
      url === "/api/auth/providers" ? {} : { username: "SilverOtter4821" }
    ));

    openSignup();

    await waitFor(() => expect(screen.getByLabelText("Username")).toHaveValue("SilverOtter4821"));
    expect(api.get).toHaveBeenCalledWith("/api/users/username-suggestion");
  });

  it("describes every way a comic can be shared", async () => {
    vi.mocked(api.get).mockImplementation((url) => Promise.resolve(
      url === "/api/auth/providers" ? {} : { username: "SilverOtter4821" }
    ));

    openSignup();

    expect(await screen.findByText(
      /share it directly by username, U- code, or email, or send a private C- or G- code/i
    )).toBeInTheDocument();
    expect(screen.queryByText(/share it by email —/i)).not.toBeInTheDocument();
  });

  it("replaces the suggestion on request", async () => {
    const user = userEvent.setup();
    const suggestions = ["SilverOtter4821", "QuietFalcon7314"];
    vi.mocked(api.get).mockImplementation((url) => Promise.resolve(
      url === "/api/auth/providers" ? {} : { username: suggestions.shift() }
    ));

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

describe("Login — social providers", () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it("shows Google only when the installation reports it configured", async () => {
    vi.mocked(api.get).mockImplementation((url) => Promise.resolve(
      url === "/api/auth/providers"
        ? { google: true }
        : { username: "SilverOtter4821" }
    ));

    openSignup();

    expect(await screen.findByRole("button", { name: "Continue with Google" })).toBeInTheDocument();
  });

  it("keeps social buttons hidden when providers are disabled", async () => {
    vi.mocked(api.get).mockImplementation((url) => Promise.resolve(
      url === "/api/auth/providers"
        ? { google: false }
        : { username: "SilverOtter4821" }
    ));

    openSignup();
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(
      "/api/auth/providers",
      { notifyUnauthorized: false },
    ));

    expect(screen.queryByRole("button", { name: "Continue with Google" })).not.toBeInTheDocument();
  });
});
