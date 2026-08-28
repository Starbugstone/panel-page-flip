import { useCallback, useEffect, useRef, useState } from "react";

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
  ["restrict_sharing", "Restrict sharing for comic", "comic"],
  ["lift_sharing_restriction", "Lift comic sharing restriction", "comic"],
  ["revoke_all_shares", "Revoke all shares", "comic"],
  ["quarantine_content", "Quarantine comic", "comic"],
  ["lift_quarantine", "Lift comic quarantine", "comic"],
  ["restrict_user_sharing", "Restrict account sharing", "user"],
  ["lift_user_sharing_restriction", "Lift account sharing restriction", "user"],
];

const emptyReview = {
  status: "under_review",
  targetType: null,
  targetId: null,
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
  const [search, setSearch] = useState("");
  const [error, setError] = useState(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const deepLinkOpened = useRef(false);

  const setDetail = useCallback((report) => {
    setSelected(report);
    setReview({
      status: report.status === "received" ? "under_review" : report.status,
      targetType: null,
      targetId: null,
      resolutionCode: report.resolutionCode || "",
      resolutionNote: report.resolutionNote || "",
      legalHold: Boolean(report.legalHold),
      action: "none",
      notifyOwner: false,
    });
  }, []);

  const open = useCallback(async (summary) => {
    setLoadingDetail(true);
    setError(null);
    try {
      const response = await api.get("/api/admin/content-reports/" + summary.id);
      setDetail(response.report);
      setSearch("");
    } catch (loadError) {
      setError(loadError.message || "The report details could not be loaded.");
    } finally {
      setLoadingDetail(false);
    }
  }, [setDetail]);

  useEffect(() => {
    let cancelled = false;
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value));
    api.get("/api/admin/content-reports" + (query.size ? "?" + query : ""))
      .then((data) => {
        if (cancelled) return;
        const loadedReports = data.reports || [];
        setReports(loadedReports);
        setStatuses(data.statuses || []);
        setCategories(data.categories || []);
        setError(null);

        if (!deepLinkOpened.current) {
          const requestedId = Number(new URLSearchParams(window.location.search).get("report"));
          const requested = Number.isInteger(requestedId) ? loadedReports.find((report) => report.id === requestedId) : null;
          if (requested) {
            deepLinkOpened.current = true;
            void open(requested);
          }
        }
      })
      .catch((loadError) => {
        if (!cancelled) setError(loadError.message || "Content reports could not be loaded.");
      });
    return () => { cancelled = true; };
  }, [filters, open]);

  const searchCandidates = async () => {
    if (!selected) return;
    setLoadingDetail(true);
    setError(null);
    try {
      const response = await api.get("/api/admin/content-reports/" + selected.id + "?q=" + encodeURIComponent(search.trim()));
      setSelected(response.report);
      setReview((current) => ({ ...current, targetType: null, targetId: null, action: "none" }));
    } catch (loadError) {
      setError(loadError.message || "Target candidates could not be loaded.");
    } finally {
      setLoadingDetail(false);
    }
  };

  const chooseTarget = (candidate) => {
    setReview((current) => ({ ...current, targetType: candidate.type, targetId: candidate.id, action: "none" }));
  };

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const payload = {
        status: review.status,
        resolutionCode: review.resolutionCode || null,
        resolutionNote: review.resolutionNote || null,
        legalHold: review.legalHold,
        action: review.action,
        notifyOwner: review.notifyOwner,
        ...(review.targetType ? { targetType: review.targetType, targetId: review.targetId } : {}),
      };
      const response = await api.patch("/api/admin/content-reports/" + selected.id, payload);
      setDetail(response.report);
      setReports((current) => current.map((item) => item.id === response.report.id ? {
        ...item,
        status: response.report.status,
        reviewedAt: response.report.reviewedAt,
        linkedTarget: linkedTargetFromDetail(response.report),
      } : item));
    } catch (saveError) {
      setError(saveError.message || "The review could not be saved.");
    } finally {
      setSaving(false);
    }
  };

  const candidates = selected?.targetResolution?.candidates || [];
  const chosen = candidates.find((candidate) => candidate.type === review.targetType && candidate.id === review.targetId) || null;
  const effectiveTarget = chosen || linkedTargetFromDetail(selected);
  const incompatibleAction = actionRequirement(review.action) && !supportsAction(effectiveTarget, actionRequirement(review.action));

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Content reports</CardTitle>
          <CardDescription>Private legal notice queue. Full allegations and reporter contact details load only when a case is opened.</CardDescription>
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
              <thead><tr className="border-b"><th className="p-2">Reference</th><th className="p-2">Category</th><th className="p-2">Reporter</th><th className="p-2">Submitted</th><th className="p-2">Target</th><th className="p-2">Status</th><th className="p-2"></th></tr></thead>
              <tbody>
                {reports.map((report) => (
                  <tr key={report.id} className="border-b align-top">
                    <td className="p-2 font-mono text-xs">{report.reference}</td>
                    <td className="p-2">{label(report.category)}</td>
                    <td className="p-2">{report.reporterDisplay}</td>
                    <td className="p-2">{new Date(report.createdAt).toLocaleDateString()}</td>
                    <td className="p-2">{report.linkedTarget?.label || "Unresolved"}</td>
                    <td className="p-2"><Badge variant="outline">{label(report.status)}</Badge></td>
                    <td className="p-2"><Button size="sm" variant="outline" disabled={loadingDetail} onClick={() => open(report)} aria-label={"Review " + report.reference}>Review</Button></td>
                  </tr>
                ))}
                {reports.length === 0 && <tr><td className="p-4 text-muted-foreground" colSpan={7}>No reports match these filters.</td></tr>}
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
            <section className="space-y-4 rounded-md border p-4">
              <h2 className="font-semibold">Submitted identifiers</h2>
              <div className="grid gap-4 md:grid-cols-2">
                <Detail label="Reporter" value={selected.reporterName} />
                <Detail label="Email" value={selected.reporterEmail} />
                <Detail label="Organization" value={selected.reporterOrganization} />
                <Detail label="Role / authority" value={selected.reporterRole} />
                <Detail label="Reference type" value={label(selected.referenceType)} />
                <Detail label="Content title" value={selected.reportedContentTitle} />
                <Detail label="Reported account" value={selected.reportedAccountReference} />
                <Detail className="md:col-span-2" label="Submitted reference" value={selected.reportedReference} preserve />
                <Detail className="md:col-span-2" label="Source context" value={selected.sourceContext} preserve />
                <Detail className="md:col-span-2" label="Explanation" value={selected.explanation} preserve />
              </div>
            </section>

            <section className="space-y-4 rounded-md border p-4">
              <div>
                <h2 className="font-semibold">Target resolution</h2>
                <p className="text-sm text-muted-foreground">{resolutionMessage(selected.targetResolution)}</p>
              </div>
              <div className="flex gap-2">
                <Input aria-label="Search target candidates" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Title, author, publisher, owner, username, or email" />
                <Button variant="outline" onClick={searchCandidates} disabled={loadingDetail || search.trim().length < 2}>{loadingDetail ? "Searching…" : "Search"}</Button>
              </div>
              <div className="grid gap-3 md:grid-cols-2">
                {candidates.map((candidate) => (
                  <article key={candidate.type + ":" + candidate.id} className={"rounded-md border p-3 " + (chosen?.type === candidate.type && chosen?.id === candidate.id ? "border-primary bg-primary/5" : "")}>
                    <Candidate candidate={candidate} />
                    <Button className="mt-3" size="sm" variant={chosen?.type === candidate.type && chosen?.id === candidate.id ? "default" : "outline"} onClick={() => chooseTarget(candidate)}>
                      {chosen?.type === candidate.type && chosen?.id === candidate.id ? "Selected" : "Link to report"}
                    </Button>
                  </article>
                ))}
              </div>
              {candidates.length === 0 && <p className="text-sm text-muted-foreground">No exact application reference matched. Search using the submitted title or account information.</p>}
            </section>

            <section className="space-y-3 rounded-md border p-4">
              <h2 className="font-semibold">Canonical linked target</h2>
              <TargetSummary target={effectiveTarget} snapshot={selected.targetSnapshot} />
            </section>

            <div className="grid gap-4 md:grid-cols-2">
              <Field label="Review status">
                <select id="reviewStatus" className="h-10 w-full rounded-md border bg-background px-3" value={review.status} onChange={(event) => setReview((current) => ({ ...current, status: event.target.value }))}>
                  {statuses.map((status) => <option key={status} value={status}>{label(status)}</option>)}
                </select>
              </Field>
              <Field label="Administrative action">
                <select id="reviewAction" className="h-10 w-full rounded-md border bg-background px-3" value={review.action} onChange={(event) => setReview((current) => ({ ...current, action: event.target.value }))}>
                  {ACTIONS.map(([value, text, requirement]) => <option key={value} value={value} disabled={requirement ? !supportsAction(effectiveTarget, requirement) : false}>{text}</option>)}
                </select>
              </Field>
              <Field label="Resolution code"><Input id="resolutionCode" value={review.resolutionCode} onChange={(event) => setReview((current) => ({ ...current, resolutionCode: event.target.value }))} maxLength={64} /></Field>
              <Field label="Resolution note"><Textarea id="resolutionNote" value={review.resolutionNote} onChange={(event) => setReview((current) => ({ ...current, resolutionNote: event.target.value }))} maxLength={10000} /></Field>
            </div>

            {review.action !== "none" && effectiveTarget && (
              <p className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
                This action will affect <strong>{effectiveTargetLabel(effectiveTarget, actionRequirement(review.action))}</strong>.
              </p>
            )}
            {incompatibleAction && <p role="alert" className="text-sm text-destructive">Choose a compatible canonical target before applying this action.</p>}

            <div className="flex flex-wrap gap-6">
              <CheckField id="legalHold" checked={review.legalHold} onChange={(checked) => setReview((current) => ({ ...current, legalHold: checked }))}>Keep the case record on legal hold</CheckField>
              <CheckField id="notifyOwner" checked={review.notifyOwner} onChange={(checked) => setReview((current) => ({ ...current, notifyOwner: checked }))}>Notify affected owner without reporter details</CheckField>
            </div>
            <p className="text-xs text-muted-foreground">Legal hold preserves this case record. Account and content deletion remain allowed; the minimal linked-target snapshot is retained for case correlation.</p>
            <Button onClick={save} disabled={saving || incompatibleAction}>{saving ? "Saving…" : "Save review"}</Button>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

/**
 * `label` is what the queue's own rows carry, and this rebuilds a row from a
 * saved detail — so it has to carry one too, or a report that was just linked
 * reads as "Unresolved" until the list is fetched again.
 */
function linkedTargetFromDetail(report) {
  if (!report) return null;
  if (report.linkedShare) return { type: "share", id: report.linkedShare.id, label: report.linkedShare.title, title: report.linkedShare.title, owner: report.linkedComic?.owner || report.linkedUser };
  if (report.linkedComic) return { type: "comic", id: report.linkedComic.id, label: report.linkedComic.title, title: report.linkedComic.title, owner: report.linkedComic.owner || report.linkedUser };
  if (report.linkedUser) return { type: "user", id: report.linkedUser.id, label: report.linkedUser.name, name: report.linkedUser.name, email: report.linkedUser.email };
  return null;
}

function actionRequirement(action) {
  return ACTIONS.find(([value]) => value === action)?.[2] || null;
}

function supportsAction(target, requirement) {
  if (!target) return false;
  if (requirement === "comic") return target.type === "comic" || target.type === "share";
  if (requirement === "user") return target.type === "user" || Boolean(target.owner);
  return true;
}

function effectiveTargetLabel(target, requirement) {
  if (requirement === "user") return target.type === "user" ? (target.name || target.email || "selected account") : (target.owner?.name || "the selected content owner");
  return target.title || target.name || (label(target.type) + " #" + target.id);
}

function resolutionMessage(resolution) {
  if (resolution?.status === "exact") return "An exact application-issued reference matched privately. Confirm the human-readable target before linking it.";
  if (resolution?.status === "candidates") return "Possible targets were found. A human administrator must choose the correct record.";
  return "No exact target is linked. Search candidates using the submitted information.";
}

function Candidate({ candidate }) {
  return (
    <div className="space-y-1 text-sm">
      <Badge variant="outline">{label(candidate.type)}</Badge>
      <p className="font-medium">{candidate.title || candidate.name || ("Record #" + candidate.id)}</p>
      {candidate.owner && <p className="text-muted-foreground">Owner: {candidate.owner.name}</p>}
      {candidate.email && <p className="text-muted-foreground">{candidate.email}</p>}
      {candidate.status && <p className="text-muted-foreground">Share status: {label(candidate.status)}</p>}
      <p className="text-xs text-muted-foreground">Internal {candidate.type} ID: {candidate.id}</p>
    </div>
  );
}

function TargetSummary({ target, snapshot }) {
  if (target) {
    return <div><p className="font-medium">{effectiveTargetLabel(target, target.type === "user" ? "user" : "comic")}</p><p className="text-sm text-muted-foreground">{label(target.type)} #{target.id}{target.owner ? " · owner " + target.owner.name : ""}</p></div>;
  }
  if (snapshot?.userId || snapshot?.comicId || snapshot?.shareId) {
    return <div><p className="font-medium">{snapshot.comicTitle || "Previously linked record"}</p><p className="text-sm text-muted-foreground">Live record deleted; retained IDs: user {snapshot.userId || "—"}, comic {snapshot.comicId || "—"}, share {snapshot.shareId || "—"}.</p></div>;
  }
  return <p className="text-sm text-muted-foreground">No target has been confirmed.</p>;
}

function label(value) {
  return value ? value.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase()) : "—";
}

function Field({ label: text, children }) {
  return <div className="space-y-2"><Label htmlFor={children.props.id}>{text}</Label>{children}</div>;
}

function FilterSelect({ label: text, value, onChange, options }) {
  const id = "filter-" + text.toLowerCase();
  return <Field label={text}><select id={id} className="h-10 rounded-md border bg-background px-3" value={value} onChange={(event) => onChange(event.target.value)}><option value="">All</option>{options.map((option) => <option key={option} value={option}>{label(option)}</option>)}</select></Field>;
}

function Detail({ label: text, value, preserve = false, className = "" }) {
  return <div className={className}><h3 className="text-xs font-semibold uppercase text-muted-foreground">{text}</h3><p className={preserve ? "whitespace-pre-wrap break-words" : "break-words"}>{value || "—"}</p></div>;
}

function CheckField({ id, checked, onChange, children }) {
  return <div className="flex items-center gap-2"><Checkbox id={id} checked={checked} onCheckedChange={(value) => onChange(value === true)} /><Label htmlFor={id}>{children}</Label></div>;
}
