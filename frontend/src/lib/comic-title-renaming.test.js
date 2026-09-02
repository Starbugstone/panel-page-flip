import { describe, expect, it } from "vitest";
import {
  applyComicTitleRenamePreview,
  planComicTitleRenames,
} from "./comic-title-renaming";

const comics = (...titles) => titles.map((title, index) => ({ id: index + 1, title }));

describe("planComicTitleRenames", () => {
  it("pads a numbered series to the width of its largest volume", () => {
    expect(planComicTitleRenames(comics(
      "DragonBall 1",
      "DragonBall 2",
      "DragonBall 10",
      "DragonBall 11",
    ))).toEqual([
      { id: 1, originalTitle: "DragonBall 1", title: "DragonBall 01" },
      { id: 2, originalTitle: "DragonBall 2", title: "DragonBall 02" },
    ]);
  });

  it("recognises common separators and volume labels as the same series", () => {
    expect(planComicTitleRenames(comics(
      "Dragon Ball #1",
      "DragonBall - Vol. 2",
      "DragonBall_issue_10",
    ))).toEqual([
      { id: 1, originalTitle: "Dragon Ball #1", title: "Dragon Ball #01" },
      { id: 2, originalTitle: "DragonBall - Vol. 2", title: "DragonBall - Vol. 02" },
    ]);
  });

  it("pads the varying issue rather than a fixed year suffix", () => {
    expect(planComicTitleRenames(comics(
      "Saga 1 (2024)",
      "Saga 2 (2024)",
      "Saga 12 (2024)",
    ))).toEqual([
      { id: 1, originalTitle: "Saga 1 (2024)", title: "Saga 01 (2024)" },
      { id: 2, originalTitle: "Saga 2 (2024)", title: "Saga 02 (2024)" },
    ]);
  });

  it("only adds zeroes and preserves deliberately wider existing padding", () => {
    expect(planComicTitleRenames(comics("Manga 001", "Manga 2", "Manga 10"))).toEqual([
      { id: 2, originalTitle: "Manga 2", title: "Manga 002" },
      { id: 3, originalTitle: "Manga 10", title: "Manga 010" },
    ]);
  });

  it("leaves unrelated numbers, decimal numbers, and one-off titles alone", () => {
    expect(planComicTitleRenames(comics(
      "1984",
      "2001: A Space Odyssey",
      "Area 51",
      "Edition 1.5",
      "Edition 1.10",
    ))).toEqual([]);
  });

  it("does not stage titles the viewer cannot edit", () => {
    expect(planComicTitleRenames([
      { id: 1, title: "Owned 1", canEdit: true },
      { id: 2, title: "Owned 10", canEdit: true },
      { id: 3, title: "Owned 2", canEdit: false },
    ])).toEqual([
      { id: 1, originalTitle: "Owned 1", title: "Owned 01" },
    ]);
  });
});

describe("applyComicTitleRenamePreview", () => {
  it("shows proposed titles without mutating the loaded library", () => {
    const loaded = comics("DragonBall 1", "DragonBall 10");
    const previewed = applyComicTitleRenamePreview(loaded, [
      { id: 1, originalTitle: "DragonBall 1", title: "DragonBall 01" },
    ]);

    expect(previewed[0]).toEqual(expect.objectContaining({
      title: "DragonBall 01",
      autoRenameOriginalTitle: "DragonBall 1",
    }));
    expect(loaded[0].title).toBe("DragonBall 1");
    expect(previewed[1]).toBe(loaded[1]);
  });
});
