import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { Link, MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { RouteAccessibility } from "./RouteAccessibility";

function Navigation() {
  return <><RouteAccessibility /><Link to="/settings">Settings</Link><Link to="/settings?section=tags">Tags</Link><Link to="/dashboard?jump=9">Resume library</Link><main id="main-content" tabIndex={-1}>Content</main></>;
}

describe("route accessibility", () => {
  afterEach(() => vi.restoreAllMocks());

  it("moves focus and resets scroll only when entering a different page", async () => {
    const scroll = vi.spyOn(window, "scrollTo").mockImplementation(() => {});
    render(<MemoryRouter initialEntries={["/dashboard"]}><Navigation /></MemoryRouter>);
    const user = userEvent.setup();
    await user.click(screen.getByRole("link", { name: "Settings" }));
    expect(document.title).toBe("Settings | Panel Page Flip");
    expect(screen.getByRole("main")).toHaveFocus();
    expect(scroll).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole("link", { name: "Tags" }));
    expect(screen.getByRole("link", { name: "Tags" })).toHaveFocus();
    expect(scroll).toHaveBeenCalledTimes(1);
  });

  it("leaves return-to-comic scrolling to the library", async () => {
    const scroll = vi.spyOn(window, "scrollTo").mockImplementation(() => {});
    render(<MemoryRouter initialEntries={["/read/9"]}><Navigation /></MemoryRouter>);
    await userEvent.click(screen.getByRole("link", { name: "Resume library" }));
    expect(scroll).not.toHaveBeenCalled();
  });

  it("keeps invitation tokens out of the document title", () => {
    render(<MemoryRouter initialEntries={["/share/invitation/private-token"]}><Navigation /></MemoryRouter>);
    expect(document.title).toBe("Comic invitation | Panel Page Flip");
  });
});
