import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import { ComicTableView } from "./ComicTableView";

vi.mock("@/hooks/use-tags.jsx", () => ({
  useTags: () => ({
    tags: [],
    searchTags: vi.fn().mockResolvedValue([]),
    isAdminContext: () => false,
  }),
}));
// The rows link to the reader and the tag badges ask whether they are being
// rendered for an administrator; neither is what these tests are about.
vi.mock("@/components/TagBadge", () => ({
  TagBadge: ({ tag }) => <span>{typeof tag === "string" ? tag : tag.name}</span>,
}));

const comic = (overrides = {}) => ({
  id: 1,
  title: "Batman #1",
  author: "Writer",
  pageCount: 20,
  readingProgress: null,
  tags: [],
  canEdit: true,
  canDelete: true,
  canShare: true,
  ...overrides,
});

const renderTable = (comics, props = {}) => {
  const onShareSelected = vi.fn();

  render(
    <MemoryRouter>
      <ComicTableView
        comics={comics}
        folders={[]}
        onEditComic={vi.fn()}
        onBulkAddTag={vi.fn()}
        onBulkDelete={vi.fn()}
        onBulkMove={vi.fn()}
        onShareSelected={onShareSelected}
        {...props}
      />
    </MemoryRouter>
  );

  return { onShareSelected };
};

const select = async (user, title) => {
  await user.click(screen.getByLabelText(`Select ${title}`));
};

/**
 * Sharing from the table.
 *
 * The point of the button is that the selection the user already made is the
 * selection that gets shared. Asking them to pick the same comics again in the
 * dialog's own list is a step that can only go wrong.
 */
describe("ComicTableView sharing", () => {
  it("shows the previous title while a rename is being previewed", () => {
    renderTable([comic({ title: "Batman #01", autoRenameOriginalTitle: "Batman #1" })]);

    expect(screen.getByText("Was Batman #1")).toBeInTheDocument();
  });

  it("hands the existing selection straight to the share workflow", async () => {
    const user = userEvent.setup();
    const { onShareSelected } = renderTable([
      comic(),
      comic({ id: 2, title: "Superman #1" }),
    ]);

    await select(user, "Batman #1");
    await select(user, "Superman #1");
    await user.click(screen.getByRole("button", { name: /Share selected/i }));

    expect(onShareSelected).toHaveBeenCalledWith([1, 2]);
  });

  it("offers nothing to share until something is selected", () => {
    renderTable([comic()]);

    expect(screen.getByRole("button", { name: /Share selected/i })).toBeDisabled();
  });

  /**
   * Blocked and explained rather than silently filtered: a sender told
   * "2 shared" while meaning 3 has been told the wrong thing.
   */
  it("blocks a selection containing a comic that was shared with you", async () => {
    const user = userEvent.setup();
    const { onShareSelected } = renderTable([
      comic(),
      comic({ id: 2, title: "Received Comic", canShare: false }),
    ]);

    await select(user, "Batman #1");
    await select(user, "Received Comic");

    expect(screen.getByRole("button", { name: /Share selected/i })).toBeDisabled();
    expect(screen.getByText(/cannot be shared on/i)).toBeInTheDocument();
    expect(onShareSelected).not.toHaveBeenCalled();
  });

  it("says so rather than truncating when more than twenty are selected", async () => {
    const user = userEvent.setup();
    const comics = Array.from({ length: 21 }, (_, index) => comic({
      id: index + 1,
      title: `Comic ${index + 1}`,
    }));

    renderTable(comics);
    await user.click(screen.getByLabelText("Select all comics"));

    expect(screen.getByRole("button", { name: /Share selected/i })).toBeDisabled();
    expect(screen.getByText(/at most 20 comics at once/i)).toBeInTheDocument();
  });

  it("collapses desktop columns into the comic cell on narrow screens", () => {
    const title = "A very long comic title that must fit on a phone";
    renderTable([
      comic({
        title,
        author: "Long Form Writer",
        tags: ["Adventure"],
        libraryFolderId: 7,
      }),
    ], { folders: [{ id: 7, name: "A shelf with a long name" }] });

    expect(screen.getByRole("table")).toHaveClass("table-fixed", "sm:table-auto");
    expect(screen.getByRole("table")).not.toHaveClass("min-w-[920px]");
    expect(screen.getByRole("columnheader", { name: "Author" })).toHaveClass("hidden", "md:table-cell");
    expect(screen.getByRole("columnheader", { name: "Tags" })).toHaveClass("hidden", "xl:table-cell");

    const comicCell = screen.getByRole("link", { name: title }).closest("td");
    expect(comicCell).toHaveClass("min-w-0", "px-2", "sm:px-4");
    expect(within(comicCell).getByText("Long Form Writer")).toHaveClass("md:hidden");
    expect(within(comicCell).getByText("A shelf with a long name")).toHaveClass("xl:hidden");
    expect(within(comicCell).getByText("Adventure")).toBeInTheDocument();
    expect(within(comicCell).getByText("Not started")).toHaveClass("sm:hidden");
  });
});
