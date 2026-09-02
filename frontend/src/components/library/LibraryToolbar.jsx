import { Link } from "react-router-dom";
import { Folders, Grid3X3, List, Upload } from "lucide-react";
import { Button } from "@/components/ui/button";

const SORT_OPTIONS = [
  ["title-asc", "Title A–Z"],
  ["title-desc", "Title Z–A"],
  ["uploaded-desc", "Recently added"],
  ["uploaded-asc", "Oldest added"],
  ["updated-desc", "Recently updated"],
  ["last-read-desc", "Recently read"],
];

/** The library's heading and the controls that change how it is shown. */
export function LibraryToolbar({ isRefreshing, sort, onSortChange, viewMode, onViewModeChange, onOpenSidebar, uploadUrl }) {
  return (
    <div className="mb-6 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
      <div className="flex items-center gap-3">
        <h1 className="text-2xl font-comic sm:text-3xl">My Comic Library</h1>
        {isRefreshing && <span className="text-sm text-muted-foreground" role="status">Refreshing…</span>}
      </div>
      <div className="grid grid-cols-2 items-stretch gap-2 sm:flex sm:flex-wrap sm:items-center">
        <Button variant="outline" className="w-full sm:w-auto lg:hidden" onClick={onOpenSidebar}>
          <Folders className="mr-2 h-4 w-4" />Folders
        </Button>
        <select
          value={sort}
          onChange={(event) => onSortChange(event.target.value)}
          className="h-10 w-full rounded-md border bg-background px-3 text-sm sm:h-9 sm:w-auto"
          aria-label="Sort comics"
        >
          {SORT_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
        <div className="col-span-2 grid grid-cols-2 rounded-md border p-1 sm:col-span-1 sm:flex" role="group" aria-label="Library view">
          <Button className="w-full sm:w-auto" variant={viewMode === "grid" ? "secondary" : "ghost"} size="sm" onClick={() => onViewModeChange("grid")} aria-pressed={viewMode === "grid"}>
            <Grid3X3 className="mr-2 h-4 w-4" />Grid
          </Button>
          <Button className="w-full sm:w-auto" variant={viewMode === "table" ? "secondary" : "ghost"} size="sm" onClick={() => onViewModeChange("table")} aria-pressed={viewMode === "table"}>
            <List className="mr-2 h-4 w-4" />Table
          </Button>
        </div>
        <Button asChild className="col-span-2 w-full sm:col-span-1 sm:w-auto"><Link to={uploadUrl}><Upload className="mr-2 h-4 w-4" />Upload</Link></Button>
      </div>
    </div>
  );
}
