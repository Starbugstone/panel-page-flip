import { useEffect, useState } from "react";
import { Link } from "react-router-dom";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import { api } from "@/lib/api";

const EMPTY = {
  reporterName: "",
  reporterOrganization: "",
  reporterRole: "",
  reporterEmail: "",
  category: "",
  referenceType: "other",
  reportedReference: "",
  reportedContentTitle: "",
  reportedAccountReference: "",
  sourceContext: "",
  explanation: "",
  goodFaithAcknowledged: false,
  website: "",
};

/**
 * What is being reported, and how a reader can find it again.
 *
 * The reference kind decides the wording of the field beneath it, because
 * "paste the link" and "name the comic" are different requests and asking for
 * both at once is how a report arrives with neither.
 */
function NoticeDetails({ form, errors, change, setForm, referenceCopy }) {
  return (
        <Card>
          <CardHeader>
            <CardTitle>Notice details</CardTitle>
            <CardDescription>Provide enough detail for an administrator to locate and assess the specific material.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            <Field label="Report type" error={errors.category} required>
              <select id="category" className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.category} onChange={change("category")}>
                <option value="">Select a category</option>
                <option value="copyright_ip">Copyright / intellectual-property infringement</option>
                <option value="other_illegal">Other illegal content</option>
              </select>
            </Field>
            <Field label="How can we identify it?" error={errors.referenceType} required hint="Choose a reference you can realistically know. Internal database IDs are never required.">
              <select id="referenceType" className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.referenceType} onChange={change("referenceType")}>
                <option value="invitation_url">Panel Page Flip invitation link</option>
                <option value="sharing_code">C- / G- content sharing code</option>
                <option value="user_code">U- public user code</option>
                <option value="account_reference">Username, display name, or known account email</option>
                <option value="comic_reference">Comic or publication details</option>
                <option value="panel_url">Other Panel Page Flip reading URL</option>
                <option value="other">Other reference or external evidence</option>
              </select>
            </Field>
            <Field label={referenceCopy.label} error={errors.reportedReference} required hint={referenceCopy.hint}>
              <Textarea id="reportedReference" value={form.reportedReference} onChange={change("reportedReference")} maxLength={2000} rows={3} placeholder={referenceCopy.placeholder} />
            </Field>
            <Field label={`Content title${form.referenceType === "comic_reference" ? "" : " (optional)"}`} error={errors.reportedContentTitle} required={form.referenceType === "comic_reference"}>
              <Input id="reportedContentTitle" value={form.reportedContentTitle} onChange={change("reportedContentTitle")} maxLength={255} placeholder="Title, issue, edition, or collection" />
            </Field>
            <Field label="Reported account (optional)" error={errors.reportedAccountReference} hint="A username, display name, or email only if you genuinely know it.">
              <Input id="reportedAccountReference" value={form.reportedAccountReference} onChange={change("reportedAccountReference")} maxLength={320} />
            </Field>
            <Field label="Where you encountered it (optional)" error={errors.sourceContext} hint="Describe where or how you encountered the material. Do not include passwords or private credentials.">
              <Textarea id="sourceContext" value={form.sourceContext} onChange={change("sourceContext")} maxLength={2000} rows={3} />
            </Field>
            <Field label="Explain the report" error={errors.explanation} required hint="Explain what you believe is illegal, the right involved, your authority to report it, and supporting context.">
              <Textarea id="explanation" value={form.explanation} onChange={change("explanation")} maxLength={10000} rows={8} />
            </Field>

            <div className="hidden" aria-hidden="true">
              <Label htmlFor="website">Website</Label>
              <Input id="website" tabIndex={-1} autoComplete="off" value={form.website} onChange={change("website")} />
            </div>

            <div className="flex items-start gap-3">
              <Checkbox
                id="goodFaithAcknowledged"
                checked={form.goodFaithAcknowledged}
                onCheckedChange={(checked) => setForm((current) => ({ ...current, goodFaithAcknowledged: checked === true }))}
              />
              <div>
                <Label htmlFor="goodFaithAcknowledged">
                  I confirm that the information in this report is accurate to the best of my knowledge and that I am submitting it in good faith.
                </Label>
                {errors.goodFaithAcknowledged && <p role="alert" className="mt-1 text-sm text-destructive">{errors.goodFaithAcknowledged}</p>}
              </div>
            </div>
          </CardContent>
        </Card>
  );
}

export default function ReportContent() {
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [result, setResult] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const referenceCopy = REFERENCE_COPY[form.referenceType];
  // From the one public-config request the application makes on startup, rather
  // than a second anonymous round trip for one address.
  const { legal } = useAdSense();
  const legalEmail = legal.legalEmail;

  useEffect(() => {
    document.title = "Report illegal content | Panel Page Flip";
  }, []);

  const change = (field) => (event) => setForm((current) => ({
    ...current,
    [field]: event.target.value,
  }));

  const submit = async (event) => {
    event.preventDefault();
    setErrors({});
    setSubmitting(true);
    try {
      const response = await api.post("/api/content-reports", form, { notifyUnauthorized: false });
      setResult(response);
      setForm(EMPTY);
    } catch (error) {
      setErrors(error?.data?.errors || { form: error?.message || "The report could not be submitted." });
    } finally {
      setSubmitting(false);
    }
  };

  if (result) {
    return (
      <div className="container mx-auto max-w-3xl px-4 py-10">
        <Card>
          <CardHeader>
            <CardTitle>Report received</CardTitle>
            <CardDescription>{result.message}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <p>Your reference is <strong>{result.reference}</strong>.</p>
            <p>A detailed receipt has been sent to your email address. Capability-like invitation tokens and sharing codes are masked in that copy.</p>
            <p>Keep this reference if you contact the site operator. No specific response time is promised.</p>
            <Button variant="outline" onClick={() => setResult(null)}>Submit another report</Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="container mx-auto max-w-3xl px-4 py-10">
      <h1 className="mb-3 font-comic text-4xl">Report illegal content</h1>
      <p className="mb-8 text-muted-foreground">
        Use this public form to identify specific material you believe is illegal or infringes intellectual-property rights.
        You do not need an account or an internal comic ID. This is not a public moderation feed.
      </p>

      <form className="space-y-6" onSubmit={submit} noValidate>
        {errors.form && <p role="alert" className="text-sm text-destructive">{errors.form}</p>}

        <Card>
          <CardHeader><CardTitle>Reporter details</CardTitle></CardHeader>
          <CardContent className="grid gap-5 sm:grid-cols-2">
            <Field label="Name or organization" error={errors.reporterName} required>
              <Input id="reporterName" value={form.reporterName} onChange={change("reporterName")} maxLength={200} />
            </Field>
            <Field label="Email" error={errors.reporterEmail} required>
              <Input id="reporterEmail" type="email" value={form.reporterEmail} onChange={change("reporterEmail")} maxLength={320} />
            </Field>
            <Field label="Company / rights holder">
              <Input id="reporterOrganization" value={form.reporterOrganization} onChange={change("reporterOrganization")} maxLength={200} />
            </Field>
            <Field label="Role or authority">
              <Input id="reporterRole" value={form.reporterRole} onChange={change("reporterRole")} maxLength={200} placeholder="Rights holder or authorized representative" />
            </Field>
          </CardContent>
        </Card>

        <NoticeDetails form={form} errors={errors} change={change} setForm={setForm} referenceCopy={referenceCopy} />

        <Button type="submit" disabled={submitting}>{submitting ? "Submitting…" : "Submit report"}</Button>
        <p className="text-sm text-muted-foreground">The legal operator may contact you if more identifying information is needed.</p>
      </form>

      <div className="mt-8 border-t pt-6 text-sm text-muted-foreground">
        <p>
          Notices may also be sent to {legalEmail
            ? <a className="underline" href={`mailto:${legalEmail}`}>{legalEmail}</a>
            : "the legal contact configured by the site operator"}.
        </p>
        <p className="mt-2">See the <Link className="underline" to="/privacy">Privacy Policy</Link> for how report data is handled.</p>
      </div>
    </div>
  );
}

const REFERENCE_COPY = {
  invitation_url: { label: "Invitation URL", hint: "Paste the full HTTP(S) invitation link ending in /share/invitation/…", placeholder: "https://…/share/invitation/…" },
  sharing_code: { label: "Content sharing code", hint: "Enter the C- comic code or G- group code.", placeholder: "C-1234-5678-9ABC" },
  user_code: { label: "Public user code", hint: "Enter the U- code shown by the account.", placeholder: "U-1234-5678-9ABC" },
  account_reference: { label: "Account reference", hint: "Enter a username, display name, or known account email.", placeholder: "Account name or email" },
  comic_reference: { label: "Publication details", hint: "Include issue, author, publisher, edition, or other details that distinguish the work.", placeholder: "Publisher, author, issue, edition…" },
  panel_url: { label: "Panel Page Flip URL", hint: "Paste the HTTP(S) /read/{id} URL you legitimately received.", placeholder: "https://…/read/123" },
  other: { label: "Reference or evidence", hint: "Provide a useful external reference or any identifying information that does not fit above.", placeholder: "Reference, correspondence number, or evidence details" },
};

function Field({ label, hint, error, required = false, children }) {
  const id = children.props.id;
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}{required ? " *" : ""}</Label>
      {children}
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    </div>
  );
}
