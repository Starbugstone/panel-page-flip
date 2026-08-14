import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MetadataSuggestions } from "./MetadataSuggestions";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));

const suggestion = (overrides = {}) => ({
  field: "series",
  current: null,
  suggested: "Batman",
  source: "filename",
  fillsGap: true,
  ...overrides,
});

describe("MetadataSuggestions", () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset();
    vi.mocked(api.get).mockResolvedValue({ suggestions: [] });
  });

  it("shows what each proposal would change and where it came from", async () => {
    vi.mocked(api.get).mockResolvedValue({
      suggestions: [suggestion({ current: "Detective Comics", suggested: "Batman" })],
    });

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);

    // The value is wrapped for emphasis, so the line is asserted as a whole
    // rather than as one text node.
    const line = await screen.findByText(/from the filename/);
    expect(line).toHaveTextContent("Detective Comics → Batman");
    expect(screen.getByText("Series")).toBeInTheDocument();
  });

  /**
   * The whole point of the review step: accepting stages a value into the form
   * rather than writing it, so nothing reaches the server without a save.
   */
  it("hands an accepted value back instead of saving it", async () => {
    const user = userEvent.setup();
    const onAccept = vi.fn();
    vi.mocked(api.get).mockResolvedValue({ suggestions: [suggestion()] });

    render(<MetadataSuggestions comicId={7} onAccept={onAccept} />);
    await user.click(await screen.findByRole("button", { name: /use series batman/i }));

    expect(onAccept).toHaveBeenCalledWith({ series: "Batman" });
    // Only the two reads; nothing was written.
    expect(api.get).toHaveBeenCalledTimes(1);
  });

  it("will not offer the same proposal twice", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockResolvedValue({ suggestions: [suggestion()] });

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(await screen.findByRole("button", { name: /use series batman/i }));

    expect(await screen.findByRole("button", { name: /added/i })).toBeDisabled();
  });

  it("searches providers only when asked", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
      path.includes("metadata-candidates")
        ? {
            candidates: [{
              candidate: { provider: "metron", externalId: "1", series: "Batman", issueNumber: "7", title: "The Long Halloween" },
              suggestions: [suggestion({ field: "issueNumber", suggested: "7", source: "provider" })],
            }],
          }
        : { suggestions: [] }
    ));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/7/metadata-suggestions"));

    expect(api.get).not.toHaveBeenCalledWith("/api/comics/7/metadata-candidates");

    await user.click(screen.getByRole("button", { name: /search providers/i }));

    expect(await screen.findByText(/Batman #7 — The Long Halloween/)).toBeInTheDocument();
    expect(screen.getByText(/from a provider/)).toBeInTheDocument();
  });

  it("says so when providers return nothing", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
      path.includes("metadata-candidates") ? { candidates: [] } : { suggestions: [] }
    ));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: /search providers/i }));

    expect(await screen.findByText(/no provider matches/i)).toBeInTheDocument();
  });

  /** A failing lookup must not take the edit form down with it. */
  it("reports a failed lookup without throwing", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockImplementation((path) => (
      path.includes("metadata-candidates")
        ? Promise.reject(new Error("providers unreachable"))
        : Promise.resolve({ suggestions: [] })
    ));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: /search providers/i }));

    expect(await screen.findByText(/providers unreachable/i)).toBeInTheDocument();
  });

  it("stays quiet when the suggestion load fails", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("nope"));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);

    expect(await screen.findByText(/nothing to suggest/i)).toBeInTheDocument();
  });
});
