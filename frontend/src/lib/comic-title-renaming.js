const NUMBER = /\d+/g;
const DECIMAL_DIGIT = /\d/;
const SEQUENCE_LABEL = /(?:^|[\s._#:-])(?:volume|vol|issue|iss|number|num|no|book|chapter|ch|part|pt|v)$/iu;

const normaliseKey = (value) => value
  .normalize("NFKC")
  .toLocaleLowerCase()
  .replace(/[^\p{L}\p{N}]+/gu, "");

const normalisePrefix = (value) => {
  const withoutTrailingSeparators = value.normalize("NFKC").replace(/[\s._#:-]+$/gu, "");
  return normaliseKey(withoutTrailingSeparators.replace(SEQUENCE_LABEL, ""));
};

const isDecimalPart = (title, start, end) => (
  (title[start - 1] === "." && DECIMAL_DIGIT.test(title[start - 2] || ""))
  || (title[end] === "." && DECIMAL_DIGIT.test(title[end + 1] || ""))
);

const numberCandidates = (comic) => {
  const title = comic.title || "";
  return [...title.matchAll(NUMBER)]
    .filter((match) => !isDecimalPart(title, match.index, match.index + match[0].length))
    .map((match) => {
      const start = match.index;
      const end = start + match[0].length;
      return {
        comic,
        digits: match[0],
        start,
        end,
        value: match[0].replace(/^0+(?=\d)/, ""),
        group: `${normalisePrefix(title.slice(0, start))}\u0000${normaliseKey(title.slice(end))}`,
      };
    });
};

/**
 * Find numeric positions that demonstrably form a sequence, then make every
 * member as wide as the widest one already present. Existing zeroes are never
 * removed, and punctuation stays exactly as the uploader wrote it.
 */
export function planComicTitleRenames(comics) {
  const editableComics = (comics || []).filter((comic) => comic.canEdit !== false && typeof comic.title === "string");
  const groups = new Map();

  for (const comic of editableComics) {
    for (const candidate of numberCandidates(comic)) {
      const group = groups.get(candidate.group) || [];
      group.push(candidate);
      groups.set(candidate.group, group);
    }
  }

  const replacementsByComic = new Map();
  for (const candidates of groups.values()) {
    const comicIds = new Set(candidates.map(({ comic }) => String(comic.id)));
    const values = new Set(candidates.map(({ value }) => value));
    if (comicIds.size < 2 || values.size < 2) continue;

    const width = Math.max(...candidates.map(({ digits }) => digits.length));
    for (const candidate of candidates) {
      if (candidate.digits.length >= width) continue;
      const comicReplacements = replacementsByComic.get(candidate.comic) || new Map();
      comicReplacements.set(candidate.start, { ...candidate, width });
      replacementsByComic.set(candidate.comic, comicReplacements);
    }
  }

  const renames = [];
  for (const comic of editableComics) {
    const replacements = replacementsByComic.get(comic);
    if (!replacements) continue;

    let title = comic.title;
    for (const replacement of [...replacements.values()].sort((left, right) => right.start - left.start)) {
      title = `${title.slice(0, replacement.start)}${replacement.digits.padStart(replacement.width, "0")}${title.slice(replacement.end)}`;
    }
    if (title !== comic.title) {
      renames.push({ id: comic.id, originalTitle: comic.title, title });
    }
  }

  return renames;
}

export function applyComicTitleRenamePreview(comics, renames) {
  if (!renames?.length) return comics;

  const byId = new Map(renames.map((rename) => [String(rename.id), rename]));
  return comics.map((comic) => {
    const rename = byId.get(String(comic.id));
    if (!rename) return comic;
    return {
      ...comic,
      title: rename.title,
      autoRenameOriginalTitle: rename.originalTitle,
    };
  });
}
