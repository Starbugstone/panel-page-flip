import { Button } from "@/components/ui/button";
import { LibraryContents } from "@/components/library/LibraryContents";
import { LibraryEmptyState } from "@/components/library/LibraryEmptyState";
import { LibrarySkeleton } from "@/components/library/LibrarySkeleton";

/**
 * Which of the four things the library has to say right now.
 *
 * The order matters. A failed request must not be reported as an empty
 * library, and neither must one that has not arrived yet — both would tell
 * somebody their comics are gone.
 */
export function LibraryResults({ showSkeleton, error, hasContent, onRetry, emptyState, contents }) {
  if (showSkeleton) return <LibrarySkeleton />;

  if (error) {
    return (
      <div className="py-12 text-center">
        <p className="mb-4 text-xl text-destructive">{error}</p>
        <Button onClick={onRetry}>Try Again</Button>
      </div>
    );
  }

  if (!hasContent) return <LibraryEmptyState {...emptyState} />;

  return <LibraryContents {...contents} />;
}
