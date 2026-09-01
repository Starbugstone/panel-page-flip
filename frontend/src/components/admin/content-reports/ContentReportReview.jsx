import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  contentReportActionSupports,
  contentReportLabel,
  contentReportResolutionMessage,
  contentReportTargetLabel,
  hasContentReportTargetSnapshot,
} from "@/lib/content-report-review";

export function ContentReportReview({
  report,
  review,
  statuses,
  actions,
  search,
  loadingDetail,
  saving,
  onReviewChange,
  onSearchChange,
  onSearch,
  onChooseTarget,
  onUnlinkTarget,
  onSave,
}) {
  const candidates = report.targetResolution?.candidates || [];
  const target = selectedTarget(report, review, candidates);
  const requirement = actions.find((action) => action.value === review.action)?.requires || null;
  const incompatibleAction = Boolean(requirement && !contentReportActionSupports(target.effective, requirement));

  const updateReview = (changes) => onReviewChange((current) => ({ ...current, ...changes }));

  return (
    <Card>
      <CardHeader>
        <CardTitle>Review {report.reference}</CardTitle>
        <CardDescription>Submitted {new Date(report.createdAt).toLocaleString()}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <SubmittedIdentifiers report={report} />
        <TargetResolution
          report={report}
          candidates={candidates}
          chosen={target.chosen}
          search={search}
          loading={loadingDetail}
          onSearchChange={onSearchChange}
          onSearch={onSearch}
          onChoose={onChooseTarget}
        />
        <CanonicalTarget
          target={target.effective}
          snapshot={target.retainedSnapshot}
          canUnlink={target.canUnlink}
          onUnlink={onUnlinkTarget}
        />
        <ReviewFields
          review={review}
          statuses={statuses}
          actions={actions}
          effectiveTarget={target.effective}
          onChange={updateReview}
        />

        {review.action !== "none" && target.effective && (
          <p className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
            This action will affect <strong>{contentReportTargetLabel(target.effective, requirement)}</strong>.
          </p>
        )}
        {incompatibleAction && <p role="alert" className="text-sm text-destructive">Choose a compatible canonical target before applying this action.</p>}

        <div className="flex flex-wrap gap-6">
          <CheckField id="legalHold" checked={review.legalHold} onChange={(legalHold) => updateReview({ legalHold })}>Keep the case record on legal hold</CheckField>
          <CheckField id="notifyOwner" checked={review.notifyOwner} onChange={(notifyOwner) => updateReview({ notifyOwner })}>Notify affected owner without reporter details</CheckField>
        </div>
        <p className="text-xs text-muted-foreground">Legal hold preserves this case record. Account and content deletion remain allowed; the minimal linked-target snapshot is retained for case correlation.</p>
        <Button onClick={onSave} disabled={saving || incompatibleAction}>{saving ? "Saving…" : "Save review"}</Button>
      </CardContent>
    </Card>
  );
}

function selectedTarget(report, review, candidates) {
  const untouched = review.pendingTarget === undefined;
  const chosen = candidates.find((candidate) => (
    candidate.type === review.pendingTarget?.type && candidate.id === review.pendingTarget?.id
  )) || null;
  const effective = untouched ? (report.linkedTarget ?? null) : chosen;

  return {
    chosen,
    effective,
    retainedSnapshot: !untouched && !chosen ? null : report.targetSnapshot,
    canUnlink: Boolean(effective || (untouched && hasContentReportTargetSnapshot(report.targetSnapshot))),
  };
}

function SubmittedIdentifiers({ report }) {
  return (
    <section className="space-y-4 rounded-md border p-4">
      <h2 className="font-semibold">Submitted identifiers</h2>
      <div className="grid gap-4 md:grid-cols-2">
        <Detail label="Reporter" value={report.reporterName} />
        <Detail label="Email" value={report.reporterEmail} />
        <Detail label="Organization" value={report.reporterOrganization} />
        <Detail label="Role / authority" value={report.reporterRole} />
        <Detail label="Reference type" value={contentReportLabel(report.referenceType)} />
        <Detail label="Content title" value={report.reportedContentTitle} />
        <Detail label="Reported account" value={report.reportedAccountReference} />
        <Detail className="md:col-span-2" label="Submitted reference" value={report.reportedReference} preserve />
        <Detail className="md:col-span-2" label="Source context" value={report.sourceContext} preserve />
        <Detail className="md:col-span-2" label="Explanation" value={report.explanation} preserve />
      </div>
    </section>
  );
}

function TargetResolution({ report, candidates, chosen, search, loading, onSearchChange, onSearch, onChoose }) {
  return (
    <section className="space-y-4 rounded-md border p-4">
      <div>
        <h2 className="font-semibold">Target resolution</h2>
        <p className="text-sm text-muted-foreground">{contentReportResolutionMessage(report.targetResolution)}</p>
      </div>
      <div className="flex gap-2">
        <Input aria-label="Search target candidates" value={search} onChange={(event) => onSearchChange(event.target.value)} placeholder="Title, author, publisher, owner, username, or email" />
        <Button variant="outline" onClick={onSearch} disabled={loading || search.trim().length < 2}>{loading ? "Searching…" : "Search"}</Button>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {candidates.map((candidate) => {
          const selected = chosen?.type === candidate.type && chosen?.id === candidate.id;
          return (
            <article key={`${candidate.type}:${candidate.id}`} className={`rounded-md border p-3 ${selected ? "border-primary bg-primary/5" : ""}`}>
              <Candidate candidate={candidate} />
              <Button className="mt-3" size="sm" variant={selected ? "default" : "outline"} onClick={() => onChoose(candidate)}>
                {selected ? "Selected" : "Link to report"}
              </Button>
            </article>
          );
        })}
      </div>
      {candidates.length === 0 && <p className="text-sm text-muted-foreground">No exact application reference matched. Search using the submitted title or account information.</p>}
    </section>
  );
}

function CanonicalTarget({ target, snapshot, canUnlink, onUnlink }) {
  return (
    <section className="space-y-3 rounded-md border p-4">
      <h2 className="font-semibold">Canonical linked target</h2>
      <TargetSummary target={target} snapshot={snapshot} />
      {canUnlink && <Button type="button" size="sm" variant="outline" onClick={onUnlink}>Unlink target</Button>}
    </section>
  );
}

function ReviewFields({ review, statuses, actions, effectiveTarget, onChange }) {
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Field label="Review status">
        <select id="reviewStatus" className="h-10 w-full rounded-md border bg-background px-3" value={review.status} onChange={(event) => onChange({ status: event.target.value })}>
          {statuses.map((status) => <option key={status} value={status}>{contentReportLabel(status)}</option>)}
        </select>
      </Field>
      <Field label="Administrative action">
        <select id="reviewAction" className="h-10 w-full rounded-md border bg-background px-3" value={review.action} onChange={(event) => onChange({ action: event.target.value })}>
          {actions.map((action) => <option key={action.value} value={action.value} disabled={action.requires ? !contentReportActionSupports(effectiveTarget, action.requires) : false}>{action.label}</option>)}
        </select>
      </Field>
      <Field label="Resolution code"><Input id="resolutionCode" value={review.resolutionCode} onChange={(event) => onChange({ resolutionCode: event.target.value })} maxLength={64} /></Field>
      <Field label="Resolution note"><Textarea id="resolutionNote" value={review.resolutionNote} onChange={(event) => onChange({ resolutionNote: event.target.value })} maxLength={10000} /></Field>
    </div>
  );
}

function Candidate({ candidate }) {
  return (
    <div className="space-y-1 text-sm">
      <Badge variant="outline">{contentReportLabel(candidate.type)}</Badge>
      <p className="font-medium">{candidate.title || candidate.name || `Record #${candidate.id}`}</p>
      {candidate.owner && <p className="text-muted-foreground">Owner: {candidate.owner.name}</p>}
      {candidate.email && <p className="text-muted-foreground">{candidate.email}</p>}
      {candidate.status && <p className="text-muted-foreground">Share status: {contentReportLabel(candidate.status)}</p>}
      <p className="text-xs text-muted-foreground">Internal {candidate.type} ID: {candidate.id}</p>
    </div>
  );
}

function TargetSummary({ target, snapshot }) {
  if (target) {
    const requirement = target.type === "user" ? "user" : "comic";
    return (
      <div>
        <p className="font-medium">{contentReportTargetLabel(target, requirement)}</p>
        <p className="text-sm text-muted-foreground">{contentReportLabel(target.type)} #{target.id}{target.owner ? ` · owner ${target.owner.name}` : ""}</p>
      </div>
    );
  }
  if (hasContentReportTargetSnapshot(snapshot)) {
    return (
      <div>
        <p className="font-medium">{snapshot.comicTitle || "Previously linked record"}</p>
        <p className="text-sm text-muted-foreground">Live record deleted; retained IDs: user {snapshot.userId || "—"}, comic {snapshot.comicId || "—"}, share {snapshot.shareId || "—"}.</p>
      </div>
    );
  }

  return <p className="text-sm text-muted-foreground">No target has been confirmed.</p>;
}

function Field({ label, children }) {
  return <div className="space-y-2"><Label htmlFor={children.props.id}>{label}</Label>{children}</div>;
}

function Detail({ label, value, preserve = false, className = "" }) {
  return <div className={className}><h3 className="text-xs font-semibold uppercase text-muted-foreground">{label}</h3><p className={preserve ? "whitespace-pre-wrap break-words" : "break-words"}>{value || "—"}</p></div>;
}

function CheckField({ id, checked, onChange, children }) {
  return <div className="flex items-center gap-2"><Checkbox id={id} checked={checked} onCheckedChange={(value) => onChange(value === true)} /><Label htmlFor={id}>{children}</Label></div>;
}
