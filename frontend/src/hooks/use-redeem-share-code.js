import { useState } from "react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { formatShareCode, parseShareCode } from "@/lib/sharing";

/** Claiming a `C-` or `G-` code somebody sent. */
export function useRedeemShareCode(onRedeemed) {
  const { toast } = useToast();
  const [value, setValue] = useState("");
  const [isRedeeming, setIsRedeeming] = useState(false);
  const [error, setError] = useState(null);

  const change = (raw) => {
    setValue(formatShareCode(raw));
    setError(null);
  };

  const redeem = async () => {
    setIsRedeeming(true);
    setError(null);

    try {
      const data = await api.post("/api/shares/content-codes/redeem", { code: parseShareCode(value)?.code });
      const claimed = Number(data.claimed) || 0;
      const gated = (data.results || []).filter((result) => result.status === "awaiting_age_confirmation").length;

      // A code can be spent without adding anything — every comic on it may
      // already be shared with this account. "0 comics added" as a success
      // toast reads like the feature working; it is not.
      if (claimed === 0) {
        setError(
          (data.results || []).find((result) => result.message)?.message
            || "That code added nothing new to your collection."
        );
        return;
      }

      toast({
        title: claimed === 1 ? "Comic added" : `${claimed} comics added`,
        description: gated > 0
          ? `From ${data.ownerLabel}. ${gated === 1 ? "One comic needs" : `${gated} comics need`} your age confirmed below.`
          : `${data.ownerLabel} shared ${claimed === 1 ? "a comic" : "these"} with you.`,
      });

      setValue("");

      try {
        await onRedeemed?.();
      } catch (refreshError) {
        logger.error("Sharing data refresh failed:", refreshError);
        toast({
          title: "Comics added",
          description: "They were added, but the page could not refresh. Reload to see them.",
          variant: "destructive",
        });
      }
    } catch (err) {
      logger.error("Redeeming a sharing code failed:", err);
      setError(err.message || "That code could not be redeemed.");
    } finally {
      setIsRedeeming(false);
    }
  };

  return { value, change, isRedeeming, error, redeem };
}
