export function getComicProgressState(comic) {
  const currentPage = comic.readingProgress?.currentPage ?? comic.lastReadPage ?? 0;
  const pageCount = comic.pageCount ?? 0;
  const completed = comic.readingProgress?.completed || (pageCount > 0 && currentPage >= pageCount);

  if (completed) {
    return {
      label: "Fully read",
      badgeClass: "border-emerald-600 bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200",
      rowClass: "bg-emerald-50/70 hover:bg-emerald-100/70 dark:bg-emerald-950/20 dark:hover:bg-emerald-950/30",
      progressClass: "[&>div]:bg-emerald-600",
      currentPage: pageCount || currentPage,
      percent: 100,
    };
  }

  if (!comic.readingProgress && !comic.lastReadPage) {
    return {
      label: "Not started",
      badgeClass: "border-amber-600 bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200",
      rowClass: "bg-amber-50/70 hover:bg-amber-100/70 dark:bg-amber-950/20 dark:hover:bg-amber-950/30",
      progressClass: "[&>div]:bg-amber-500",
      currentPage: 0,
      percent: 0,
    };
  }

  return {
    label: "In progress",
    badgeClass: "border-blue-600 bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200",
    rowClass: "hover:bg-muted/50",
    progressClass: "[&>div]:bg-blue-600",
    currentPage,
    percent: pageCount > 0 ? Math.min(100, Math.round((currentPage / pageCount) * 100)) : 0,
  };
}
