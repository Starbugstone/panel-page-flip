import { useId, useMemo, useRef, useState } from "react";
import { ArrowDownAZ, ArrowUpAZ, CalendarRange, ListFilter, X } from "lucide-react";

import { AdminDateRangeCalendar } from "@/components/admin/AdminDateRangePicker";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { parseAdminDateRange } from "@/lib/admin-table-filters";
import { cn } from "@/lib/utils";

const EMPTY_SUGGESTIONS = [];

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
  filterType = "text",
  filterSuggestions = EMPTY_SUGGESTIONS,
  emptyDateLabel,
  className,
}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(filterValue);
  const suggestionsRef = useRef(null);
  const suggestionsId = useId();
  const activeSort = Boolean(sortField && sort === sortField);
  const activeFilter = Boolean(filterValue);
  const isDate = filterType === "date";
  const draftRange = isDate ? parseAdminDateRange(draft) : null;
  const invalidDateRange = Boolean(draftRange && (
    !draftRange.valid
    || (draftRange.from && draftRange.to && draftRange.from > draftRange.to)
  ));
  const matchingSuggestions = useMemo(() => {
    if (isDate) return [];
    const query = String(draft ?? "").trim().toLocaleLowerCase();
    if (!query) return [];

    return [...new Map(filterSuggestions.map((value) => {
      const text = String(value ?? "").trim();
      return [text.toLocaleLowerCase(), text];
    })).values()]
      .filter((value) => value && value.toLocaleLowerCase().includes(query))
      .sort((left, right) => {
        const leftStarts = left.toLocaleLowerCase().startsWith(query);
        const rightStarts = right.toLocaleLowerCase().startsWith(query);
        return leftStarts === rightStarts
          ? left.localeCompare(right, undefined, { numeric: true, sensitivity: "base" })
          : (leftStarts ? -1 : 1);
      })
      .slice(0, 6);
  }, [draft, filterSuggestions, isDate]);

  const apply = () => {
    if (invalidDateRange) return;
    if (filterField) onFilter(filterField, draft);
    setOpen(false);
  };

  const clear = () => {
    setDraft("");
    if (filterField) onFilter(filterField, "");
    setOpen(false);
  };

  const chooseSuggestion = (suggestion) => {
    setDraft(suggestion);
    if (filterField) onFilter(filterField, suggestion);
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
              "relative h-7 w-7 text-muted-foreground",
              (activeSort || activeFilter) && "bg-accent text-foreground"
            )}
            aria-label={`${label} sort and filter`}
            aria-pressed={activeSort || activeFilter}
          >
            {activeSort
              ? (direction === "ASC" ? <ArrowUpAZ /> : <ArrowDownAZ />)
              : (isDate ? <CalendarRange /> : <ListFilter />)}
            {activeFilter && (
              <span className="absolute right-0.5 top-0.5 h-1.5 w-1.5 rounded-full bg-primary" aria-hidden="true" />
            )}
          </Button>
        </PopoverTrigger>
        <PopoverContent
          align="start"
          collisionPadding={8}
          className={cn(
            "space-y-3",
            isDate && "max-h-[var(--radix-popover-content-available-height)] w-[min(20rem,calc(100vw-1rem))] overflow-y-auto",
          )}
        >
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
              {isDate ? (
                <AdminDateRangeCalendar value={draft} onChange={setDraft} emptyDateLabel={emptyDateLabel} />
              ) : (
                <>
                  <Input
                    type="search"
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key !== "ArrowDown" || matchingSuggestions.length === 0) return;
                      event.preventDefault();
                      suggestionsRef.current?.querySelector("button")?.focus();
                    }}
                    placeholder={filterPlaceholder || `Filter ${label.toLowerCase()}…`}
                    aria-label={`Filter ${label}`}
                    aria-autocomplete={filterSuggestions.length > 0 ? "list" : undefined}
                    aria-controls={matchingSuggestions.length > 0 ? suggestionsId : undefined}
                    autoFocus={!sortField}
                  />
                  {matchingSuggestions.length > 0 && (
                    <div
                      ref={suggestionsRef}
                      id={suggestionsId}
                      role="listbox"
                      aria-label={`${label} suggestions`}
                      className="max-h-52 overflow-y-auto rounded-md border bg-popover p-1 shadow-sm"
                    >
                      {matchingSuggestions.map((suggestion) => (
                        <button
                          key={suggestion}
                          type="button"
                          role="option"
                          aria-selected={suggestion === draft}
                          className="flex w-full min-w-0 items-center break-words rounded-sm px-2 py-2 text-left text-sm outline-none hover:bg-accent focus-visible:bg-accent"
                          onClick={() => chooseSuggestion(suggestion)}
                        >
                          {suggestion}
                        </button>
                      ))}
                    </div>
                  )}
                </>
              )}
              <div className="flex justify-end gap-2">
                {(activeFilter || draft) && (
                  <Button type="button" size="sm" variant="ghost" onClick={clear}>
                    <X /> Clear
                  </Button>
                )}
                <Button type="submit" size="sm" disabled={invalidDateRange}>
                  {isDate ? "Apply range" : "Apply filter"}
                </Button>
              </div>
            </form>
          )}
        </PopoverContent>
      </Popover>
    </div>
  );
}
