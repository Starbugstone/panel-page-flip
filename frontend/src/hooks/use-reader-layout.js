import { useMemo } from "react";

import { buildReadingUnits, displayOrderFor, readingUnitForPage } from "@/lib/reader-layout";

/**
 * Which reading mode is actually in force, and what that puts on screen.
 *
 * The mode a reader asked for and the mode they get are not always the same:
 * two-page reading needs a landscape screen that is not a phone, and saying so
 * here keeps the preference intact rather than rewriting it when a device is
 * turned. Everything downstream — preloading, page turns, the progress label —
 * works from the effective mode.
 */
export function useReaderLayout({ settings, profile, pageCount, pageGeometry, currentPage }) {
  const effectiveMode = settings.mode === "continuous"
    ? "continuous"
    : settings.mode === "double" && profile.orientation === "landscape" && profile.device !== "phone"
      ? "double"
      : "single";

  const readingUnits = useMemo(
    () => buildReadingUnits(pageCount, pageGeometry, { coverAlone: settings.coverAlone }),
    [pageCount, pageGeometry, settings.coverAlone]
  );
  const currentUnit = useMemo(
    () => effectiveMode === "double" ? readingUnitForPage(readingUnits, currentPage) : [currentPage],
    [currentPage, effectiveMode, readingUnits]
  );
  const visiblePages = useMemo(
    () => displayOrderFor(currentUnit, settings.direction),
    [currentUnit, settings.direction]
  );

  return { effectiveMode, readingUnits, currentUnit, visiblePages };
}
