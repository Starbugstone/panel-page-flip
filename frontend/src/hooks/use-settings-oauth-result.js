import { useEffect } from "react";
import { useSearchParams } from "react-router-dom";

import { useToast } from "@/hooks/use-toast";

const OAUTH_ERRORS = {
  identity_in_use: "That provider account is already connected to another user.",
  wrong_account: "The provider account did not match this user.",
  sign_in_required: "Your session expired before the provider could be connected.",
  invalid_state: "The provider response could not be verified. Please try again.",
  cancelled: "The provider connection was cancelled or not completed.",
  expired: "The provider connection expired. Please try again.",
};

export function useSettingsOAuthResult() {
  const { toast } = useToast();
  const [searchParams, setSearchParams] = useSearchParams();
  const reauthenticated = searchParams.get("oauth_reauthenticated");
  const connected = searchParams.get("oauth_connected");
  const error = searchParams.get("oauth_error");

  useEffect(() => {
    if (!reauthenticated && !connected && !error) return;

    if (reauthenticated) {
      toast({ title: "Identity confirmed", description: "Continue to delete the account within five minutes." });
    } else if (connected) {
      toast({ title: "Sign-in method connected", description: `${providerLabel(connected)} can now sign in to this account.` });
    } else {
      toast({
        title: "Provider connection unsuccessful",
        description: OAUTH_ERRORS[error] || "The provider connection could not be completed.",
        variant: "destructive",
      });
    }

    const next = new URLSearchParams(searchParams);
    ["oauth_reauthenticated", "oauth_connected", "oauth_error", "provider"].forEach((key) => next.delete(key));
    setSearchParams(next, { replace: true });
  }, [connected, error, reauthenticated, searchParams, setSearchParams, toast]);

  return reauthenticated !== null;
}

function providerLabel(provider) {
  return provider === "google" ? "Google" : provider;
}
