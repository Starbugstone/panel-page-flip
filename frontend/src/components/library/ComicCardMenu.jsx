import { Edit, FolderInput, MoreVertical, RotateCcw, Share2Icon, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { PAGE_LAYER_CLASSES } from "@/lib/overlay-layers";
import { cn } from "@/lib/utils";

/**
 * The card's actions, sat outside its Link so nothing here has to cancel a
 * navigation.
 *
 * Items use `onSelect`, not `onClick`: Radix closes the menu from its own click
 * handler, and `preventDefault()` on the click event suppressed that — which is
 * how the menu ended up sitting over the dialog it had just opened.
 */
export function ComicCardMenu({ comic, capabilities, onMove, onEdit, onDelete, onShare, onRemoveShared, onResetProgress }) {
  return (
    <div className={cn("absolute right-2 top-2", PAGE_LAYER_CLASSES.cardAction)}>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8 rounded-full bg-black/50 text-white hover:bg-black/70"
            aria-label={`Actions for ${comic.title}`}
          >
            <MoreVertical size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {onMove && (
            <DropdownMenuItem onSelect={onMove}>
              <FolderInput className="mr-2 h-4 w-4" />
              Move to folder
            </DropdownMenuItem>
          )}
          {capabilities.canEdit && (
            <DropdownMenuItem onSelect={onEdit}>
              <Edit className="mr-2 h-4 w-4" />
              Edit Details
            </DropdownMenuItem>
          )}
          {capabilities.canDelete && (
            <DropdownMenuItem onSelect={onDelete}>
              <Trash2 className="mr-2 h-4 w-4" />
              Delete Comic
            </DropdownMenuItem>
          )}
          {capabilities.canShare && (
            <DropdownMenuItem onSelect={onShare}>
              <Share2Icon className="mr-2 h-4 w-4" />
              Share Comic
            </DropdownMenuItem>
          )}
          {/* Removing hides the owner's comic from this collection; it deletes
              nothing, which is why it is worded differently. */}
          {capabilities.isSharedWithMe && (
            <DropdownMenuItem onSelect={onRemoveShared}>
              <Trash2 className="mr-2 h-4 w-4" />
              Remove from my collection
            </DropdownMenuItem>
          )}
          {comic.lastReadPage !== undefined && (
            <DropdownMenuItem onSelect={onResetProgress}>
              <RotateCcw className="mr-2 h-4 w-4" />
              Reset Progress
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}
