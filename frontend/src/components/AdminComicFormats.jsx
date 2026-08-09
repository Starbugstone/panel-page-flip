import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";

const LABELS = { cbz: "CBZ (ZIP)", cbr: "CBR (RAR)", cb7: "CB7 (7z)", cbt: "CBT (tar)", pdf: "PDF (Poppler)" };

export function AdminComicFormats() {
  const { toast } = useToast();
  const [formats, setFormats] = useState(null);
  const [busy, setBusy] = useState(true);
  const [loadError, setLoadError] = useState(null);

  const load = async (verify = false) => {
    setBusy(true);
    try {
      const result = verify ? await api.post("/api/admin/comic-formats/verify", {}) : await api.get("/api/admin/comic-formats");
      setFormats(result.formats);
      setLoadError(null);
      if (verify) toast({ title: "Verification complete", description: "Server format dependencies were checked." });
    } catch (error) {
      toast({ title: "Format check failed", description: error.message, variant: "destructive" });
    } finally { setBusy(false); }
  };

  // The initial load holds `busy`, so verification cannot start alongside it.
  // Otherwise the slower of the two settles last, and a stale availability
  // snapshot would quietly replace the one the admin just asked for.
  useEffect(() => {
    let cancelled = false;
    api.get("/api/admin/comic-formats")
      .then((result) => { if (!cancelled) { setFormats(result.formats); setLoadError(null); } })
      .catch((error) => { if (!cancelled) setLoadError(error.message); })
      .finally(() => { if (!cancelled) setBusy(false); });
    return () => { cancelled = true; };
  }, []);

  const toggle = (name, checked) => setFormats((current) => ({ ...current, [name]: { ...current[name], enabled: checked } }));
  const save = async () => {
    setBusy(true);
    try {
      const enabled = Object.entries(formats).filter(([, value]) => value.enabled).map(([name]) => name);
      const result = await api.put("/api/admin/comic-formats", { enabled });
      setFormats(result.formats);
      toast({ title: "Formats saved", description: "Uploaders now reflect the enabled formats." });
    } catch (error) {
      toast({ title: "Could not save formats", description: error.message, variant: "destructive" });
    } finally { setBusy(false); }
  };

  return (
    <Card>
      <CardHeader><CardTitle>Comic formats</CardTitle><CardDescription>CBZ is always enabled. Verify this server before enabling formats that require external tools.</CardDescription></CardHeader>
      <CardContent className="space-y-5">
        {loadError && <p className="text-sm text-destructive">{loadError}</p>}
        {!formats ? <p>Checking format support…</p> : Object.entries(formats).map(([name, status]) => (
          <div key={name} className="flex items-start justify-between rounded-md border p-4">
            <div className="flex items-start gap-3">
              <Checkbox id={`format-${name}`} checked={status.enabled} disabled={name === "cbz" || busy || (!status.available && !status.enabled)} onCheckedChange={(checked) => toggle(name, checked === true)} />
              <div><Label htmlFor={`format-${name}`}>{LABELS[name]}</Label><p className="text-sm text-muted-foreground">Requires {status.requirements.join(" + ")}</p></div>
            </div>
            <span className={status.available ? "text-sm text-green-600" : "text-sm text-destructive"}>{status.available ? "Available" : "Unavailable"}</span>
          </div>
        ))}
        <div className="flex gap-2"><Button variant="outline" disabled={busy} onClick={() => load(true)}>Verify server</Button><Button disabled={busy || !formats} onClick={save}>Save enabled formats</Button></div>
      </CardContent>
    </Card>
  );
}
