import { useMemo } from "react";

import { AdminColumnHeader } from "@/components/admin/AdminColumnHeader";
import { AdminDateRangePopover } from "@/components/admin/AdminDateRangePicker";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAdminTableControls } from "@/hooks/use-admin-table-controls";
import { filterAndSortAdminRows } from "@/lib/admin-client-table";
import {
  adminFilterSuggestions,
  matchesAdminDateRange,
  parseAdminDateRange,
  serializeAdminDateRange,
} from "@/lib/admin-table-filters";
import { contentReportLabel } from "@/lib/content-report-review";

export function ContentReportsQueue({
  reports,
  statuses,
  categories,
  filters,
  error,
  loadingDetail,
  onFiltersChange,
  onOpen,
}) {
  const controls = useAdminTableControls({ defaultSort: "createdAt" });
  const visibleReports = useMemo(() => filterAndSortAdminRows(reports, controls, {
    reference: { value: (report) => report.reference },
    category: { value: (report) => contentReportLabel(report.category) },
    reporter: { value: (report) => report.reporterDisplay },
    createdAt: {
      value: (report) => report.createdAt,
      filter: (value, query) => matchesAdminDateRange(value, query),
    },
    target: { value: (report) => report.linkedTarget?.label || "Unresolved" },
    status: { value: (report) => contentReportLabel(report.status) },
  }), [controls, reports]);

  const updateFilter = (field, value) => {
    onFiltersChange((current) => ({ ...current, [field]: value }));
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Content reports</CardTitle>
        <CardDescription>Private legal notice queue. Full allegations and reporter contact details load only when a case is opened.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid items-end gap-3 md:grid-cols-3">
          <FilterSelect label="Status" value={filters.status} onChange={(value) => updateFilter("status", value)} options={statuses} />
          <FilterSelect label="Category" value={filters.category} onChange={(value) => updateFilter("category", value)} options={categories} />
          <AdminDateRangePopover
            label="Submitted"
            align="start"
            value={serializeAdminDateRange({ from: filters.from, to: filters.to })}
            onChange={(value) => {
              const range = parseAdminDateRange(value);
              onFiltersChange((current) => ({ ...current, from: range.from, to: range.to }));
            }}
            className="w-full"
          />
        </div>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b">
                <th className="p-2"><AdminColumnHeader label="Reference" sortField="reference" filterField="reference" filterSuggestions={adminFilterSuggestions(reports, (report) => report.reference)} filterValue={controls.columnFilters.reference} {...controls.headerProps} /></th>
                <th className="p-2"><AdminColumnHeader label="Category" sortField="category" filterField="category" filterType="select" filterOptions={categories.map(contentReportLabel)} filterValue={controls.columnFilters.category} {...controls.headerProps} /></th>
                <th className="p-2"><AdminColumnHeader label="Reporter" sortField="reporter" filterField="reporter" filterSuggestions={adminFilterSuggestions(reports, (report) => report.reporterDisplay)} filterValue={controls.columnFilters.reporter} {...controls.headerProps} /></th>
                <th className="p-2"><AdminColumnHeader label="Submitted" sortField="createdAt" filterField="createdAt" filterType="date" filterValue={controls.columnFilters.createdAt} {...controls.headerProps} /></th>
                <th className="p-2"><AdminColumnHeader label="Target" sortField="target" filterField="target" filterSuggestions={["Unresolved", ...adminFilterSuggestions(reports, (report) => report.linkedTarget?.label)]} filterValue={controls.columnFilters.target} {...controls.headerProps} /></th>
                <th className="p-2"><AdminColumnHeader label="Status" sortField="status" filterField="status" filterType="select" filterOptions={statuses.map(contentReportLabel)} filterValue={controls.columnFilters.status} {...controls.headerProps} /></th>
                <th className="p-2"></th>
              </tr>
            </thead>
            <tbody>
              {visibleReports.map((report) => (
                <tr key={report.id} className="border-b align-top">
                  <td className="p-2 font-mono text-xs">{report.reference}</td>
                  <td className="p-2">{contentReportLabel(report.category)}</td>
                  <td className="p-2">{report.reporterDisplay}</td>
                  <td className="p-2">{new Date(report.createdAt).toLocaleDateString()}</td>
                  <td className="p-2">{report.linkedTarget?.label || "Unresolved"}</td>
                  <td className="p-2"><Badge variant="outline">{contentReportLabel(report.status)}</Badge></td>
                  <td className="p-2"><Button size="sm" variant="outline" disabled={loadingDetail} onClick={() => onOpen(report)} aria-label={`Review ${report.reference}`}>Review</Button></td>
                </tr>
              ))}
              {visibleReports.length === 0 && <tr><td className="p-4 text-muted-foreground" colSpan={7}>No reports match these filters.</td></tr>}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  );
}

function Field({ label, children }) {
  return <div className="space-y-2"><label htmlFor={children.props.id}>{label}</label>{children}</div>;
}

function FilterSelect({ label, value, onChange, options }) {
  const id = `filter-${label.toLowerCase()}`;
  return (
    <Field label={label}>
      <select id={id} className="h-10 rounded-md border bg-background px-3" value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">All</option>
        {options.map((option) => <option key={option} value={option}>{contentReportLabel(option)}</option>)}
      </select>
    </Field>
  );
}
