import { Badge } from "@/components/ui/badge";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";
import { EyeOff } from "lucide-react";

export const HIDDEN_TAG_EXPLANATION = "Comics with this tag stay out of your default library and appear only when you filter by this tag. This provides discretion, not access control.";

export function TagBadge({ tag, hideFromLibrary, className, children, ...props }) {
  const name = typeof tag === "string" ? tag : tag?.name;
  const hidesComics = hideFromLibrary ?? (typeof tag === "object" && tag?.hideFromLibrary === true);
  const badge = (
    <Badge
      variant="outline"
      className={cn(
        hidesComics && "border-pink-400 bg-pink-100 text-pink-800 hover:bg-pink-200 dark:border-pink-500 dark:bg-pink-950/70 dark:text-pink-200 dark:hover:bg-pink-950",
        className,
      )}
      tabIndex={hidesComics ? 0 : undefined}
      {...props}
    >
      {children ?? name}
      {hidesComics && (
        <>
          <EyeOff className="ml-1 h-3 w-3" aria-hidden="true" />
          <span className="sr-only"> Hidden from the default library.</span>
        </>
      )}
    </Badge>
  );

  if (!hidesComics) return badge;

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>{badge}</TooltipTrigger>
        <TooltipContent className="max-w-72">
          <p>{HIDDEN_TAG_EXPLANATION}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
