import { useMemo, useState } from "react";
import { AlertTriangle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { formatBytes } from "@/lib/format";

const GIB = 1024 ** 3;
const MAX_SAFE_BYTES = Number.MAX_SAFE_INTEGER;

function asGiBInput(bytes) {
  const gib = Math.max(0, Number(bytes) || 0) / GIB;
  return String(Number(gib.toFixed(4)));
}

function parseQuota(useDefault, quotaGiB) {
  if (useDefault) return { bytes: null, valid: true };
  if (quotaGiB.trim() === "") return { bytes: null, valid: false };

  const gib = Number(quotaGiB);
  if (!Number.isFinite(gib) || gib < 0) return { bytes: null, valid: false };

  const bytes = Math.round(gib * GIB);
  return {
    bytes,
    valid: Number.isSafeInteger(bytes)
      && bytes <= MAX_SAFE_BYTES
      && (gib === 0 || bytes > 0),
  };
}

function QuotaInput({ useDefault, defaultBytes, quotaGiB, valid, onUseDefault, onQuotaChange }) {
  return (
    <>
      <div className="flex items-center gap-2">
        <Checkbox
          id="admin-storage-use-default"
          checked={useDefault}
          onCheckedChange={(checked) => onUseDefault(checked === true)}
        />
        <Label htmlFor="admin-storage-use-default" className="font-normal">
          Use server default ({defaultBytes === 0 ? "Unlimited" : formatBytes(defaultBytes)})
        </Label>
      </div>

      {!useDefault && (
        <div className="grid max-w-xs gap-2">
          <Label htmlFor="admin-storage-quota-gib">Custom quota (GiB)</Label>
          <Input
            id="admin-storage-quota-gib"
            type="number"
            min="0"
            step="0.01"
            value={quotaGiB}
            onChange={(event) => onQuotaChange(event.target.value)}
          />
          <p className="text-xs text-muted-foreground">Enter 0 for unlimited storage.</p>
          {!valid && (
            <p className="text-sm text-destructive">Enter a non-negative quota within the supported range.</p>
          )}
        </div>
      )}
    </>
  );
}

function QuotaWarnings({ isUnlimited, isBelowUsage }) {
  return (
    <>
      {isUnlimited && (
        <div role="alert" className="flex max-w-xl gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
          <p>
            Unlimited removes the application-level storage safeguard for this account.
            Available disk space becomes the only remaining limit.
          </p>
        </div>
      )}

      {isBelowUsage && (
        <div role="alert" className="flex max-w-xl gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
          <p>
            This account already uses more than the proposed quota. Existing comics remain,
            but new uploads and imports will be blocked.
          </p>
        </div>
      )}
    </>
  );
}

/**
 * The administrator's storage policy control for one account.
 *
 * Null and zero are intentionally different: null follows the installation
 * default while zero removes the application-level quota for this account.
 */
export function AdminStorageQuotaForm({ user, onUpdated }) {
  const { toast } = useToast();
  const override = user.storageQuotaOverrideBytes ?? null;
  const defaultBytes = Math.max(0, Number(user.storageDefaultQuotaBytes) || 0);
  const [useDefault, setUseDefault] = useState(override === null);
  const [quotaGiB, setQuotaGiB] = useState(asGiBInput(override ?? defaultBytes));
  const [isSaving, setIsSaving] = useState(false);

  const parsed = useMemo(() => parseQuota(useDefault, quotaGiB), [quotaGiB, useDefault]);

  const effectiveBytes = useDefault ? defaultBytes : parsed.bytes;
  const isUnlimited = parsed.valid && effectiveBytes === 0;
  const isBelowUsage = parsed.valid
    && effectiveBytes > 0
    && effectiveBytes < Math.max(0, Number(user.storageUsedBytes) || 0);
  const isDirty = parsed.valid && parsed.bytes !== override;

  const save = async () => {
    if (!parsed.valid || !isDirty) return;

    setIsSaving(true);
    try {
      const data = await api.patch("/api/users/" + user.id + "/storage-quota", {
        storageQuotaOverrideBytes: parsed.bytes,
      });
      onUpdated(data.user);
      toast({ title: data.message });
    } catch (error) {
      toast({ title: "Quota update failed", description: error.message, variant: "destructive" });
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="mt-6 space-y-4 border-t pt-5">
      <div>
        <h3 className="font-medium">Storage allowance</h3>
        <p className="text-sm text-muted-foreground">
          Set an exception for this account, or let it follow the server default.
          Changing this never deletes existing comics.
        </p>
      </div>

      <QuotaInput
        useDefault={useDefault}
        defaultBytes={defaultBytes}
        quotaGiB={quotaGiB}
        valid={parsed.valid}
        onUseDefault={setUseDefault}
        onQuotaChange={setQuotaGiB}
      />
      <QuotaWarnings isUnlimited={isUnlimited} isBelowUsage={isBelowUsage} />

      <Button onClick={save} disabled={isSaving || !isDirty || !parsed.valid}>
        {isSaving ? "Saving quota…" : "Save storage quota"}
      </Button>
    </div>
  );
}
