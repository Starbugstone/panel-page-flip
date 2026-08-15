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
    name: "metronToken",
    label: "Metron API token",
    type: "password",
    hint: "A revocable token from your metron.cloud account. There is deliberately nowhere here to put an account password — a token can be withdrawn without touching the account behind it.",
  },
  {
    provider: "comicvine",
    name: "comicVineApiKey",
    label: "Comic Vine API key",
    type: "password",
    hint: "From comicvine.gamespot.com/api. Comic Vine's published terms are non-commercial use only.",
  },
];

/** Which enable flag belongs to which provider. */
const TOGGLES = {
  metron: { field: "metronSharedEnabled", label: "Share this server's Metron token with all users" },
  comicvine: { field: "comicVineEnabled", label: "Allow Comic Vine lookups" },
};

const describeQuota = (quota) => {
  if (!quota || quota.remaining === undefined) return null;
  const reset = quota.resetsAt ? new Date(quota.resetsAt * 1000).toLocaleTimeString() : null;
  const limit = quota.limit === undefined ? "" : ` of ${quota.limit}`;
  return `${quota.remaining}${limit} left${reset ? `, resets ${reset}` : ""}`;
};

const STATUS_STYLES = {
  ok: "text-green-600",
  unconfigured: "text-muted-foreground",
  unauthorized: "text-destructive",
  rate_limited: "text-amber-600",
  unreachable: "text-destructive",
  failed: "text-destructive",
};

export function AdminMetadataProviders() {
  const { toast } = useToast();
  const [providers, setProviders] = useState(null);
  const [environment, setEnvironment] = useState(null);
  const [settings, setSettings] = useState(null);
  const [values, setValues] = useState({});
  const [results, setResults] = useState(null);
  // Which fields the admin has actually put a cursor in. See the readOnly note
  // on the inputs below.
  const [engaged, setEngaged] = useState({});
  const [busy, setBusy] = useState(true);
  const [loadError, setLoadError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api.get("/api/admin/metadata-providers")
      .then((result) => {
        if (cancelled) return;
        setProviders(result.providers);
        setEnvironment(result.environment ?? null);
        setSettings(result.settings ?? null);
        setLoadError(null);
      })
      .catch((error) => { if (!cancelled) setLoadError(error.message); })
      .finally(() => { if (!cancelled) setBusy(false); });
    return () => { cancelled = true; };
  }, []);

  const configured = Object.fromEntries((providers ?? []).map((p) => [p.key, p.configured]));

  // Sends what is typed, so a credential can be tested before it is stored.
  // Anything left blank falls back to what the server already has.
  /**
   * Only fields the admin actually typed in. Belt and braces against autofill:
   * even if something did get written into a box nobody touched, it is not
   * sent, so a working credential cannot be replaced by one nobody chose.
   */
  const entered = () => Object.fromEntries(
    Object.entries(values).filter(([field, value]) => value !== "" && engaged[field])
  );

  const test = async () => {
    setBusy(true);
    setResults(null);
    try {
      const payload = entered();
      const result = await api.post("/api/admin/metadata-providers/verify", payload);
      setResults(result.results ?? []);
    } catch (error) {
      toast({ title: "Could not test credentials", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  const save = async () => {
    const payload = entered();

    if (Object.keys(payload).length === 0) {
      toast({ title: "Nothing to save", description: "Enter a credential first." });
      return;
    }

    setBusy(true);
    try {
      const result = await api.put("/api/admin/metadata-providers", payload);
      setProviders(result.providers);
      setEnvironment(result.environment ?? environment);
      setSettings(result.settings ?? settings);
      // Never keep a secret in component state once it has been stored.
      setValues({});
      setEngaged({});
      setResults(null);
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
      const payload = provider === "metron" ? { metronToken: null } : { comicVineApiKey: null };
      const result = await api.put("/api/admin/metadata-providers", payload);
      setProviders(result.providers);
      setEnvironment(result.environment ?? environment);
      setSettings(result.settings ?? settings);
      setResults(null);
      // Removing the key stops new requests. It does not touch metadata anybody
      // has already accepted, which is theirs now.
      toast({ title: "Credentials removed", description: `${provider} lookups are disabled.` });
    } catch (error) {
      toast({ title: "Could not remove credentials", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  /**
   * A pause the circuit breaker applied is not a setting, so it is not shown as
   * one. Only the two switches an administrator actually owns are toggleable
   * here, and the environment's veto is reported beside them rather than being
   * silently applied.
   */
  const toggle = async (providerKey, enabled) => {
    setBusy(true);
    try {
      const result = await api.put("/api/admin/metadata-providers", { [TOGGLES[providerKey].field]: enabled });
      setProviders(result.providers);
      setEnvironment(result.environment ?? environment);
    } catch (error) {
      toast({ title: "Could not change the setting", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  /**
   * Whether users may bring their own token. Not per-provider: it governs the
   * whole idea, for every provider at once.
   */
  const setPersonalCredentials = async (enabled) => {
    setBusy(true);
    try {
      const result = await api.put("/api/admin/metadata-providers", { personalCredentialsEnabled: enabled });
      setProviders(result.providers);
      setSettings(result.settings ?? settings);
    } catch (error) {
      toast({ title: "Could not change the setting", description: error.message, variant: "destructive" });
    } finally {
      setBusy(false);
    }
  };

  const allowedByEnvironment = (providerKey) => (providerKey === "metron"
    ? environment?.metronSharedEnabled
    : environment?.comicVineEnabled) !== false;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Metadata providers</CardTitle>
        <CardDescription>
          Optional. Without credentials, comics are still described by their own ComicInfo.xml and their
          filenames — providers only add a second opinion, and nothing they return is applied without a
          person accepting it. These credentials are the server's; a user who adds their own token in
          their settings uses that instead, and spends their own allowance rather than this server's.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {loadError && <p className="text-sm text-destructive">{loadError}</p>}

        {(providers ?? []).map((provider) => (
          <div key={provider.key} className="space-y-2 rounded-md border p-3">
            <div className="flex items-center justify-between gap-4">
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

            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={provider.enabled === true}
                disabled={busy}
                onChange={(event) => toggle(provider.key, event.target.checked)}
              />
              {TOGGLES[provider.key]?.label ?? "Enabled"}
            </label>

            {!allowedByEnvironment(provider.key) && (
              <p className="text-xs text-amber-600">
                Turned off for this server by {provider.key === "metron" ? "METRON_SHARED_ENABLED" : "COMIC_VINE_SHARED_ENABLED"}.
                The environment has the final word, so this switch has no effect until that changes.
                A user's own token is unaffected either way.
              </p>
            )}

            {describeQuota(provider.quota) && (
              <p className="text-xs text-muted-foreground">
                Provider quota: {describeQuota(provider.quota)}
              </p>
            )}
          </div>
        ))}

        <div className="space-y-4">
          {FIELDS.map((field) => (
            <div key={field.name} className="space-y-1.5">
              <Label htmlFor={field.name}>{field.label}</Label>
              {/* A browser fills a saved site login into anything that looks
                  like one, and a username box above a password box looks
                  exactly like one. Chrome ignores autocomplete="off" here by
                  design, so three things keep it out: "new-password", which it
                  does respect; names that do not read as credentials; and
                  opening read-only, because nothing autofills a field it
                  cannot write to. The cost of getting this wrong is not a
                  nuisance — it is the operator's own password stored as a
                  Metron credential and then sent to metron.cloud. */}
              <Input
                id={field.name}
                name={`provider-${field.name}`}
                type={field.type}
                autoComplete="new-password"
                readOnly={!engaged[field.name]}
                onFocus={() => setEngaged((current) => ({ ...current, [field.name]: true }))}
                data-1p-ignore
                data-lpignore="true"
                data-bwignore="true"
                data-form-type="other"
                value={values[field.name] ?? ""}
                disabled={busy}
                placeholder={configured[field.provider] ? "Stored — enter a new value to replace it" : ""}
                onChange={(event) => setValues((current) => ({ ...current, [field.name]: event.target.value }))}
              />
              {field.hint && <p className="text-xs text-muted-foreground">{field.hint}</p>}
            </div>
          ))}
        </div>

        {/* A personal token spends its owner's allowance, not this server's, so
            it is allowed unless an administrator wants exactly one outbound
            credential and wants to know which one it is. Turning it off stops
            stored tokens being used; it does not delete them. */}
        <label className="flex items-start gap-2 rounded-md border p-3 text-sm">
          <input
            type="checkbox"
            className="mt-1"
            checked={settings?.personalCredentialsEnabled !== false}
            disabled={busy || settings === null}
            onChange={(event) => setPersonalCredentials(event.target.checked)}
          />
          <span>
            Allow users to add their own provider tokens
            <span className="block text-xs text-muted-foreground">
              A personal token is used in preference to this server's and spends its owner's allowance.
              Switching this off falls back to the credentials above; tokens users already saved are kept,
              not deleted.
            </span>
          </span>
        </label>

        {results && (
          <div className="space-y-2 rounded-md border p-3">
            <p className="text-sm font-medium">Test results</p>
            {results.map((result) => (
              <p key={result.key} className="text-sm">
                <span className="font-medium">{result.label}: </span>
                <span className={STATUS_STYLES[result.status] ?? "text-muted-foreground"}>{result.message}</span>
              </p>
            ))}
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Button variant="outline" disabled={busy} onClick={test}>Test credentials</Button>
          <Button disabled={busy} onClick={save}>Save credentials</Button>
        </div>
      </CardContent>
    </Card>
  );
}
