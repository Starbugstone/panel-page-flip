import { useState } from "react";
import { ArrowDownAZ, ArrowUpAZ, ListFilter, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { cn } from "@/lib/utils";

/**
 * A table heading with an attached sort/filter dropdown.
 *
 * The filter is applied explicitly instead of on every keystroke. Admin lists
 * are server-paged, and typing five characters should not issue five database
 * queries merely because the dropdown is open.
 */
export function AdminColumnHeader({
  label,
  sortField,
  filterField,
  sort,
  direction,
  filterValue = "",
  onSort,
  onFilter,
  filterPlaceholder,
  className,
}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(filterValue);
  const activeSort = Boolean(sortField && sort === sortField);
  const activeFilter = Boolean(filterValue);

  const apply = () => {
    if (filterField) onFilter(filterField, draft);
    setOpen(false);
  };

  const clear = () => {
    setDraft("");
    if (filterField) onFilter(filterField, "");
    setOpen(false);
  };

  return (
    <div className={cn("flex items-center gap-1", className)}>
      <span>{label}</span>
      <Popover open={open} onOpenChange={(nextOpen) => {
        if (nextOpen) setDraft(filterValue);
        setOpen(nextOpen);
      }}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className={cn(
              "h-7 w-7 text-muted-foreground",
              (activeSort || activeFilter) && "bg-accent text-foreground"
            )}
            aria-label={`${label} sort and filter`}
            aria-pressed={activeSort || activeFilter}
          >
            {activeSort
              ? (direction === "ASC" ? <ArrowUpAZ /> : <ArrowDownAZ />)
              : <ListFilter />}
          </Button>
        </PopoverTrigger>
        <PopoverContent align="start" className="space-y-3">
          <p className="text-sm font-medium">{label}</p>
          {sortField && (
            <div className="grid grid-cols-2 gap-2">
              <Button
                type="button"
                size="sm"
                variant={activeSort && direction === "ASC" ? "default" : "outline"}
                onClick={() => { onSort(sortField, "ASC"); setOpen(false); }}
              >
                <ArrowUpAZ /> Ascending
              </Button>
              <Button
                type="button"
                size="sm"
                variant={activeSort && direction === "DESC" ? "default" : "outline"}
                onClick={() => { onSort(sortField, "DESC"); setOpen(false); }}
              >
                <ArrowDownAZ /> Descending
              </Button>
            </div>
          )}
          {filterField && (
            <form className="space-y-2" onSubmit={(event) => { event.preventDefault(); apply(); }}>
              <Input
                type="search"
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                placeholder={filterPlaceholder || `Filter ${label.toLowerCase()}…`}
                aria-label={`Filter ${label}`}
                autoFocus={!sortField}
              />
              <div className="flex justify-end gap-2">
                {(activeFilter || draft) && (
                  <Button type="button" size="sm" variant="ghost" onClick={clear}>
                    <X /> Clear
                  </Button>
                )}
                <Button type="submit" size="sm">Apply filter</Button>
              </div>
            </form>
          )}
        </PopoverContent>
      </Popover>
    </div>
  );
}
