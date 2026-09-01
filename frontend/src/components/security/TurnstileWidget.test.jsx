import { act, render, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TurnstileWidget } from "./TurnstileWidget.jsx";
import { loadTurnstile } from "@/lib/turnstile-loader";

vi.mock("@/lib/turnstile-loader", () => ({
  loadTurnstile: vi.fn(),
}));

let options;
let turnstile;

beforeEach(() => {
  options = null;
  turnstile = {
    render: vi.fn((_element, suppliedOptions) => {
      options = suppliedOptions;
      return "widget-177";
    }),
    reset: vi.fn(),
    remove: vi.fn(),
  };
  vi.mocked(loadTurnstile).mockReset().mockResolvedValue(turnstile);
});

describe("TurnstileWidget", () => {
  it("renders explicitly with the site key and report-specific action", async () => {
    render(<TurnstileWidget siteKey="public-site-key" onToken={vi.fn()} resetKey={0} />);

    await waitFor(() => expect(turnstile.render).toHaveBeenCalledTimes(1));
    expect(options.sitekey).toBe("public-site-key");
    expect(options.action).toBe("content_report");
  });

  it("clears tokens on expiry/error and resets a single-use widget after submission", async () => {
    const onToken = vi.fn();
    const onError = vi.fn();
    const { rerender } = render(
      <TurnstileWidget siteKey="public-site-key" onToken={onToken} onError={onError} resetKey={0} />
    );
    await waitFor(() => expect(options).not.toBeNull());

    act(() => options.callback("browser-token"));
    expect(onToken).toHaveBeenLastCalledWith("browser-token");
    act(() => options["expired-callback"]());
    expect(onToken).toHaveBeenLastCalledWith(null);
    act(() => options["error-callback"]());
    expect(onToken).toHaveBeenLastCalledWith(null);
    expect(onError).toHaveBeenCalled();

    rerender(<TurnstileWidget siteKey="public-site-key" onToken={onToken} onError={onError} resetKey={1} />);
    expect(turnstile.reset).toHaveBeenCalledWith("widget-177");
  });

  it("removes its widget when the report page unmounts", async () => {
    const { unmount } = render(<TurnstileWidget siteKey="public-site-key" onToken={vi.fn()} resetKey={0} />);
    await waitFor(() => expect(turnstile.render).toHaveBeenCalled());

    unmount();

    expect(turnstile.remove).toHaveBeenCalledWith("widget-177");
  });
});
