import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";

const widths = { wide: "max-w-[1400px]", settings: "max-w-5xl", reading: "max-w-4xl", form: "max-w-3xl" };

export function PageLayout({ children, className, width = "wide", ...props }) {
  return <div className={cn("page-layout", widths[width], className)} {...props}>{children}</div>;
}

export function PageHeader({ title, description, actions, children, className }) {
  return (
    <div className={cn("page-header", className)}>
      <div className="min-w-0 space-y-2">
        <h1 className="page-title">{title}</h1>
        {description && <p className="max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">{description}</p>}
        {children}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </div>
  );
}

export function PageLoading({ label = "Loading page…" }) {
  return (
    <div className="flex min-h-[40vh] items-center justify-center gap-3 px-4 text-sm text-muted-foreground" role="status">
      <Loader2 aria-hidden="true" className="h-5 w-5 animate-spin motion-reduce:animate-none" />
      <span>{label}</span>
    </div>
  );
}
