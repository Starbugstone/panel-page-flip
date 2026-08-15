import { describe, expect, it } from "vitest";
import { folderAncestors, folderDescendantIds, foldersByParent } from "@/lib/library-folders";

const folders = [
  { id: 1, name: "Marvel", parentId: null },
  { id: 2, name: "Spider-Man", parentId: 1 },
  { id: 3, name: "X-Men", parentId: 1 },
  { id: 4, name: "Miles Morales", parentId: 2 },
  { id: 5, name: "DC", parentId: null },
];

describe("library folder tree helpers", () => {
  it("groups and alphabetises siblings", () => {
    expect(foldersByParent(folders).get(null).map((folder) => folder.name)).toEqual(["DC", "Marvel"]);
    expect(foldersByParent(folders).get(1).map((folder) => folder.name)).toEqual(["Spider-Man", "X-Men"]);
  });

  it("builds root-to-leaf breadcrumbs", () => {
    expect(folderAncestors(folders, 4).map((folder) => folder.name)).toEqual(["Marvel", "Spider-Man", "Miles Morales"]);
  });

  it("marks a folder and all descendants as invalid move targets", () => {
    expect([...folderDescendantIds(folders, 2)].sort()).toEqual([2, 4]);
  });

  it("stops safely if malformed data contains a cycle", () => {
    const cyclic = [{ id: 1, name: "A", parentId: 2 }, { id: 2, name: "B", parentId: 1 }];
    expect(folderAncestors(cyclic, 1)).toHaveLength(2);
  });
});
