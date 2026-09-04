import { BookOpen } from "lucide-react";
import { cn } from "@/lib/utils";

export function AuthLayout({ children, title, description, footer, className }) {
  return (
    <div className="auth-layout">
      <section className={cn("auth-card", className)}>
        <div className="mb-7 space-y-3 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
            <BookOpen aria-hidden="true" className="h-6 w-6" />
          </div>
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {description && <p className="text-sm leading-6 text-muted-foreground">{description}</p>}
        </div>
        {children}
        {footer && <div className="mt-6 border-t pt-5 text-center text-sm">{footer}</div>}
      </section>
    </div>
  );
}
