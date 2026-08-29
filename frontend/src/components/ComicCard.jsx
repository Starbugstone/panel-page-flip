import { Link } from "react-router-dom";
import { BookOpen } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardFooter } from "@/components/ui/card";
import { ComicCardCover } from "@/components/library/ComicCardCover";
import { ComicCardDetails } from "@/components/library/ComicCardDetails";
import { ComicCardDialogs } from "@/components/library/ComicCardDialogs";
import { ComicCardMenu } from "@/components/library/ComicCardMenu";
import { useComicCardActions } from "@/hooks/use-comic-card-actions";
import { describeComicCapabilities } from "@/lib/comic-capabilities";

/** One comic in the grid: its cover, what it is, and what may be done with it. */
export function ComicCard({
  comic,
  coverPriority = false,
  onResetProgress,
  onEditComic,
  onDeleteComic,
  onShareClick,
  onRemoveSharedComic,
  onMoveComic,
  locationName,
}) {
  const capabilities = describeComicCapabilities(comic);
  const actions = useComicCardActions({ comic, onResetProgress, onDeleteComic, onRemoveSharedComic });

  return (
    <>
      <div className="relative">
        <Link to={`/read/${comic.id}`} className="group block">
          <Card className="overflow-hidden border-2 transition-all duration-300 hover:border-comic-purple hover:shadow-lg">
            <ComicCardCover
              comic={comic}
              coverPriority={coverPriority}
              isSharedWithMe={capabilities.isSharedWithMe}
              onResetProgress={() => actions.open("reset")}
            />
            <ComicCardDetails comic={comic} locationName={locationName} />
            <CardFooter className="px-4 pb-4 pt-0">
              <Button variant="secondary" className="w-full">
                <BookOpen className="mr-2 h-4 w-4" />
                {comic.lastReadPage !== undefined ? "Continue Reading" : "Start Reading"}
              </Button>
            </CardFooter>
          </Card>
        </Link>

        <ComicCardMenu
          comic={comic}
          capabilities={capabilities}
          onMove={onMoveComic && (() => onMoveComic(comic))}
          onEdit={() => onEditComic(comic)}
          onDelete={() => actions.open("delete")}
          onShare={() => onShareClick(comic.id, comic.title)}
          onRemoveShared={() => actions.open("remove-shared")}
          onResetProgress={() => actions.open("reset")}
        />
      </div>

      <ComicCardDialogs comic={comic} actions={actions} />
    </>
  );
}
