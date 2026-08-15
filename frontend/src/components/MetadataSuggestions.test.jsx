import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MetadataSuggestions } from "./MetadataSuggestions";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));

const configMock = vi.hoisted(() => ({ providers: [] }));
vi.mock("@/hooks/use-config", () => ({
  useConfig: () => ({ config: { metadataProviders: configMock.providers } }),
}));

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
    vi.mocked(api.get).mockResolvedValue({ suggestions: [], tags: [] });
    configMock.providers = [{ key: "metron", label: "Metron" }];
  });

  describe("choosing which provider to ask", () => {
    /**
     * Each provider answering the same question costs its own quota, so a
     * search asks one of them, not all of them.
     */
    it("asks only the chosen provider", async () => {
      const user = userEvent.setup();
      configMock.providers = [
        { key: "metron", label: "Metron" },
        { key: "comicvine", label: "Comic Vine" },
      ];
      vi.mocked(api.get).mockResolvedValue({ suggestions: [], tags: [], candidates: [] });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/7/metadata-suggestions"));

      await user.click(screen.getByRole("button", { name: /search provider/i }));

      await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/7/metadata-candidates?provider=metron"));
      expect(api.get).not.toHaveBeenCalledWith("/api/comics/7/metadata-candidates");
    });

    it("offers no choice when only one provider is configured", async () => {
      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await screen.findByRole("button", { name: /search metron/i });

      expect(screen.queryByRole("combobox", { name: /provider to search/i })).not.toBeInTheDocument();
    });

    it("cannot search when nothing is configured", async () => {
      configMock.providers = [];

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);

      expect(await screen.findByText(/no metadata provider is configured/i)).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /search provider/i })).toBeDisabled();
    });
  });

  describe("tags the library already has", () => {
    const tag = (overrides = {}) => ({
      name: "marvel", isGlobal: false, matchedField: "publisher", matchedValue: "Marvel Comics", ...overrides,
    });

    it("offers a matching tag and says what matched", async () => {
      vi.mocked(api.get).mockResolvedValue({ suggestions: [], tags: [tag()] });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} onAddTag={vi.fn()} />);

      const button = await screen.findByRole("button", { name: /add tag marvel/i });
      expect(button).toHaveAttribute("title", "Matches publisher: Marvel Comics");
    });

    /** Adding stages the tag into the form; it is not saved by itself. */
    it("hands the tag back rather than applying it", async () => {
      const user = userEvent.setup();
      const onAddTag = vi.fn();
      vi.mocked(api.get).mockResolvedValue({ suggestions: [], tags: [tag()] });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} onAddTag={onAddTag} />);
      await user.click(await screen.findByRole("button", { name: /add tag marvel/i }));

      expect(onAddTag).toHaveBeenCalledWith("marvel");
    });

    /** Nothing left to propose means no section, not an empty box. */
    it("does not offer a tag the comic already has", async () => {
      vi.mocked(api.get).mockResolvedValue({ suggestions: [], tags: [tag()] });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} onAddTag={vi.fn()} currentTags={["Marvel"]} />);
      await screen.findByText(/nothing to suggest/i);

      expect(screen.queryByRole("button", { name: /add tag marvel/i })).not.toBeInTheDocument();
      expect(screen.queryByText(/tags you already use/i)).not.toBeInTheDocument();
    });
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
        : { suggestions: [], tags: [] }
    ));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/7/metadata-suggestions"));

    expect(api.get).not.toHaveBeenCalledWith("/api/comics/7/metadata-candidates");

    await user.click(screen.getByRole("button", { name: /^search /i }));

    expect(await screen.findByText(/Batman #7 — The Long Halloween/)).toBeInTheDocument();
    expect(screen.getByText(/from a provider/)).toBeInTheDocument();
  });

  it("says so when providers return nothing", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockImplementation((path) => Promise.resolve(
      path.includes("metadata-candidates") ? { candidates: [] } : { suggestions: [], tags: [] }
    ));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: /^search /i }));

    expect(await screen.findByText(/no matches from metron/i)).toBeInTheDocument();
  });

  /** A failing lookup must not take the edit form down with it. */
  it("reports a failed lookup without throwing", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockImplementation((path) => (
      path.includes("metadata-candidates")
        ? Promise.reject(new Error("providers unreachable"))
        : Promise.resolve({ suggestions: [], tags: [] })
    ));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: /^search /i }));

    expect(await screen.findByText(/providers unreachable/i)).toBeInTheDocument();
  });

  it("stays quiet when the suggestion load fails", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("nope"));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);

    expect(await screen.findByText(/nothing to suggest/i)).toBeInTheDocument();
  });
});
