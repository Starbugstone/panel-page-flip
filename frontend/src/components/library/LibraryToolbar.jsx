import { Link } from "react-router-dom";
import { Folders, Grid3X3, List, Upload } from "lucide-react";
import { Button } from "@/components/ui/button";

const SORT_OPTIONS = [
  ["title-asc", "Title A–Z"],
  ["title-desc", "Title Z–A"],
  ["uploaded-desc", "Recently added"],
  ["uploaded-asc", "Oldest added"],
  ["updated-desc", "Recently updated"],
];

/** The library's heading and the controls that change how it is shown. */
export function LibraryToolbar({ isRefreshing, sort, onSortChange, viewMode, onViewModeChange, onOpenSidebar, uploadUrl }) {
  return (
    <div className="mb-6 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
      <div className="flex items-center gap-3">
        <h1 className="text-3xl font-comic">My Comic Library</h1>
        {isRefreshing && <span className="text-sm text-muted-foreground" role="status">Refreshing…</span>}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <Button variant="outline" className="lg:hidden" onClick={onOpenSidebar}>
          <Folders className="mr-2 h-4 w-4" />Folders
        </Button>
        <select
          value={sort}
          onChange={(event) => onSortChange(event.target.value)}
          className="h-9 rounded-md border bg-background px-3 text-sm"
          aria-label="Sort comics"
        >
          {SORT_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
        <div className="flex rounded-md border p-1" aria-label="Library view">
          <Button variant={viewMode === "grid" ? "secondary" : "ghost"} size="sm" onClick={() => onViewModeChange("grid")} aria-pressed={viewMode === "grid"}>
            <Grid3X3 className="mr-2 h-4 w-4" />Grid
          </Button>
          <Button variant={viewMode === "table" ? "secondary" : "ghost"} size="sm" onClick={() => onViewModeChange("table")} aria-pressed={viewMode === "table"}>
            <List className="mr-2 h-4 w-4" />Table
          </Button>
        </div>
        <Button asChild><Link to={uploadUrl}><Upload className="mr-2 h-4 w-4" />Upload</Link></Button>
      </div>
    </div>
  );
}
