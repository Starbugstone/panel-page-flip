import { useEffect, useState } from "react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { copyText } from "@/lib/clipboard";
import { logger } from "@/lib/logger";

const COPIED_FEEDBACK_MS = 2000;

/**
 * The username and `U-` code that identify this account to other people.
 *
 * A failed load is said on the page, not only in the log: without that the
 * panel sits on its placeholder code with both buttons disabled, which is
 * indistinguishable from a slow load and gives somebody nothing to do about it.
 */
export function useSharingIdentity() {
  const { toast } = useToast();
  const [identity, setIdentity] = useState(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [copied, setCopied] = useState(false);
  const [isRotating, setIsRotating] = useState(false);

  useEffect(() => {
    let ignore = false;

    api.get("/api/shares/user-code")
      .then((data) => { if (!ignore) setIdentity(data); })
      .catch((err) => {
        if (ignore) return;
        logger.error("Could not load your sharing identity:", err);
        setLoadFailed(true);
      });

    return () => { ignore = true; };
  }, []);

  const copyCode = async () => {
    if (await copyText(identity.userCode)) {
      setCopied(true);
      setTimeout(() => setCopied(false), COPIED_FEEDBACK_MS);
      return;
    }

    // Clipboard access can still be refused outright; the code is on screen and
    // selectable, so say so rather than pretending the copy worked.
    logger.error("Could not copy the user code.");
    toast({
      title: "Could not copy the code",
      description: "Select the code and copy it manually.",
      variant: "destructive",
    });
  };

  /** @returns {boolean} whether the confirmation should close */
  const rotate = async () => {
    setIsRotating(true);
    try {
      setIdentity(await api.post("/api/shares/user-code/rotate", {}));
      toast({
        title: "User code replaced",
        description: "The old code no longer works. Send the new one to anyone who needs it.",
      });
      return true;
    } catch (err) {
      logger.error("Rotating the user code failed:", err);
      toast({
        title: "Could not replace the code",
        description: err.message || "Please try again.",
        variant: "destructive",
      });
      return false;
    } finally {
      setIsRotating(false);
    }
  };

  return { identity, loadFailed, copied, isRotating, copyCode, rotate };
}
