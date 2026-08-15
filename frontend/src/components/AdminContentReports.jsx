import { useEffect, useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";

const ACTIONS = [
  ["none", "No content action"],
  ["restrict_sharing", "Restrict sharing for comic"],
  ["lift_sharing_restriction", "Lift comic sharing restriction"],
  ["revoke_all_shares", "Revoke all shares"],
  ["quarantine_content", "Quarantine comic"],
  ["lift_quarantine", "Lift comic quarantine"],
  ["restrict_user_sharing", "Restrict account sharing"],
  ["lift_user_sharing_restriction", "Lift account sharing restriction"],
];

const emptyReview = {
  status: "under_review",
  linkedUserId: "",
  linkedComicId: "",
  linkedShareId: "",
  resolutionCode: "",
  resolutionNote: "",
  legalHold: false,
  action: "none",
  notifyOwner: false,
};

export function AdminContentReports() {
  const [reports, setReports] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [categories, setCategories] = useState([]);
  const [filters, setFilters] = useState({ status: "", category: "", from: "", to: "" });
  const [selected, setSelected] = useState(null);
  const [review, setReview] = useState(emptyReview);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value));
    api.get(`/api/admin/content-reports${query.size ? `?${query}` : ""}`)
      .then((data) => {
        if (cancelled) return;
        setReports(data.reports || []);
        setStatuses(data.statuses || []);
        setCategories(data.categories || []);
        setError(null);
      })
      .catch((loadError) => {
        if (!cancelled) setError(loadError.message || "Content reports could not be loaded.");
      });

    return () => { cancelled = true; };
  }, [filters]);

  const open = (report) => {
    setSelected(report);
    setReview({
      status: report.status === "received" ? "under_review" : report.status,
      linkedUserId: report.linkedUser?.id?.toString() || "",
      linkedComicId: report.linkedComic?.id?.toString() || "",
      linkedShareId: report.linkedShare?.id?.toString() || "",
      resolutionCode: report.resolutionCode || "",
      resolutionNote: report.resolutionNote || "",
      legalHold: Boolean(report.legalHold),
      action: "none",
      notifyOwner: false,
    });
  };

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const payload = {
        ...review,
        linkedUserId: nullableId(review.linkedUserId),
        linkedComicId: nullableId(review.linkedComicId),
        linkedShareId: nullableId(review.linkedShareId),
        resolutionCode: review.resolutionCode || null,
        resolutionNote: review.resolutionNote || null,
      };
      const response = await api.patch(`/api/admin/content-reports/${selected.id}`, payload);
      setSelected(response.report);
      setReports((current) => current.map((item) => item.id === response.report.id ? response.report : item));
      setReview((current) => ({ ...current, action: "none", notifyOwner: false }));
    } catch (saveError) {
      setError(saveError.message || "The review could not be saved.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Content reports</CardTitle>
          <CardDescription>Private legal notice queue. Report text and reporter details must not be copied into ordinary logs.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-3 md:grid-cols-4">
            <FilterSelect label="Status" value={filters.status} onChange={(value) => setFilters((current) => ({ ...current, status: value }))} options={statuses} />
            <FilterSelect label="Category" value={filters.category} onChange={(value) => setFilters((current) => ({ ...current, category: value }))} options={categories} />
            <Field label="From"><Input id="reportFrom" type="date" value={filters.from} onChange={(event) => setFilters((current) => ({ ...current, from: event.target.value }))} /></Field>
            <Field label="To"><Input id="reportTo" type="date" value={filters.to} onChange={(event) => setFilters((current) => ({ ...current, to: event.target.value }))} /></Field>
          </div>
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead><tr className="border-b"><th className="p-2">Reference</th><th className="p-2">Category</th><th className="p-2">Reporter</th><th className="p-2">Submitted</th><th className="p-2">Status</th><th className="p-2"></th></tr></thead>
              <tbody>
                {reports.map((report) => (
                  <tr key={report.id} className="border-b align-top">
                    <td className="p-2 font-mono text-xs">{report.reference}</td>
                    <td className="p-2">{label(report.category)}</td>
                    <td className="p-2">{report.reporterOrganization || report.reporterName}</td>
                    <td className="p-2">{new Date(report.createdAt).toLocaleDateString()}</td>
                    <td className="p-2"><Badge variant="outline">{label(report.status)}</Badge></td>
                    <td className="p-2"><Button size="sm" variant="outline" onClick={() => open(report)} aria-label={`Review ${report.reference}`}>Review</Button></td>
                  </tr>
                ))}
                {reports.length === 0 && <tr><td className="p-4 text-muted-foreground" colSpan={6}>No reports match these filters.</td></tr>}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {selected && (
        <Card>
          <CardHeader>
            <CardTitle>Review {selected.reference}</CardTitle>
            <CardDescription>Submitted {new Date(selected.createdAt).toLocaleString()}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <section className="grid gap-4 rounded-md border p-4 md:grid-cols-2">
              <Detail label="Reporter" value={selected.reporterName} />
              <Detail label="Organization" value={selected.reporterOrganization} />
              <Detail label="Role / authority" value={selected.reporterRole} />
              <Detail label="Email" value={selected.reporterEmail} />
              <Detail className="md:col-span-2" label="Reported reference" value={selected.reportedReference} preserve />
              <Detail className="md:col-span-2" label="Explanation" value={selected.explanation} preserve />
            </section>

            <div className="grid gap-4 md:grid-cols-3">
              <Field label="Linked user ID"><Input id="linkedUserId" inputMode="numeric" value={review.linkedUserId} onChange={(event) => setReview((current) => ({ ...current, linkedUserId: event.target.value }))} /></Field>
              <Field label="Linked comic ID"><Input id="linkedComicId" inputMode="numeric" value={review.linkedComicId} onChange={(event) => setReview((current) => ({ ...current, linkedComicId: event.target.value }))} /></Field>
              <Field label="Linked share ID"><Input id="linkedShareId" inputMode="numeric" value={review.linkedShareId} onChange={(event) => setReview((current) => ({ ...current, linkedShareId: event.target.value }))} /></Field>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <Field label="Review status">
                <select id="reviewStatus" className="h-10 w-full rounded-md border bg-background px-3" value={review.status} onChange={(event) => setReview((current) => ({ ...current, status: event.target.value }))}>
                  {statuses.map((status) => <option key={status} value={status}>{label(status)}</option>)}
                </select>
              </Field>
              <Field label="Administrative action">
                <select id="reviewAction" className="h-10 w-full rounded-md border bg-background px-3" value={review.action} onChange={(event) => setReview((current) => ({ ...current, action: event.target.value }))}>
                  {ACTIONS.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
                </select>
              </Field>
              <Field label="Resolution code"><Input id="resolutionCode" value={review.resolutionCode} onChange={(event) => setReview((current) => ({ ...current, resolutionCode: event.target.value }))} maxLength={64} /></Field>
              <Field label="Resolution note"><Textarea id="resolutionNote" value={review.resolutionNote} onChange={(event) => setReview((current) => ({ ...current, resolutionNote: event.target.value }))} maxLength={10000} /></Field>
            </div>

            <div className="flex flex-wrap gap-6">
              <CheckField id="legalHold" checked={review.legalHold} onChange={(checked) => setReview((current) => ({ ...current, legalHold: checked }))}>Keep on legal hold</CheckField>
              <CheckField id="notifyOwner" checked={review.notifyOwner} onChange={(checked) => setReview((current) => ({ ...current, notifyOwner: checked }))}>Notify affected owner without reporter details</CheckField>
            </div>

            {selected.linkedComic && (
              <p className="rounded-md bg-muted p-3 text-sm">
                Comic #{selected.linkedComic.id}: sharing {selected.linkedComic.sharingRestricted ? "restricted" : "available"}; content {selected.linkedComic.quarantined ? "quarantined" : "not quarantined"}.
              </p>
            )}
            <Button onClick={save} disabled={saving}>{saving ? "Saving…" : "Save review"}</Button>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function nullableId(value) {
  const trimmed = value.trim();
  return trimmed === "" ? null : Number.parseInt(trimmed, 10);
}

function label(value) {
  return value ? value.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase()) : "—";
}

function Field({ label: text, children }) {
  return <div className="space-y-2"><Label htmlFor={children.props.id}>{text}</Label>{children}</div>;
}

function FilterSelect({ label: text, value, onChange, options }) {
  const id = `filter-${text.toLowerCase()}`;
  return <Field label={text}><select id={id} className="h-10 rounded-md border bg-background px-3" value={value} onChange={(event) => onChange(event.target.value)}><option value="">All</option>{options.map((option) => <option key={option} value={option}>{label(option)}</option>)}</select></Field>;
}

function Detail({ label: text, value, preserve = false, className = "" }) {
  return <div className={className}><h3 className="text-xs font-semibold uppercase text-muted-foreground">{text}</h3><p className={preserve ? "whitespace-pre-wrap break-words" : "break-words"}>{value || "—"}</p></div>;
}

function CheckField({ id, checked, onChange, children }) {
  return <div className="flex items-center gap-2"><Checkbox id={id} checked={checked} onCheckedChange={(value) => onChange(value === true)} /><Label htmlFor={id}>{children}</Label></div>;
}
