import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";

const LABELS = { cbz: "CBZ (ZIP)", cbr: "CBR (RAR)", cb7: "CB7 (7z)", cbt: "CBT (tar)", pdf: "PDF" };

/**
 * One format: whether it is switched on, whether this server can actually
 * serve it, and what to install if it cannot.
 *
 * CBZ can never be switched off — it is the format everything else converts
 * to — and a format that is unavailable and off cannot be switched on, because
 * doing so would only queue up failing uploads.
 */
function FormatRow({ name, status, busy, onToggle }) {
  return (
          <div key={name} className="rounded-md border p-4">
            <div className="flex items-start justify-between gap-4">
              <div className="flex items-start gap-3">
                <Checkbox
                  id={`format-${name}`}
                  checked={status.enabled}
                  disabled={name === "cbz" || busy || (!status.available && !status.enabled)}
                  onCheckedChange={(checked) => onToggle(name, checked === true)}
                />
                <div>
                  <Label htmlFor={`format-${name}`}>{LABELS[name]}</Label>
                  <p className="text-sm text-muted-foreground">Requires {status.requirements.join(" + ")}</p>
                </div>
              </div>
              <span className={status.available ? "text-sm text-green-600 whitespace-nowrap" : "text-sm text-destructive whitespace-nowrap"}>
                {status.available ? "Available" : "Unavailable"}
              </span>
            </div>
            {!status.available && status.hint && (
              <p className="mt-3 rounded bg-muted p-3 text-sm text-muted-foreground">{status.hint}</p>
            )}
            {/* Works, but could do more — currently only PDF, which reads
                image-based comics natively and needs Poppler for the rest. */}
            {status.available && status.note && (
              <p className="mt-3 rounded bg-muted p-3 text-sm text-muted-foreground">{status.note}</p>
            )}
            {status.enabled && !status.available && (
              <p className="mt-3 text-sm text-destructive">
                This format is switched on but cannot be served right now. Uploads and reads for it will fail until the
                tools above are installed, or turn it off here.
              </p>
            )}
          </div>
  );
}

export function AdminComicFormats() {
  const { toast } = useToast();
  const [formats, setFormats] = useState(null);
  const [delivery, setDelivery] = useState(null);
  const [busy, setBusy] = useState(true);
  const [loadError, setLoadError] = useState(null);

  // Verification answers "what can this server do", not "what did the admin
  // just tick". Keeping the local `enabled` values means pressing Verify to
  // check whether a package landed does not silently discard the changes they
  // were about to save.
  const applyVerification = (current, incoming) => Object.fromEntries(
    Object.entries(incoming).map(([name, status]) => [
      name,
      { ...status, enabled: current?.[name]?.enabled ?? status.enabled },
    ]),
  );

  const verify = async () => {
    setBusy(true);
    try {
      const result = await api.post("/api/admin/comic-formats/verify", {});
      setFormats((current) => applyVerification(current, result.formats));
      setDelivery(result.delivery ?? null);
      setLoadError(null);
      toast({ title: "Verification complete", description: "Server format dependencies were re-checked." });
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
      .then((result) => { if (!cancelled) { setFormats(result.formats); setDelivery(result.delivery ?? null); setLoadError(null); } })
      .catch((error) => { if (!cancelled) setLoadError(error.message); })
      .finally(() => { if (!cancelled) setBusy(false); });
    return () => { cancelled = true; };
  }, []);

  const brokenEssentials = Object.entries(formats ?? {})
    .filter(([, status]) => status.essential && !status.available)
    .map(([name]) => name);

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
      <CardHeader>
        <CardTitle>Comic formats</CardTitle>
        <CardDescription>
          What this server can read, and what it would need in order to read the rest. CBZ is always enabled.
          Press Verify server after installing anything.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        {loadError && <p className="text-sm text-destructive">{loadError}</p>}

        {/* CBZ and PDF need nothing installed, so one of them failing means the
            installation is broken rather than merely unconfigured. That is worth
            saying once, loudly, rather than leaving an admin to infer it from a
            red row among four others. */}
        {brokenEssentials.length > 0 && (
          <div className="rounded-md border border-destructive bg-destructive/10 p-4">
            <p className="font-semibold text-destructive">
              This installation cannot serve {brokenEssentials.map((name) => LABELS[name]).join(" or ")}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              These formats need nothing installed and are expected to work on any server. Something is wrong with this
              deployment — see the guidance on the affected rows below.
            </p>
          </div>
        )}
        {!formats ? <p>Checking format support…</p> : Object.entries(formats).map(([name, status]) => (
          <FormatRow key={name} name={name} status={status} busy={busy} onToggle={toggle} />
        ))}

        {/* Independent of which formats are on: how pages leave the server is
            the same question for every comic in the library. */}
        {delivery && (
          <div className="rounded-md border p-4">
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="font-medium">Page delivery</p>
                <p className="text-sm text-muted-foreground">{delivery.summary}</p>
              </div>
              <span className={delivery.healthy ? "text-sm text-green-600 whitespace-nowrap" : "text-sm text-amber-600 whitespace-nowrap"}>
                {delivery.format === "webp" ? "WebP" : "Source format"}
              </span>
            </div>
            {delivery.hint && (
              <p className="mt-3 rounded bg-muted p-3 text-sm text-muted-foreground">{delivery.hint}</p>
            )}
          </div>
        )}

        <div className="flex gap-2">
          <Button variant="outline" disabled={busy} onClick={verify}>Verify server</Button>
          <Button disabled={busy || !formats} onClick={save}>Save enabled formats</Button>
        </div>
      </CardContent>
    </Card>
  );
}
