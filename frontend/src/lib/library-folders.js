export function foldersByParent(folders = []) {
  const grouped = new Map();
  folders.forEach((folder) => {
    const key = folder.parentId ?? null;
    grouped.set(key, [...(grouped.get(key) || []), folder]);
  });
  grouped.forEach((items) => items.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: "base" })));
  return grouped;
}

export function folderAncestors(folders = [], folderId) {
  const byId = new Map(folders.map((folder) => [Number(folder.id), folder]));
  const result = [];
  const visited = new Set();
  let current = byId.get(Number(folderId));
  while (current && !visited.has(Number(current.id))) {
    visited.add(Number(current.id));
    result.unshift(current);
    current = current.parentId == null ? null : byId.get(Number(current.parentId));
  }
  return result;
}

export function folderDescendantIds(folders = [], folderId) {
  const grouped = foldersByParent(folders);
  const result = new Set([Number(folderId)]);
  const visit = (id) => {
    (grouped.get(Number(id)) || []).forEach((child) => {
      result.add(Number(child.id));
      visit(child.id);
    });
  };
  visit(folderId);
  return result;
}
