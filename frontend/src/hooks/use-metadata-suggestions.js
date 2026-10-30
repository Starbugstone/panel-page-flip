import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useConfig } from "@/hooks/use-config";

const recordKey = (candidate) => `${candidate.provider}:${candidate.externalId}`;

// Two records can propose the same field, and the same record can change its
// answer on a refresh. Only this exact value from this exact record has been
// used, so taking one leaves the others available.
const proposalKey = (suggestion, candidate) =>
  JSON.stringify([recordKey(candidate), suggestion.field, suggestion.suggested]);
const localProposalKey = (suggestion) => JSON.stringify([suggestion.source, suggestion.field, suggestion.suggested]);

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
  const [acceptedLocal, setAcceptedLocal] = useState([]);
  const [acceptedFromRecords, setAcceptedFromRecords] = useState([]);
  const [chosenRecord, setChosenRecord] = useState(null);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);
  // One provider at a time by default: a search spends somebody's quota, and
  // asking all of them to answer the same question spends all of them.
  //
  // Null until somebody picks one. The server then prefers a provider the user's
  // own token works with, which it deliberately does not tell the browser, so
  // naming the first provider here would overrule that and spend the wrong
  // account.
  const [provider, setProvider] = useState(null);

  // Which providers would actually answer this user. The server deliberately
  // does not say *why* a provider is unavailable beyond what is the user's own
  // to act on — whose credential would be spent, and how the installation's
  // fallback account is configured, are not theirs to read.
  const providers = (config?.metadataProviders ?? []).filter((entry) => entry.available !== false);
  const unavailable = (config?.metadataProviders ?? []).filter((entry) => entry.available === false);
  // Only a guess at who answered when there is exactly one who could have.
  const answering = provider ?? (providers.length === 1 ? providers[0].key : null);

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
        provider,
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
      setSearchedProvider(answering);
      setOpenRecord(null);
      // These cards are gone, and what was done with the last set says nothing
      // about the next. What came from the file is still on screen and stays.
      setAcceptedFromRecords([]);
      setChosenRecord(null);
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

  /** @param {object|null} [candidate] the record proposing this, if one is */
  const accept = (suggestion, candidate = null) => {
    onAccept({ [suggestion.field]: suggestion.suggested });
    if (candidate) setAcceptedFromRecords((current) => [...current, proposalKey(suggestion, candidate)]);
    else setAcceptedLocal((current) => [...current, localProposalKey(suggestion)]);
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
    setAcceptedFromRecords((current) => [...current, ...fields.map((field) => proposalKey(field, candidate))]);
    setChosenRecord(recordKey(candidate));
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
    chosen: provider,
    setProvider,
    lookUp,
    openFullRecord,
    refresh,
    accept,
    acceptRecord,
    isAccepted: (suggestion, candidate = null) => (candidate
      ? acceptedFromRecords.includes(proposalKey(suggestion, candidate))
      : acceptedLocal.includes(localProposalKey(suggestion))),
    isRecordChosen: (candidate, fields = []) => chosenRecord === recordKey(candidate)
      && fields.every((suggestion) => acceptedFromRecords.includes(proposalKey(suggestion, candidate))),
  };
}
