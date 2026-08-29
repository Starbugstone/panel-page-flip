/**
 * What the providers had to say for themselves.
 *
 * Every reason rendered here is one the reader can act on — their own token,
 * or their own account. Whose credential would have been spent, and how the
 * installation's fallback account is configured, are not theirs to read, and
 * the server does not send them.
 */
export function MetadataProviderNotices({ providers, unavailable, providerResults, candidates, searchedProvider }) {
  const providerLabel = (key) => providers.find((entry) => entry.key === key)?.label ?? key;

  return (
    <>
      {candidates !== null && candidates.length === 0 && searchedProvider && (
        <p className="text-xs text-muted-foreground">
          No matches from {providerLabel(searchedProvider)}.
        </p>
      )}

      {providerResults.filter((entry) => entry.reason).map((entry) => (
        <p key={entry.key} className="text-xs text-amber-700 dark:text-amber-300">
          {entry.reason}
        </p>
      ))}

      {providers.length === 0 && (
        <div className="space-y-1">
          <p className="text-xs text-muted-foreground">No metadata provider is available for your account.</p>
          {unavailable.filter((entry) => entry.reason).map((entry) => (
            <p key={entry.key} className="text-xs text-muted-foreground">{entry.reason}</p>
          ))}
          {/* Static copy, not server state: it says what they could try, without
              confirming anything about the installation's own credentials. */}
          <p className="text-xs text-muted-foreground">
            Adding your own provider token in Settings may make one available.
          </p>
        </div>
      )}
    </>
  );
}
