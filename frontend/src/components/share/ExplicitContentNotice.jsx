import { ShieldAlert } from "lucide-react";
import { EXPLICIT_GATE_TITLE } from "@/lib/sharing";

export function ExplicitContentNotice() {
  return (
    <p className="mt-1 flex items-center gap-1 text-sm font-medium text-destructive">
      <ShieldAlert className="h-4 w-4" />
      {EXPLICIT_GATE_TITLE}
    </p>
  );
}
