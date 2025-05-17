import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card.jsx";
import { Button } from "@/components/ui/button.jsx";
import { Link } from "react-router-dom";
import { formatDistance } from "date-fns";

export function ComicCard({ comic }) {
  const hasStartedReading = comic.lastReadPage !== undefined;
  
  return (
    <Card className="comic-card h-full flex flex-col">
      <div className="relative pt-[140%] overflow-hidden">
        <img 
          src={comic.cover} 
          alt={`Cover for ${comic.title}`}
          className="absolute inset-0 w-full h-full object-cover"
        />
        {hasStartedReading && (
          <div className="absolute bottom-0 left-0 right-0 bg-background/80 p-2 text-xs">
            <div className="flex items-center justify-between">
              <span>Page {comic.lastReadPage} of {comic.totalPages}</span>
              <span className="text-comic-orange">
                {comic.lastReadAt && formatDistance(new Date(comic.lastReadAt), new Date(), { addSuffix: true })}
              </span>
            </div>
            <div className="w-full bg-gray-300 h-1 mt-1 rounded-full overflow-hidden">
              <div 
                className="bg-comic-purple h-full" 
                style={{ width: `${(comic.lastReadPage / comic.totalPages) * 100}%` }} 
              ></div>
            </div>
          </div>
        )}
      </div>
      <CardHeader className="p-4">
        <CardTitle className="line-clamp-1 text-lg">{comic.title}</CardTitle>
        <CardDescription className="line-clamp-2 text-xs">
          {comic.author && `By ${comic.author}`}
          {comic.publisher && ` • ${comic.publisher}`}
        </CardDescription>
      </CardHeader>
      <CardContent className="p-4 pt-0 text-sm line-clamp-2 flex-grow">
        <p>{comic.description}</p>
      </CardContent>
      <CardFooter className="p-4 pt-0">
        <Link to={`/read/${comic.id}`} className="w-full">
          <Button className="w-full bg-comic-purple hover:bg-comic-purple-dark">
            {hasStartedReading ? 'Continue Reading' : 'Start Reading'}
          </Button>
        </Link>
      </CardFooter>
    </Card>
  );
}
