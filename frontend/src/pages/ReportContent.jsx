import { useEffect, useState } from "react";
import { Link } from "react-router-dom";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";

const EMPTY = {
  reporterName: "",
  reporterOrganization: "",
  reporterRole: "",
  reporterEmail: "",
  category: "",
  reportedReference: "",
  explanation: "",
  goodFaithAcknowledged: false,
  website: "",
};

export default function ReportContent() {
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [result, setResult] = useState(null);
  const [legalEmail, setLegalEmail] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    document.title = "Report illegal content | Panel Page Flip";
    api.get("/api/legal-config", { notifyUnauthorized: false })
      .then((config) => setLegalEmail(config.legalEmail || null))
      .catch(() => {});
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
            <Field label="Identify the material" error={errors.reportedReference} required hint="Provide a Panel Page Flip URL, invitation reference, account identifier, title, or external evidence reference. Do not include secret credentials.">
              <Textarea id="reportedReference" value={form.reportedReference} onChange={change("reportedReference")} maxLength={2000} rows={4} />
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

        <Button type="submit" disabled={submitting}>{submitting ? "Submitting…" : "Submit report"}</Button>
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
