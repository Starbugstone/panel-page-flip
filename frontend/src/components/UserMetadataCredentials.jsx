import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/**
 * Only ever a token, never a provider account password. A token can be revoked
 * upstream without touching the account behind it, which is what makes asking
 * for one defensible at all.
 */
const FIELDS = [
  {
    provider: "metron",
    name: "metronToken",
    label: "Metron API token",
    hint: "From your metron.cloud account. Searches then spend your allowance instead of this server's, and keep working if the shared token is turned off.",
  },
  {
    provider: "comicvine",
    name: "comicVineApiKey",
    label: "Comic Vine API key",
    hint: "From comicvine.gamespot.com/api. Your own key keeps working even when this server's shared Comic Vine access is switched off. Comic Vine's published terms are non-commercial use only, and obtaining a key is you accepting them.",
  },
];

const STATUS_STYLES = {
  ok: "text-green-600",
  unconfigured: "text-muted-foreground",
  unauthorized: "text-destructive",
  rate_limited: "text-amber-600",
  unreachable: "text-destructive",
  failed: "text-destructive",
};

/**
 * Your own metadata-provider tokens.
 *
 * Write-only: the server never sends one back, so a stored token shows as
 * "Configured" and nothing more. An empty box means "leave whatever is stored
 * alone" rather than "clear it" — clearing is its own button.
 */
export function UserMetadataCredentials() {
  const { toast } = useToast();
  const [state, setState] = useState(null);
  const [values, setValues] = useState({});
  const [engaged, setEngaged] = useState({});
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(true);
  const [loadError, setLoadError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api.get("/api/me/metadata-credentials")
      .then((response) => { if (!cancelled) { setState(response); setLoadError(null); } })
      .catch((error) => { if (!cancelled) setLoadError(error.message); })
      .finally(() => { if (!cancelled) setBusy(false); });
    return () => { cancelled = true; };
  }, []);

  /**
   * Only fields actually typed in. A browser will fill a saved site login into
   * anything that looks like a credential box, and a token nobody chose would
   * be sent straight to the provider.
   */
  const entered = () => Object.fromEntries(
    Object.entries(values).filter(([field, value]) => value !== "" && engaged[field])
  );

  const save = async () => {
    const payload = entered();
    if (Object.keys(payload).length === 0) {
      toast({ title: "Nothing to save", description: "Enter a token first." });
      return;
    }

    setBusy(true);
    try {
      setState(await api.put("/api/me/metadata-credentials", payload));
      // Never keep a secret in component state once it has been stored.
      setValues({});
      setEngaged({});
      setResult(null);
      toast({ title: "Token saved", description: "Your metadata searches will use it." });
    } catch (error) {
      toast({ title: "Could not save the token", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  const remove = async (field) => {
    setBusy(true);
    try {
      setState(await api.put("/api/me/metadata-credentials", { [field]: null }));
      setResult(null);
      // Removing it stops new requests. Metadata already accepted is yours and
      // stays exactly as it is.
      toast({ title: "Token removed" });
    } catch (error) {
      toast({ title: "Could not remove the token", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  const test = async (field, provider) => {
    setBusy(true);
    setResult(null);
    try {
      const response = await api.post("/api/me/metadata-credentials/verify", {
        provider,
        // What is typed if anything is, otherwise what is stored — so "test
        // what I saved" and "test what I just typed" are the same button.
        secret: engaged[field] ? values[field] ?? null : null,
      });
      setResult({ provider, ...response.result });
    } catch (error) {
      toast({ title: "Could not test the token", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  const configured = state?.configured ?? {};
  const providers = state?.providers ?? [];
  // Switched off, the panel still shows what is stored and still lets it be
  // removed — a token that has stopped being used is one somebody may well
  // want off this server.
  const accepted = state?.personalCredentialsEnabled !== false;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Metadata provider tokens</CardTitle>
        <CardDescription>
          Optional. Your comics are already described by their own ComicInfo.xml and their filenames;
          a provider only adds a second opinion, and nothing it returns is applied until you accept it.
          A token you add here is stored encrypted, never shown again, and deleted with your account.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {loadError && <p className="text-sm text-destructive">{loadError}</p>}

        {state?.metadataApiEnabled === false && (
          <p className="rounded-md border p-3 text-sm text-amber-600">
            An administrator has turned off external metadata lookups for your account. Metadata read from
            your files and their names is unaffected.
          </p>
        )}

        {!accepted && (
          <p className="rounded-md border p-3 text-sm text-amber-600">
            This server does not accept personal provider tokens. Searches use the credentials an
            administrator configured. Anything you saved earlier is kept but not used, and you can still
            remove it.
          </p>
        )}

        {FIELDS.map((field) => (
          <div key={field.name} className="space-y-1.5">
            <div className="flex items-center justify-between gap-2">
              <Label htmlFor={`me-${field.name}`}>{field.label}</Label>
              <span className={configured[field.provider] ? "text-xs text-green-600" : "text-xs text-muted-foreground"}>
                {configured[field.provider] ? "Configured" : "Not configured"}
              </span>
            </div>

            {/* Opens read-only and asks for a "new-password": nothing autofills
                a field it cannot write to, and Chrome ignores autocomplete="off"
                on anything that looks like a login. */}
            <Input
              id={`me-${field.name}`}
              name={`personal-${field.name}`}
              type="password"
              autoComplete="new-password"
              readOnly={!engaged[field.name]}
              onFocus={() => setEngaged((current) => ({ ...current, [field.name]: true }))}
              data-1p-ignore
              data-lpignore="true"
              data-bwignore="true"
              data-form-type="other"
              value={values[field.name] ?? ""}
              disabled={busy || !accepted}
              placeholder={configured[field.provider] ? "Stored — enter a new value to replace it" : ""}
              onChange={(event) => setValues((current) => ({ ...current, [field.name]: event.target.value }))}
            />

            <p className="text-xs text-muted-foreground">{field.hint}</p>

            <div className="flex gap-2">
              <Button variant="outline" size="sm" disabled={busy || !accepted} onClick={() => test(field.name, field.provider)}>
                Test
              </Button>
              {configured[field.provider] && (
                <Button variant="ghost" size="sm" disabled={busy} onClick={() => remove(field.name)}>
                  Remove
                </Button>
              )}
            </div>

            {result?.provider === field.provider && (
              <p className={`text-sm ${STATUS_STYLES[result.status] ?? "text-muted-foreground"}`}>{result.message}</p>
            )}
          </div>
        ))}

        {/* Which provider a search would actually use, and why not when none
            would. Saying "no results" when nothing was ever asked is the
            failure this replaces. */}
        <div className="space-y-1 rounded-md border p-3">
          <p className="text-sm font-medium">What a search would use</p>
          {providers.map((provider) => (
            <p key={provider.key} className="text-xs text-muted-foreground">
              <span className="font-medium text-foreground">{provider.label}: </span>
              {provider.available
                ? `ready — ${provider.origin === "personal" ? "your token" : "this server's shared token"}`
                : provider.message}
            </p>
          ))}
        </div>

        <div className="flex justify-end">
          <Button disabled={busy || !accepted} onClick={save}>Save tokens</Button>
        </div>
      </CardContent>
    </Card>
  );
}
