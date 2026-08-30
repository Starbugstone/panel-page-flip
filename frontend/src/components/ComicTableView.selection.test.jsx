import { render, screen } from "@testing-library/react";
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
vi.mock("@/components/TagBadge", () => ({ TagBadge: () => null }));

const comics = [1, 2, 3, 4, 5].map((id) => ({
  id,
  title: `Comic ${id}`,
  pageCount: 20,
  tags: [],
  canEdit: true,
  canDelete: true,
  canShare: true,
}));

const renderTable = () => {
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
      />
    </MemoryRouter>
  );

  return { onShareSelected };
};

const box = (id) => screen.getByLabelText(`Select Comic ${id}`);

const click = async (user, id) => user.click(box(id));

const shiftClick = async (user, id) => {
  await user.keyboard("{Shift>}");
  await user.click(box(id));
  await user.keyboard("{/Shift}");
};

const selectedIds = (onShareSelected, user) =>
  user.click(screen.getByRole("button", { name: /Share selected/i }))
    .then(() => onShareSelected.mock.calls.at(-1)[0]);

/**
 * Range selection, as any file manager does it: click one, shift-click another,
 * get everything between. The share button is how the selection is read back —
 * it is handed exactly the ids the table holds.
 */
describe("ComicTableView range selection", () => {
  it("selects everything between the two clicks", async () => {
    const user = userEvent.setup();
    const { onShareSelected } = renderTable();

    await click(user, 2);
    await shiftClick(user, 4);

    expect(await selectedIds(onShareSelected, user)).toEqual([2, 3, 4]);
  });

  it("extends a range upwards just as well", async () => {
    const user = userEvent.setup();
    const { onShareSelected } = renderTable();

    await click(user, 4);
    await shiftClick(user, 2);

    expect(await selectedIds(onShareSelected, user)).toEqual([2, 3, 4]);
  });

  /**
   * The range takes the state of the comic the anchor click left behind, so
   * shift-clicking back into a selection does not invert what it passes over.
   */
  it("keeps a shorter second range selected rather than unticking it", async () => {
    const user = userEvent.setup();
    const { onShareSelected } = renderTable();

    await click(user, 1);
    await shiftClick(user, 4);
    await shiftClick(user, 2);

    expect(await selectedIds(onShareSelected, user)).toEqual([1, 2, 3, 4]);
  });

  it("unselects a range when the anchor click was an unselect", async () => {
    const user = userEvent.setup();
    const { onShareSelected } = renderTable();

    await click(user, 1);
    await shiftClick(user, 5);
    await click(user, 2);
    await shiftClick(user, 4);

    expect(await selectedIds(onShareSelected, user)).toEqual([1, 5]);
  });

  it("still toggles one comic at a time without shift", async () => {
    const user = userEvent.setup();
    const { onShareSelected } = renderTable();

    await click(user, 1);
    await click(user, 3);
    await click(user, 1);

    expect(await selectedIds(onShareSelected, user)).toEqual([3]);
  });

  /** Selecting all is not an anchor: a later shift-click starts a fresh range. */
  it("takes no anchor from the select-all box", async () => {
    const user = userEvent.setup();
    const { onShareSelected } = renderTable();

    await user.click(screen.getByLabelText("Select all comics"));
    await user.click(screen.getByLabelText("Select all comics"));
    await shiftClick(user, 3);

    expect(await selectedIds(onShareSelected, user)).toEqual([3]);
  });
});
