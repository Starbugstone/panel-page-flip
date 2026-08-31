import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ComicEditDialog } from "./ComicEditDialog";
import { EXPLICIT_FLAG_DESCRIPTION } from "@/lib/sharing";

vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast: vi.fn() }) }));
vi.mock("@/hooks/use-tags.jsx", () => ({
  useTags: () => ({
    tags: [{ id: 1, name: "noir", isGlobal: false, hideFromLibrary: false }],
    addTagToCache: vi.fn(),
    searchTags: vi.fn().mockResolvedValue([]),
    isAdminContext: () => false,
  }),
}));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() } }));
vi.mock("@/lib/api", () => ({ api: { get: vi.fn().mockResolvedValue({ suggestions: [], tags: [] }) } }));
// The suggestions panel reads which providers are configured, which comes from
// the config hook and therefore from auth context this test does not stand up.
vi.mock("@/hooks/use-config", () => ({ useConfig: () => ({ config: { metadataProviders: [] } }) }));

const comic = (overrides = {}) => ({
  id: 3,
  title: "Sandman",
  author: "Neil Gaiman",
  publisher: "Vertigo",
  description: "Dreams.",
  tags: [],
  explicitContent: false,
  ...overrides,
});

const renderDialog = (props = {}) => {
  const onSave = props.onSave ?? vi.fn().mockResolvedValue({});

  const result = render(
    <ComicEditDialog comic={comic()} isOpen onClose={() => {}} onSave={onSave} {...props} />
  );

  return { ...result, onSave };
};

const explicitBox = () => screen.getByRole("checkbox", { name: /explicit content \(18\+\)/i });
const save = () => screen.getByRole("button", { name: /save changes/i });
const settleSuggestions = () => screen.findByText("Nothing to suggest from this comic's file or name.");

describe("ComicEditDialog explicit-content flag", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("explains what marking a comic explicit does to its recipients", async () => {
    renderDialog();
    await settleSuggestions();

    expect(screen.getByText(EXPLICIT_FLAG_DESCRIPTION)).toBeInTheDocument();
    expect(screen.getByText(EXPLICIT_FLAG_DESCRIPTION))
      .toHaveTextContent("confirm they are 18 or older");
  });

  it("gives the dialog an accessible description", async () => {
    renderDialog();
    await settleSuggestions();

    // A screen reader otherwise announces the title and drops the user into an
    // unexplained set of fields — and Radix warns about it on every mount,
    // which is how this was found.
    expect(screen.getByRole("dialog")).toHaveAccessibleDescription(/classified 18\+/i);
  });

  it("is unticked for a comic nobody has classified", async () => {
    renderDialog();
    await settleSuggestions();

    expect(explicitBox()).not.toBeChecked();
  });

  it("restores the flag from the comic it was opened on", async () => {
    renderDialog({ comic: comic({ explicitContent: true }) });
    await settleSuggestions();

    expect(explicitBox()).toBeChecked();
  });

  it("treats a comic with no flag at all as not explicit", async () => {
    // An older payload, or one from an endpoint that does not send the field.
    // Absence is not a classification, and must not read as one either way.
    renderDialog({ comic: comic({ explicitContent: undefined }) });
    await settleSuggestions();

    expect(explicitBox()).not.toBeChecked();
  });

  it("saves the flag through the ordinary update", async () => {
    const user = userEvent.setup();
    const { onSave } = renderDialog();

    await user.click(explicitBox());
    await user.click(save());

    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({
      id: 3,
      title: "Sandman",
      explicitContent: true,
    }));
  });

  it("saves an unticked box as an explicit false, not as an omission", async () => {
    const user = userEvent.setup();
    const { onSave } = renderDialog({ comic: comic({ explicitContent: true }) });

    // Unticking has to be a change. Sending nothing would leave the comic
    // classified 18+ with the owner believing they had cleared it.
    await user.click(explicitBox());
    await user.click(save());

    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ explicitContent: false }));
  });

  it("is untouched by adding or removing tags", async () => {
    const user = userEvent.setup();
    const { onSave } = renderDialog({ comic: comic({ explicitContent: true, tags: ["noir"] }) });

    await user.type(screen.getByLabelText(/add a tag/i), "horror");
    await user.click(screen.getByRole("button", { name: /^add$/i }));
    // The badge's remove control is an icon-only button inside it, so it is
    // reached through the badge rather than by an accessible name it has none of.
    await user.click(within(screen.getByText("noir")).getByRole("button"));

    // Hiding or shelving a comic says nothing about what is inside it, so no
    // tag edit may move the classification in either direction.
    expect(explicitBox()).toBeChecked();

    await user.click(save());
    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ explicitContent: true }));
  });

  describe("structured metadata", () => {
    it("submits the structured fields alongside the rest", async () => {
      const user = userEvent.setup();
      const { onSave } = renderDialog();

      await user.type(screen.getByLabelText(/^series$/i), "The Sandman");
      await user.type(screen.getByLabelText(/^issue$/i), "17");
      await user.click(screen.getByRole("button", { name: /save/i }));

      expect(onSave).toHaveBeenCalledWith(expect.objectContaining({
        series: "The Sandman",
        issueNumber: "17",
      }));
    });

    /** An empty box means "no value", which has to reach the server as null. */
    it("sends an emptied field as null rather than an empty string", async () => {
      const user = userEvent.setup();
      const { onSave } = renderDialog({ comic: { ...comic(), series: "The Sandman" } });

      await user.clear(screen.getByLabelText(/^series$/i));
      await user.click(screen.getByRole("button", { name: /save/i }));

      expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ series: null }));
    });

    it("stages an accepted suggestion into the form instead of saving it", async () => {
      const user = userEvent.setup();
      const { api } = await import("@/lib/api");
      vi.mocked(api.get).mockResolvedValue({
        suggestions: [{ field: "series", current: null, suggested: "The Sandman", source: "filename", fillsGap: true }],
      });

      const { onSave } = renderDialog();
      await user.click(await screen.findByRole("button", { name: /use series the sandman/i }));

      // Staged, not saved.
      expect(onSave).not.toHaveBeenCalled();
      expect(screen.getByLabelText(/^series$/i)).toHaveValue("The Sandman");

      await user.click(screen.getByRole("button", { name: /save/i }));
      expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ series: "The Sandman" }));
    });
  });
});
