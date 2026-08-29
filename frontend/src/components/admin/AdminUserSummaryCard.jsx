import { Card, CardContent } from "@/components/ui/card";

/** One figure about an account, in the row of four above the tabs. */
export function AdminUserSummaryCard({ icon: Icon, label, value }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <Icon className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
        <div className="min-w-0">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
          <p className="truncate font-medium">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}
