import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MetadataSuggestions } from "./MetadataSuggestions";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));

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
    vi.mocked(api.post).mockReset();
    vi.mocked(api.get).mockResolvedValue({ suggestions: [], tags: [] });
    vi.mocked(api.post).mockResolvedValue({ candidates: [], providers: [] });
    configMock.providers = [{ key: "metron", label: "Metron", available: true }];
  });

  describe("choosing which provider to ask", () => {
    const twoProviders = [
      { key: "metron", label: "Metron", available: true },
      { key: "comicvine", label: "Comic Vine", available: true },
    ];

    /**
     * Only the server knows which provider the user's own token works with, and
     * it prefers that one. Naming the first provider from here overruled it, and
     * spent the installation's account in place of the user's.
     */
    it("leaves the provider to the server until somebody picks one", async () => {
      const user = userEvent.setup();
      configMock.providers = twoProviders;

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/7/metadata-suggestions"));

      await user.click(screen.getByRole("button", { name: /search provider/i }));

      await waitFor(() => expect(api.post).toHaveBeenCalledWith(
        "/api/comics/7/metadata-candidates",
        expect.objectContaining({ provider: null })
      ));
      expect(api.post).toHaveBeenCalledTimes(1);
    });

    /**
     * Each provider answering the same question costs its own quota, so a
     * search asks one of them, not all of them.
     */
    it("asks only the provider somebody picked, and can go back to automatic", async () => {
      const user = userEvent.setup();
      configMock.providers = twoProviders;

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await waitFor(() => expect(api.get).toHaveBeenCalled());

      await user.click(screen.getByRole("combobox", { name: /provider to search/i }));
      await user.click(await screen.findByRole("option", { name: "Comic Vine" }));
      await user.click(screen.getByRole("button", { name: /search provider/i }));

      await waitFor(() => expect(api.post).toHaveBeenLastCalledWith(
        "/api/comics/7/metadata-candidates",
        expect.objectContaining({ provider: "comicvine" })
      ));

      await user.click(screen.getByRole("combobox", { name: /provider to search/i }));
      await user.click(await screen.findByRole("option", { name: "Automatic" }));
      await user.click(screen.getByRole("button", { name: /search provider/i }));

      await waitFor(() => expect(api.post).toHaveBeenLastCalledWith(
        "/api/comics/7/metadata-candidates",
        expect.objectContaining({ provider: null })
      ));
    });

    /** The browser is not told who answered, so it does not claim to know. */
    it("names no provider for an empty automatic search among several", async () => {
      const user = userEvent.setup();
      configMock.providers = twoProviders;

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await user.click(screen.getByRole("button", { name: /search provider/i }));

      expect(await screen.findByText("No matches.")).toBeInTheDocument();
    });

    /**
     * The flow break this replaces: the search used to run off the saved
     * comic, so a filename suggestion accepted a moment ago needed a save and
     * a reopen before it could be used.
     */
    it("searches the values in the form rather than the ones last saved", async () => {
      const user = userEvent.setup();

      render(
        <MetadataSuggestions
          comicId={7}
          onAccept={vi.fn()}
          staged={{ series: "The Boys", issueNumber: "7", publishedAt: "2006-11-01", volume: "1" }}
        />
      );
      await waitFor(() => expect(api.get).toHaveBeenCalled());

      await user.click(screen.getByRole("button", { name: /^search /i }));

      await waitFor(() => expect(api.post).toHaveBeenCalledWith(
        "/api/comics/7/metadata-candidates",
        expect.objectContaining({
          query: { series: "The Boys", issueNumber: "7", year: 2006, volume: "1" },
        })
      ));
    });

    it("offers no choice when only one provider is configured", async () => {
      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await screen.findByRole("button", { name: /search metron/i });

      expect(screen.queryByRole("combobox", { name: /provider to search/i })).not.toBeInTheDocument();
    });

    it("cannot search when nothing is available", async () => {
      configMock.providers = [];

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);

      expect(await screen.findByText(/no metadata provider is available/i)).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /search provider/i })).toBeDisabled();
    });

    /**
     * A user is told a provider will not answer, and given a reason only when
     * the reason is theirs. Why the *server's* credential was refused is
     * operator configuration and deliberately not sent.
     */
    it("says a provider it cannot offer is unavailable", async () => {
      configMock.providers = [
        { key: "metron", label: "Metron", available: false, reason: "Metron is currently unavailable." },
      ];

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);

      expect(await screen.findByText(/metron is currently unavailable/i)).toBeInTheDocument();
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
    vi.mocked(api.post).mockResolvedValue({
      providers: [],
      candidates: [{
        candidate: {
          provider: "metron", externalId: "1", series: "Batman", issueNumber: "7",
          title: "The Long Halloween", confidence: "exact",
        },
        suggestions: [suggestion({ field: "issueNumber", suggested: "7", source: "provider" })],
      }],
    });

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/comics/7/metadata-suggestions"));

    expect(api.post).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: /^search /i }));

    expect(await screen.findByText(/Batman #7 — The Long Halloween/)).toBeInTheDocument();
    expect(screen.getByText(/from a provider/)).toBeInTheDocument();
    // How likely the match is, so the first row is not accepted on faith.
    expect(screen.getByText(/exact match/i)).toBeInTheDocument();
  });

  /**
   * A search row carries a fraction of what a provider knows, so the rest is
   * fetched only for the record somebody picked — one request, not one per row.
   */
  it("fetches the full record only when a candidate is chosen", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockImplementation((path) => Promise.resolve(
      path.includes("metadata-record")
        ? {
            candidate: { provider: "metron", externalId: "1", series: "Batman", confidence: "exact", publisher: "DC Comics" },
            suggestions: [suggestion({ field: "publisher", suggested: "DC Comics", source: "provider" })],
            tags: [],
          }
        : {
            providers: [],
            candidates: [{
              candidate: { provider: "metron", externalId: "1", series: "Batman", confidence: "exact" },
              suggestions: [],
            }],
          }
    ));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: /^search /i }));
    await user.click(await screen.findByRole("button", { name: /show everything/i }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/comics/7/metadata-record",
      { provider: "metron", externalId: "1" }
    ));
    expect(await screen.findByText("Publisher")).toBeInTheDocument();
  });

  /**
   * Accepting a record remembers which one it was, so a later refresh asks for
   * that exact issue instead of repeating a fuzzy search.
   */
  it("stages the chosen record alongside the fields it filled", async () => {
    const user = userEvent.setup();
    const onAccept = vi.fn();
    vi.mocked(api.post).mockResolvedValue({
      providers: [],
      candidates: [{
        candidate: { provider: "metron", externalId: "123925", series: "Batman", confidence: "exact" },
        suggestions: [
          suggestion({ field: "series", suggested: "Batman", source: "provider" }),
          suggestion({ field: "publisher", suggested: "DC Comics", source: "provider" }),
        ],
      }],
    });

    render(<MetadataSuggestions comicId={7} onAccept={onAccept} />);
    await user.click(screen.getByRole("button", { name: /^search /i }));
    await user.click(await screen.findByRole("button", { name: /use all 2 fields/i }));

    expect(onAccept).toHaveBeenCalledWith(expect.objectContaining({
      series: "Batman",
      publisher: "DC Comics",
      metadataProvider: "metron",
      metadataExternalId: "123925",
    }));
  });

  /**
   * A record agreeing with everything already in the form used to have no way to
   * be chosen, so it could never be refreshed from afterwards.
   */
  it.each([
    ["no field", []],
    ["one field", [suggestion({ field: "publisher", suggested: "DC Comics", source: "provider" })]],
  ])("stages a record differing in %s as the comic's origin", async (_label, fields) => {
    const user = userEvent.setup();
    const onAccept = vi.fn();
    vi.mocked(api.post).mockResolvedValue({
      providers: [],
      candidates: [{
        candidate: { provider: "metron", externalId: "123925", series: "Batman", confidence: "exact" },
        suggestions: fields,
      }],
    });

    render(<MetadataSuggestions comicId={7} onAccept={onAccept} />);
    await user.click(screen.getByRole("button", { name: /^search /i }));
    await user.click(await screen.findByRole("button", { name: "Use this record" }));

    expect(onAccept).toHaveBeenCalledWith({
      ...Object.fromEntries(fields.map((field) => [field.field, field.suggested])),
      metadataProvider: "metron",
      metadataExternalId: "123925",
    });
    expect(screen.getByRole("button", { name: "Record chosen" })).toBeDisabled();
  });

  describe("what has already been taken from a record", () => {
    const row = (externalId, title, suggested = "DC Comics") => ({
      candidate: { provider: "metron", externalId, series: title, confidence: "high" },
      suggestions: [suggestion({ field: "publisher", suggested, source: "provider" })],
    });

    const search = async (user) => user.click(screen.getByRole("button", { name: /^search /i }));

    /**
     * Regression: acceptance was keyed by source and field. Every row of a search
     * has the same source, so using one row's publisher disabled every other
     * row's, including rows proposing something different.
     */
    it("keeps a different record's proposal for the same field available", async () => {
      const user = userEvent.setup();
      const onAccept = vi.fn();
      vi.mocked(api.post).mockResolvedValue({
        providers: [], candidates: [row("1", "Batman", "DC Comics"), row("2", "Batwoman", "Vertigo")],
      });

      render(<MetadataSuggestions comicId={7} onAccept={onAccept} />);
      await search(user);
      await user.click(await screen.findByRole("button", { name: /use publisher dc comics/i }));

      expect(screen.getByRole("button", { name: /publisher added/i })).toBeDisabled();
      await user.click(screen.getByRole("button", { name: /use publisher vertigo/i }));
      expect(onAccept).toHaveBeenLastCalledWith({ publisher: "Vertigo" });
    });

    /** The same words from another record is still another record's proposal. */
    it("keeps another record's identical proposal available", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockResolvedValue({
        providers: [], candidates: [row("1", "Batman"), row("2", "Batwoman")],
      });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await search(user);
      const [first] = await screen.findAllByRole("button", { name: /use publisher dc comics/i });
      await user.click(first);

      expect(screen.getByRole("button", { name: /publisher added/i })).toBeDisabled();
      expect(screen.getByRole("button", { name: /use publisher dc comics/i })).toBeEnabled();
    });

    /** The expanded record is the same record as its search row. */
    it("treats a record's full view and its search row as one record", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockImplementation((path) => Promise.resolve(
        path.includes("metadata-record")
          ? { ...row("1", "Batman"), tags: [] }
          : { providers: [], candidates: [row("1", "Batman")] }
      ));

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await search(user);
      await user.click(await screen.findByRole("button", { name: /use publisher dc comics/i }));
      await user.click(screen.getByRole("button", { name: /show everything/i }));

      await waitFor(() => expect(screen.getAllByRole("button", { name: /publisher added/i })).toHaveLength(2));
      expect(screen.queryByRole("button", { name: /use publisher dc comics/i })).not.toBeInTheDocument();
    });

    it("starts over when a new search brings new results", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockResolvedValue({ providers: [], candidates: [row("1", "Batman")] });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await search(user);
      await user.click(await screen.findByRole("button", { name: /use publisher dc comics/i }));
      await user.click(screen.getByRole("button", { name: "Use this record" }));
      expect(screen.getByRole("button", { name: "Record chosen" })).toBeDisabled();

      await search(user);

      expect(await screen.findByRole("button", { name: /use publisher dc comics/i })).toBeEnabled();
      expect(screen.getByRole("button", { name: "Use this record" })).toBeEnabled();
    });

    /** A new search says nothing about what came from the file, still on screen. */
    it("remembers what was taken from the file across a search", async () => {
      const user = userEvent.setup();
      vi.mocked(api.get).mockResolvedValue({ suggestions: [suggestion()] });
      vi.mocked(api.post).mockResolvedValue({ providers: [], candidates: [] });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await user.click(await screen.findByRole("button", { name: /use series batman/i }));
      await search(user);

      await screen.findByText(/no matches from metron/i);
      expect(screen.getByRole("button", { name: /series added/i })).toBeDisabled();
    });

    /** A refresh answering differently is a new proposal to consider. */
    it("offers a changed answer from the same record again", async () => {
      const user = userEvent.setup();
      let publisher = "DC Comics";
      vi.mocked(api.post).mockImplementation(() => Promise.resolve({ ...row("1", "Batman", publisher), tags: [] }));

      render(
        <MetadataSuggestions comicId={7} onAccept={vi.fn()} metadataOrigin={{ provider: "metron", externalId: "1" }} />
      );
      await user.click(await screen.findByRole("button", { name: /refresh/i }));
      await user.click(await screen.findByRole("button", { name: /use publisher dc comics/i }));

      publisher = "Vertigo";
      await user.click(screen.getByRole("button", { name: /refresh/i }));

      expect(await screen.findByRole("button", { name: /use publisher vertigo/i })).toBeEnabled();
    });
  });

  describe("refreshing a comic that was already matched", () => {
    /**
     * The point of remembering the external id: ask for that exact record
     * again rather than re-running a fuzzy search and hoping for the same one.
     */
    it("asks for the stored record and renders what came back", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockResolvedValue({
        candidate: { provider: "metron", externalId: "123925", series: "The Boys", confidence: "exact", publisher: "Dynamite" },
        suggestions: [suggestion({ field: "publisher", suggested: "Dynamite", source: "provider" })],
        tags: [],
      });

      render(
        <MetadataSuggestions
          comicId={7}
          onAccept={vi.fn()}
          metadataOrigin={{ provider: "metron", externalId: "123925" }}
        />
      );
      await user.click(await screen.findByRole("button", { name: /refresh/i }));

      await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/comics/7/metadata-refresh", {}));
      expect(await screen.findByText("Publisher")).toBeInTheDocument();
    });

    /** Nothing to refresh means no button to press. */
    it("offers no refresh until a record has been accepted", async () => {
      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
      await screen.findByRole("button", { name: /^search /i });

      expect(screen.queryByRole("button", { name: /refresh/i })).not.toBeInTheDocument();
    });

    /** A response carrying no record must not be rendered half-way. */
    it("reports an empty response instead of crashing on it", async () => {
      const user = userEvent.setup();
      vi.mocked(api.post).mockResolvedValue({ suggestions: [], tags: [] });

      render(
        <MetadataSuggestions
          comicId={7}
          onAccept={vi.fn()}
          metadataOrigin={{ provider: "metron", externalId: "123925" }}
        />
      );
      await user.click(await screen.findByRole("button", { name: /refresh/i }));

      expect(await screen.findByText(/came back empty/i)).toBeInTheDocument();
    });
  });

  it("says so when providers return nothing", async () => {
    const user = userEvent.setup();

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: /^search /i }));

    expect(await screen.findByText(/no matches from metron/i)).toBeInTheDocument();
  });

  /**
   * An empty candidate list because a provider was throttled is not the same
   * answer as an empty list because nothing matched.
   */
  it("distinguishes a provider that could not answer from a genuine miss", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      candidates: [],
      providers: [{ key: "metron", label: "Metron", available: false, reason: "Metron is currently unavailable." }],
    });

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: /^search /i }));

    expect(await screen.findByText(/metron is currently unavailable/i)).toBeInTheDocument();
  });

  /** A failing lookup must not take the edit form down with it. */
  it("reports a failed lookup without throwing", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockRejectedValue(new Error("providers unreachable"));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: /^search /i }));

    expect(await screen.findByText(/providers unreachable/i)).toBeInTheDocument();
  });

  describe("genres a file or a provider proposed", () => {
    const genre = (name, overrides = {}) => ({
      name, kind: "genre", source: "comicinfo", exists: false, isGlobal: false, ...overrides,
    });

    /** Nothing is selected by default; accepting is what creates a tag. */
    it("offers genres separately from tags the library already has", async () => {
      vi.mocked(api.get).mockResolvedValue({
        suggestions: [],
        tags: [
          { name: "marvel", kind: "library", isGlobal: false, exists: true, source: "library", matchedField: "publisher", matchedValue: "Marvel" },
          genre("Superhero"),
        ],
      });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} onAddTag={vi.fn()} />);

      expect(await screen.findByText(/suggested tags/i)).toBeInTheDocument();
      expect(screen.getByText(/tags you already use/i)).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /add tag superhero/i })).toBeInTheDocument();
    });

    it("hands an accepted genre back rather than applying it", async () => {
      const user = userEvent.setup();
      const onAddTag = vi.fn();
      vi.mocked(api.get).mockResolvedValue({ suggestions: [], tags: [genre("Superhero")] });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} onAddTag={onAddTag} />);
      await user.click(await screen.findByRole("button", { name: /add tag superhero/i }));

      expect(onAddTag).toHaveBeenCalledWith("Superhero");
      // A suggestion, not a write.
      expect(api.post).not.toHaveBeenCalled();
    });

    /** A long provider response must not become a wall of buttons. */
    it("collapses a long list behind a count", async () => {
      vi.mocked(api.get).mockResolvedValue({
        suggestions: [],
        tags: ["Superhero", "Crime", "Satire", "Action", "Horror", "Western"].map((name) => genre(name)),
      });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} onAddTag={vi.fn()} />);

      expect(await screen.findByRole("button", { name: /2 more/i })).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /add tag western/i })).not.toBeInTheDocument();
    });

    /**
     * Characters, teams, locations and story arcs are metadata, never tags: a
     * crossover names dozens and a library filed that way is nobody's.
     */
    it("shows characters and story arcs as metadata rather than as tags", async () => {
      vi.mocked(api.get).mockResolvedValue({
        suggestions: [],
        tags: [],
        classification: { characters: ["Billy Butcher"], storyArcs: ["Herogasm"] },
      });

      render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} onAddTag={vi.fn()} />);

      expect(await screen.findByText(/billy butcher/i)).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /add tag billy butcher/i })).not.toBeInTheDocument();
    });
  });

  it("stays quiet when the suggestion load fails", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("nope"));

    render(<MetadataSuggestions comicId={7} onAccept={vi.fn()} />);

    expect(await screen.findByText(/nothing to suggest/i)).toBeInTheDocument();
  });
});
