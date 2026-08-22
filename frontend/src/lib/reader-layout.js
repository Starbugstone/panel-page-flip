const DEFAULT_WIDE_ASPECT_RATIO = 1.35;

export function isWideComicPage(pageNumber, geometry = {}, threshold = DEFAULT_WIDE_ASPECT_RATIO) {
  const ratio = geometry?.[pageNumber]?.aspectRatio;
  return Number.isFinite(ratio) && ratio >= threshold;
}

/**
 * Turn canonical logical pages into visual reading units. Wide scans and an
 * optional cover are always alone; ordinary pages pair without ever changing
 * their stored page numbers.
 */
export function buildReadingUnits(pageCount, geometry = {}, { coverAlone = true } = {}) {
  if (!Number.isInteger(pageCount) || pageCount <= 0) return [];

  const units = [];
  let index = 0;
  while (index < pageCount) {
    const pageNumber = index + 1;
    if ((coverAlone && index === 0) || isWideComicPage(pageNumber, geometry)) {
      units.push([index]);
      index += 1;
      continue;
    }

    const nextPageNumber = pageNumber + 1;
    if (index + 1 < pageCount && !isWideComicPage(nextPageNumber, geometry)) {
      units.push([index, index + 1]);
      index += 2;
      continue;
    }

    units.push([index]);
    index += 1;
  }

  return units;
}

export function readingUnitForPage(units, pageIndex) {
  return units.find((unit) => unit.includes(pageIndex)) ?? (Number.isInteger(pageIndex) ? [pageIndex] : []);
}

export function adjacentReadingPage(units, pageIndex, direction) {
  const unitIndex = units.findIndex((unit) => unit.includes(pageIndex));
  if (unitIndex < 0) return null;
  const target = direction === "previous" ? units[unitIndex - 1] : units[unitIndex + 1];
  return target?.[0] ?? null;
}

export function displayOrderFor(unit, direction) {
  return direction === "rtl" ? [...unit].reverse() : [...unit];
}

export function pageRangeLabel(unit) {
  if (!Array.isArray(unit) || unit.length === 0) return "";
  const pages = [...unit].sort((a, b) => a - b).map((index) => index + 1);
  return pages.length === 1 ? String(pages[0]) : `${pages[0]}–${pages[pages.length - 1]}`;
}
