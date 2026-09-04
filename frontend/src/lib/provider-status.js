/**
 * How a provider status reads on screen.
 *
 * The values are the backend's `ProviderStatus` enum, which the credential test
 * and the search path both answer in. Kept here rather than copied into each
 * panel: the admin and the user settings pages report the same statuses, and two
 * copies of the same map are two things that stop agreeing.
 */
const PROVIDER_STATUS_STYLES = {
  ok: "text-green-600",
  unconfigured: "text-muted-foreground",
  disabled: "text-muted-foreground",
  forbidden: "text-amber-600",
  unauthorized: "text-destructive",
  rate_limited: "text-amber-600",
  paused: "text-amber-600",
  unreachable: "text-destructive",
  failed: "text-destructive",
};

export const providerStatusStyle = (status) =>
  PROVIDER_STATUS_STYLES[status] ?? "text-muted-foreground";
