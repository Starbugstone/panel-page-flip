import Fuse from "fuse.js";

export function fuzzyFilter(items, query, keys) {
  const value = query?.trim();
  if (!value) return [...items];

  return new Fuse(items, {
    keys,
    threshold: 0.35,
    distance: 100,
    ignoreLocation: true,
    shouldSort: true,
  }).search(value).map((result) => result.item);
}
