import { BookOpen, ShieldAlert } from "lucide-react";

/**
 * A comic's cover, or a stand-in for one.
 *
 * A gated share has no cover URL because the server withheld it, so the
 * placeholder is shown rather than a blurred real one — there is nothing to
 * blur, and that is the point.
 */
export function ShareCover({ src, title, gated = false }) {
  if (!src) {
    return (
      <div className="flex h-24 w-16 flex-none items-center justify-center rounded bg-muted">
        {gated
          ? <ShieldAlert className="h-6 w-6 text-muted-foreground" />
          : <BookOpen className="h-6 w-6 text-muted-foreground" />}
      </div>
    );
  }

  return (
    <img
      src={src}
      alt={`Cover of ${title}`}
      loading="lazy"
      decoding="async"
      className="h-24 w-16 flex-none rounded object-cover"
    />
  );
}
