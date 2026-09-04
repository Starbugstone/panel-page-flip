import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import SessionMonitor from "@/components/SessionMonitor";

const auth = vi.hoisted(() => ({ sessionExpired: false }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => auth }));

describe("SessionMonitor", () => {
  it("stays quiet while the session is good", () => {
    auth.sessionExpired = false;

    render(<SessionMonitor />);

    expect(screen.queryByText(/session expired/i)).not.toBeInTheDocument();
  });

  it("announces an expired session", () => {
    auth.sessionExpired = true;

    render(<SessionMonitor />);

    expect(screen.getByText(/session expired/i)).toBeInTheDocument();
  });

  it("cannot be dismissed while the session is still expired", async () => {
    const user = userEvent.setup();
    auth.sessionExpired = true;

    render(<SessionMonitor />);
    expect(screen.getByText(/session expired/i)).toBeInTheDocument();

    // Escape used to close it, because "open" was a copy of sessionExpired
    // rather than sessionExpired itself. That left the reader in an application
    // that could no longer reach the server and no longer said so.
    await user.keyboard("{Escape}");

    expect(screen.getByText(/session expired/i)).toBeInTheDocument();
  });
});
