import { Folder } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";

export function LibraryFolderCard({ folder, onOpen }) {
  return (
    <button type="button" className="text-left" onClick={() => onOpen(folder.id)}>
      <Card className="h-full border-2 transition-colors hover:border-comic-purple hover:bg-muted/40">
        <CardContent className="flex min-h-28 items-center gap-4 p-5">
          <Folder className="h-10 w-10 shrink-0 fill-comic-purple/20 text-comic-purple" />
          <span className="min-w-0 truncate font-semibold">{folder.name}</span>
        </CardContent>
      </Card>
    </button>
  );
}
