import { useCallback, useEffect, useState } from "react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { copyText } from "@/lib/clipboard";
import { logger } from "@/lib/logger";

const COPIED_FEEDBACK_MS = 2000;

/**
 * The content codes this account has issued, and what can still be done to them.
 *
 * A code's characters are never in the list payload. They are fetched one at a
 * time when the owner asks to see one, and held only for this visit — nothing
 * about a code survives leaving the page.
 */
export function useHandedOutCodes(reloadKey) {
  const { toast } = useToast();
  const [codes, setCodes] = useState([]);
  const [withdrawingId, setWithdrawingId] = useState(null);
  const [revealed, setRevealed] = useState({});
  const [revealingId, setRevealingId] = useState(null);
  const [copiedCodeId, setCopiedCodeId] = useState(null);

  const failed = (title) => (err, logLine) => {
    logger.error(logLine, err);
    toast({ title, description: err.message || "Please try again.", variant: "destructive" });
  };

  // Fetches without touching state, so both the effect below and the withdraw
  // handler can decide for themselves whether their result is still wanted.
  const fetchCodes = useCallback(
    () => api.get("/api/shares/content-codes")
      .then((data) => data.codes || [])
      .catch((err) => {
        // Not worth an error banner: the rest of the page works, and this list
        // is a record of what was handed out rather than what the page is for.
        logger.error("Could not load handed-out sharing codes:", err);
        return null;
      }),
    []
  );

  // Reloaded when the page says something changed — creating a code from the
  // share dialog is what usually adds one to this list.
  useEffect(() => {
    let ignore = false;
    fetchCodes().then((next) => { if (!ignore && next) setCodes(next); });
    return () => { ignore = true; };
  }, [fetchCodes, reloadKey]);

  const withdraw = async (code) => {
    setWithdrawingId(code.id);
    try {
      await api.delete(`/api/shares/content-codes/${code.id}`);
      toast({
        title: "Sharing code withdrawn",
        description: "Nobody else can use it. Anyone who already claimed a comic keeps it — "
          + "revoke them from Shared by me if you want it back.",
      });
      const next = await fetchCodes();
      if (next) setCodes(next);
    } catch (err) {
      failed("Could not withdraw the code")(err, "Withdrawing a sharing code failed:");
    } finally {
      setWithdrawingId(null);
    }
  };

  const toggleReveal = async (code) => {
    if (revealed[code.id]) {
      setRevealed((current) => {
        const next = { ...current };
        delete next[code.id];
        return next;
      });
      return;
    }

    setRevealingId(code.id);
    try {
      const data = await api.get(`/api/shares/content-codes/${code.id}/reveal`);
      setRevealed((current) => ({ ...current, [code.id]: data.code }));
    } catch (err) {
      failed("Could not show the code")(err, "Reading a sharing code back failed:");
    } finally {
      setRevealingId(null);
    }
  };

  const copyCode = async (code) => {
    if (await copyText(revealed[code.id])) {
      setCopiedCodeId(code.id);
      setTimeout(() => setCopiedCodeId(null), COPIED_FEEDBACK_MS);
      return;
    }

    logger.error("Could not copy a handed-out sharing code.");
    toast({
      title: "Could not copy the code",
      description: "Select the code and copy it manually.",
      variant: "destructive",
    });
  };

  return { codes, withdrawingId, revealed, revealingId, copiedCodeId, withdraw, toggleReveal, copyCode };
}
