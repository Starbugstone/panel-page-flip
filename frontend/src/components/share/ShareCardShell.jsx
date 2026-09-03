import { CardContent } from "@/components/ui/card";

export function ShareCardShell({ media, title, children, actions }) {
  return (
    <CardContent className="flex flex-col gap-4 p-4 sm:flex-row">
      <div className="flex min-w-0 flex-1 gap-4">
        {media}
        <div className="min-w-0 flex-1">
          <h3 className="truncate font-bold">{title}</h3>
          {children}
        </div>
      </div>
      {actions && (
        <div className="flex w-full flex-none flex-col gap-2 sm:w-auto">
          {actions}
        </div>
      )}
    </CardContent>
  );
}
