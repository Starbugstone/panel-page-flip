import { useState } from "react";
import { FolderInput, Share2, Tags, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { TagCombobox } from "@/components/TagCombobox";
import { useTags } from "@/hooks/use-tags.jsx";
import { MAX_SHAREABLE_SELECTION } from "@/lib/comic-selection";
import { describeTagSubmission } from "@/lib/tag-suggestions";

function bulkActionState(selection, availableTags, tagName, canMove) {
  const submission = describeTagSubmission(availableTags, tagName);
  const nothingSelected = selection.selectedComicIds.length === 0;
  const ownerActionDisabled = nothingSelected || selection.isUpdating || !selection.ownerActionsAllowed;

  return {
    submission,
    ownerActionDisabled,
    shareDisabled: !selection.canShareSelection || selection.isUpdating,
    moveDisabled: nothingSelected || selection.isUpdating || !canMove,
    canAddTag: !ownerActionDisabled
      && (submission.status === "existing" || submission.status === "new"),
  };
}

/**
 * What the current selection can be done to, and why some of it cannot.
 *
 * Each refusal is stated rather than left as a disabled button with no
 * explanation: a selection that mixes owned and received comics is a mistake
 * somebody can fix once they know what it is.
 */
export function ComicBulkActionsBar({ selection, totalCount, onShare, onMove, onDelete, canMove }) {
  const { tags: availableTags } = useTags();
  const [tagName, setTagName] = useState("");

  // Cleared only once the tag landed, so a failed request leaves what was typed.
  const addTag = async (name) => {
    if (await selection.addTag(name)) setTagName("");
  };
  // Bulk tagging submits the canonical name of an existing tag where one
  // matches, so picking "sci fi" out of the list does not create a second
  // spelling of "Sci Fi".
  const actions = bulkActionState(selection, availableTags, tagName, canMove);

  return (
    <div className="flex flex-col gap-3 rounded-lg border bg-card p-4 lg:flex-row lg:items-center lg:justify-between">
      <p className="text-sm font-medium" aria-live="polite">
        {selection.selectedComicIds.length} of {totalCount} selected
        {selection.selectedComics.length > 0 && !selection.ownerActionsAllowed && (
          <span className="ml-1 font-normal text-muted-foreground">
            (Move is available; tagging and deletion require owned comics only)
          </span>
        )}
        {selection.shareBlocked && (
          <span className="ml-1 block font-normal text-destructive">
            Comics shared with you cannot be shared on. Clear them from the selection to share the rest.
          </span>
        )}
        {selection.shareOverLimit && (
          <span className="ml-1 block font-normal text-destructive">
            You can share at most {MAX_SHAREABLE_SELECTION} comics at once.
          </span>
        )}
      </p>
      <div className="flex flex-col gap-2 sm:flex-row">
        {/* The selection goes straight through, so nobody is asked to pick the
            same comics a second time in the share dialog's own list. */}
        <Button variant="outline" onClick={onShare} disabled={actions.shareDisabled}>
          <Share2 className="mr-2 h-4 w-4" />
          Share selected
        </Button>
        <Button variant="outline" onClick={onMove} disabled={actions.moveDisabled}>
          <FolderInput className="mr-2 h-4 w-4" />
          Move selected
        </Button>
        <TagCombobox
          value={tagName}
          onChange={setTagName}
          onSubmit={addTag}
          disabled={actions.ownerActionDisabled}
          placeholder="Tag selected comics"
          label="Tag selected comics"
          className="sm:w-56"
        />
        <Button variant="secondary" onClick={() => addTag(actions.submission.name)} disabled={!actions.canAddTag}>
          <Tags className="mr-2 h-4 w-4" />
          Add tag
        </Button>
        <Button
          variant="destructive"
          onClick={onDelete}
          disabled={actions.ownerActionDisabled}
        >
          <Trash2 className="mr-2 h-4 w-4" />
          Delete selected
        </Button>
      </div>
    </div>
  );
}
