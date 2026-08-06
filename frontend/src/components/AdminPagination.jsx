import { ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { PAGE_SIZE_OPTIONS, visibleRange } from "@/lib/admin-list-params";

/**
 * The pager under every admin table.
 *
 * Deliberately plain: previous/next rather than a numbered strip, because the
 * lists are searchable and jumping to page 37 of an unfiltered table is not a
 * thing anyone does on purpose.
 */
export function AdminPagination({ pagination, itemCount, isLoading = false, onPageChange, onLimitChange, label = "results" }) {
  const { page, limit, totalItems, totalPages } = pagination;
  const range = visibleRange(pagination, itemCount);

  return (
    <div className="flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
      <p className="text-sm text-muted-foreground" aria-live="polite">
        {range
          ? `Showing ${range.first}–${range.last} of ${totalItems} ${label}`
          : `No ${label}`}
      </p>

      <div className="flex items-center gap-4">
        <div className="flex items-center gap-2">
          <Label htmlFor="admin-page-size" className="whitespace-nowrap text-sm font-normal text-muted-foreground">
            Per page
          </Label>
          <Select
            value={String(limit)}
            onValueChange={(value) => onLimitChange(Number(value))}
            disabled={isLoading}
          >
            <SelectTrigger id="admin-page-size" className="h-9 w-[84px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {PAGE_SIZE_OPTIONS.map((option) => (
                <SelectItem key={option} value={String(option)}>{option}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => onPageChange(page - 1)}
            disabled={isLoading || page <= 1}
            aria-label="Previous page"
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <span className="whitespace-nowrap text-sm text-muted-foreground">
            Page {page} of {totalPages}
          </span>
          <Button
            variant="outline"
            size="sm"
            onClick={() => onPageChange(page + 1)}
            disabled={isLoading || page >= totalPages}
            aria-label="Next page"
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      </div>
    </div>
  );
}
