const PLACEHOLDER_COUNT = 6;

/** Cover-shaped placeholders, so the grid does not jump when the real ones land. */
export function LibrarySkeleton() {
  return (
    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
      {[...Array(PLACEHOLDER_COUNT)].map((_, index) => (
        <div key={index} className="animate-pulse">
          <div className="pt-[140%] bg-muted" />
          <div className="mt-2 h-4 rounded bg-muted" />
        </div>
      ))}
    </div>
  );
}
