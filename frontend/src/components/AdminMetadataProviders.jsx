import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/**
 * Credentials are write-only here, because the server never sends them back.
 * An empty field means "leave whatever is stored alone", which is why saving
 * omits it rather than sending an empty string.
 */
const FIELDS = [
  {
    provider: "metron",
    name: "metronUsername",
    label: "Metron username",
    type: "text",
    hint: "A metron.cloud account. Metron authenticates with a username and password rather than a key.",
  },
  { provider: "metron", name: "metronPassword", label: "Metron password", type: "password" },
  {
    provider: "comicvine",
    name: "comicVineApiKey",
    label: "Comic Vine API key",
    type: "password",
    hint: "From comicvine.gamespot.com/api. Lookups are rate-limited well under Comic Vine's hourly ceiling.",
  },
];

export function AdminMetadataProviders() {
  const { toast } = useToast();
  const [providers, setProviders] = useState(null);
  const [values, setValues] = useState({});
  const [busy, setBusy] = useState(true);
  const [loadError, setLoadError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api.get("/api/admin/metadata-providers")
      .then((result) => { if (!cancelled) { setProviders(result.providers); setLoadError(null); } })
      .catch((error) => { if (!cancelled) setLoadError(error.message); })
      .finally(() => { if (!cancelled) setBusy(false); });
    return () => { cancelled = true; };
  }, []);

  const configured = Object.fromEntries((providers ?? []).map((p) => [p.key, p.configured]));

  const save = async () => {
    const payload = Object.fromEntries(
      Object.entries(values).filter(([, value]) => value !== "")
    );

    if (Object.keys(payload).length === 0) {
      toast({ title: "Nothing to save", description: "Enter a credential first." });
      return;
    }

    setBusy(true);
    try {
      const result = await api.put("/api/admin/metadata-providers", payload);
      setProviders(result.providers);
      // Never keep a secret in component state once it has been stored.
      setValues({});
      toast({ title: "Credentials saved", description: "Metadata lookups now use them." });
    } catch (error) {
      toast({ title: "Could not save credentials", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  const clear = async (provider) => {
    setBusy(true);
    try {
      const payload = provider === "metron"
        ? { metronUsername: null, metronPassword: null }
        : { comicVineApiKey: null };
      const result = await api.put("/api/admin/metadata-providers", payload);
      setProviders(result.providers);
      toast({ title: "Credentials removed", description: `${provider} lookups are disabled.` });
    } catch (error) {
      toast({ title: "Could not remove credentials", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Metadata providers</CardTitle>
        <CardDescription>
          Optional. Without credentials, comics are still described by their own ComicInfo.xml and their
          filenames — providers only add a second opinion, and nothing they return is applied without a
          person accepting it.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {loadError && <p className="text-sm text-destructive">{loadError}</p>}

        {(providers ?? []).map((provider) => (
          <div key={provider.key} className="flex items-center justify-between gap-4 rounded-md border p-3">
            <span className="font-medium">{provider.label}</span>
            <div className="flex items-center gap-3">
              <span className={provider.configured ? "text-sm text-green-600" : "text-sm text-muted-foreground"}>
                {provider.configured ? "Configured" : "Not configured"}
              </span>
              {provider.configured && (
                <Button variant="ghost" size="sm" disabled={busy} onClick={() => clear(provider.key)}>
                  Remove
                </Button>
              )}
            </div>
          </div>
        ))}

        <div className="space-y-4">
          {FIELDS.map((field) => (
            <div key={field.name} className="space-y-1.5">
              <Label htmlFor={field.name}>{field.label}</Label>
              <Input
                id={field.name}
                type={field.type}
                autoComplete="off"
                value={values[field.name] ?? ""}
                disabled={busy}
                placeholder={configured[field.provider] ? "Stored — enter a new value to replace it" : ""}
                onChange={(event) => setValues((current) => ({ ...current, [field.name]: event.target.value }))}
              />
              {field.hint && <p className="text-xs text-muted-foreground">{field.hint}</p>}
            </div>
          ))}
        </div>

        <div className="flex justify-end">
          <Button disabled={busy} onClick={save}>Save credentials</Button>
        </div>
      </CardContent>
    </Card>
  );
}
