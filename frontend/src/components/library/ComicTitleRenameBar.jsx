import { Check, Loader2, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";

export function ComicTitleRenameBar({ session, onAccept, onUndo }) {
  if (!session) return null;

  const plural = session.count === 1 ? "title" : "titles";
  const previewing = session.phase === "preview";

  return (
    <div
      role="status"
      className="sticky top-20 z-20 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/40 bg-background/95 px-4 py-3 shadow-md backdrop-blur"
    >
      <div>
        <p className="font-medium">
          {previewing
            ? `Previewing ${session.count} comic ${plural}`
            : `${session.count} comic ${plural} renamed`}
        </p>
        <p className="text-sm text-muted-foreground">
          {previewing
            ? "Review the padded titles below, then accept or undo the preview."
            : "The originals can be restored until you leave this folder."}
        </p>
      </div>
      <div className="flex gap-2">
        <Button variant="outline" size="sm" onClick={onUndo} disabled={session.isSaving}>
          {session.isSaving ? <Loader2 className="animate-spin" /> : <RotateCcw />}
          {previewing ? "Undo preview" : "Undo rename"}
        </Button>
        {previewing && (
          <Button size="sm" onClick={onAccept} disabled={session.isSaving}>
            {session.isSaving ? <Loader2 className="animate-spin" /> : <Check />}
            {session.isSaving ? "Renaming…" : "Accept rename"}
          </Button>
        )}
      </div>
    </div>
  );
}
