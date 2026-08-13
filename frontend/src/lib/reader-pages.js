export function createComicPageUrls(comicId, pageCount) {
  if (!comicId || !Number.isInteger(pageCount) || pageCount <= 0) return [];

  const encodedComicId = encodeURIComponent(String(comicId));
  return Array.from(
    { length: pageCount },
    (_, index) => `/api/comics/${encodedComicId}/pages/${index + 1}`
  );
}
