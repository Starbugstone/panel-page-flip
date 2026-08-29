import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useConfig } from "@/hooks/use-config";

/**
 * Everything a suggestion panel knows and can do, minus how it looks.
 *
 * Accepting never submits: it hands the value back to the form the user is
 * already editing, so what they see is what they are about to save and they
 * keep the chance to change their mind. That is why `onAccept` is a caller's
 * callback rather than a request from here.
 *
 * The provider search runs off the staged fields rather than off what is
 * saved, so a filename suggestion accepted a moment ago is usable immediately
 * instead of after a save and a reopen.
 */
export function useMetadataSuggestions({ comicId, onAccept, staged = {} }) {
  const { config } = useConfig();
  const [suggestions, setSuggestions] = useState([]);
  const [tagSuggestions, setTagSuggestions] = useState([]);
  const [classification, setClassification] = useState(null);
  const [candidates, setCandidates] = useState(null);
  const [providerResults, setProviderResults] = useState([]);
  const [searchedProvider, setSearchedProvider] = useState(null);
  const [openRecord, setOpenRecord] = useState(null);
  const [accepted, setAccepted] = useState([]);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);
  // One provider at a time by default: a search spends somebody's quota, and
  // asking all of them to answer the same question spends all of them.
  const [provider, setProvider] = useState(null);

  // Which providers would actually answer this user. The server deliberately
  // does not say *why* a provider is unavailable beyond what is the user's own
  // to act on — whose credential would be spent, and how the installation's
  // fallback account is configured, are not theirs to read.
  const providers = (config?.metadataProviders ?? []).filter((entry) => entry.available !== false);
  const unavailable = (config?.metadataProviders ?? []).filter((entry) => entry.available === false);
  const chosen = provider ?? providers[0]?.key ?? null;

  useEffect(() => {
    if (!comicId) return undefined;

    let cancelled = false;
    api.get(`/api/comics/${comicId}/metadata-suggestions`)
      .then((result) => {
        if (cancelled) return;
        setSuggestions(result.suggestions ?? []);
        setTagSuggestions(result.tags ?? []);
        setClassification(result.classification ?? null);
      })
      .catch(() => { /* Suggestions are an extra; failing to load one is not an error worth a toast. */ });

    return () => { cancelled = true; };
  }, [comicId]);

  const lookUp = async () => {
    setBusy("search");
    setError(null);
    try {
      const result = await api.post(`/api/comics/${comicId}/metadata-candidates`, {
        provider: chosen,
        // The values in front of the user, not the ones last saved.
        query: {
          series: staged.series || null,
          issueNumber: staged.issueNumber || null,
          year: staged.publishedAt ? Number(String(staged.publishedAt).slice(0, 4)) : null,
          volume: staged.volume || null,
        },
      });
      setCandidates(result.candidates ?? []);
      setProviderResults(result.providers ?? []);
      setSearchedProvider(chosen);
      setOpenRecord(null);
    } catch (loadError) {
      setError(loadError.message || "Could not reach the metadata providers.");
    } finally {
      setBusy(null);
    }
  };

  /**
   * The full record behind a candidate.
   *
   * A search row carries a fraction of what a provider knows — Metron's issue
   * list has no publisher, description or genres at all — so the rest is
   * fetched only for the one somebody picked.
   */
  const openFullRecord = async (candidate) => {
    setBusy(`record:${candidate.provider}:${candidate.externalId}`);
    setError(null);
    try {
      const result = await api.post(`/api/comics/${comicId}/metadata-record`, {
        provider: candidate.provider,
        externalId: candidate.externalId,
      });
      if (!result?.candidate) throw new Error("That record came back empty.");
      setOpenRecord({ key: `${candidate.provider}-${candidate.externalId}`, ...result });
      if (result.tags) setTagSuggestions(result.tags);
    } catch (loadError) {
      setError(loadError.message || "That record could not be read.");
    } finally {
      setBusy(null);
    }
  };

  /** Ask again about the record this comic was already matched to. */
  const refresh = async () => {
    setBusy("refresh");
    setError(null);
    try {
      const result = await api.post(`/api/comics/${comicId}/metadata-refresh`, {});
      // A response without a record is not something to render half of.
      if (!result?.candidate) throw new Error("That record came back empty.");
      setOpenRecord({ key: "refresh", ...result });
      if (result.tags) setTagSuggestions(result.tags);
    } catch (loadError) {
      setError(loadError.message || "That record could not be refreshed.");
    } finally {
      setBusy(null);
    }
  };

  const accept = (suggestion) => {
    onAccept({ [suggestion.field]: suggestion.suggested });
    setAccepted((current) => [...current, `${suggestion.source}:${suggestion.field}`]);
  };

  /**
   * Remember which record was chosen, so a later refresh can ask for that exact
   * issue rather than repeating a fuzzy search and hoping for the same answer.
   */
  const acceptRecord = (candidate, fields) => {
    onAccept({
      ...Object.fromEntries(fields.map((field) => [field.field, field.suggested])),
      metadataProvider: candidate.provider,
      metadataExternalId: candidate.externalId,
    });
    setAccepted((current) => [...current, ...fields.map((field) => `${field.source}:${field.field}`)]);
  };

  return {
    suggestions,
    tagSuggestions,
    classification,
    candidates,
    providerResults,
    searchedProvider,
    openRecord,
    busy,
    error,
    providers,
    unavailable,
    chosen,
    setProvider,
    lookUp,
    openFullRecord,
    refresh,
    accept,
    acceptRecord,
    isAccepted: (suggestion) => accepted.includes(`${suggestion.source}:${suggestion.field}`),
  };
}
