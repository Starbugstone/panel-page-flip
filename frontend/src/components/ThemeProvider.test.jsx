import { render, screen, act } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ThemeProvider, useTheme } from "./ThemeProvider";

const { getCookie, setCookie } = vi.hoisted(() => ({
  getCookie: vi.fn(),
  setCookie: vi.fn(),
}));

vi.mock("@/lib/cookies", () => ({ getCookie, setCookie }));

function Probe() {
  const { theme, setTheme } = useTheme();
  return (
    <button type="button" onClick={() => setTheme("dark")}>
      current:{theme}
    </button>
  );
}

const renderProvider = () =>
  render(
    <ThemeProvider>
      <Probe />
    </ThemeProvider>
  );

let store;

beforeEach(() => {
  getCookie.mockReset().mockReturnValue(null);
  setCookie.mockReset();
  store = new Map();
  vi.stubGlobal("localStorage", {
    getItem: (k) => (store.has(k) ? store.get(k) : null),
    setItem: (k, v) => store.set(k, String(v)),
    removeItem: (k) => store.delete(k),
  });
  document.documentElement.className = "";
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("ThemeProvider", () => {
  it("starts on the default theme and puts it on the root element", () => {
    renderProvider();

    expect(screen.getByRole("button")).toHaveTextContent("current:light");
    expect(document.documentElement.classList.contains("light")).toBe(true);
  });

  it("prefers the cookie over localStorage", () => {
    getCookie.mockReturnValue("dark");
    store.set("comic-reader-theme", "light");

    renderProvider();

    expect(screen.getByRole("button")).toHaveTextContent("current:dark");
  });

  it("migrates a localStorage theme to a cookie when no cookie is set", () => {
    store.set("comic-reader-theme", "dark");

    renderProvider();

    expect(screen.getByRole("button")).toHaveTextContent("current:dark");
    expect(setCookie).toHaveBeenCalledWith("comic-reader-theme", "dark");
  });

  it("applies and persists a change", async () => {
    renderProvider();

    await userEvent.click(screen.getByRole("button"));

    expect(screen.getByRole("button")).toHaveTextContent("current:dark");
    expect(document.documentElement.classList.contains("dark")).toBe(true);
    expect(setCookie).toHaveBeenCalledWith("comic-reader-theme", "dark", 365);
    expect(store.get("comic-reader-theme")).toBe("dark");
  });

  // The regression: persistence used to run before the state update, inside the
  // same try, so a browser that refuses site data swallowed the theme change
  // along with the failed write.
  it("still applies the theme when localStorage refuses the write", async () => {
    vi.stubGlobal("localStorage", {
      getItem: () => null,
      setItem: () => { throw new DOMException("QuotaExceededError"); },
      removeItem: () => {},
    });

    renderProvider();
    await userEvent.click(screen.getByRole("button"));

    expect(screen.getByRole("button")).toHaveTextContent("current:dark");
    expect(document.documentElement.classList.contains("dark")).toBe(true);
    // The cookie is the one that actually has to carry it.
    expect(setCookie).toHaveBeenCalledWith("comic-reader-theme", "dark", 365);
  });

  it("still applies the theme when the cookie write throws", async () => {
    setCookie.mockImplementation(() => { throw new Error("cookies blocked"); });

    renderProvider();
    await userEvent.click(screen.getByRole("button"));

    expect(screen.getByRole("button")).toHaveTextContent("current:dark");
    expect(document.documentElement.classList.contains("dark")).toBe(true);
    // A failed cookie must not cost the localStorage write too.
    expect(store.get("comic-reader-theme")).toBe("dark");
  });

  it("falls back to the default when reading storage throws", () => {
    getCookie.mockImplementation(() => { throw new Error("no cookies here"); });

    renderProvider();

    expect(screen.getByRole("button")).toHaveTextContent("current:light");
  });

  it("swaps the root class rather than accumulating themes", async () => {
    renderProvider();
    expect(document.documentElement.classList.contains("light")).toBe(true);

    await act(async () => {
      await userEvent.click(screen.getByRole("button"));
    });

    expect(document.documentElement.classList.contains("dark")).toBe(true);
    expect(document.documentElement.classList.contains("light")).toBe(false);
  });
});
