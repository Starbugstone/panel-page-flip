import { useState } from "react";
import { HardDrive, Loader2, UserRound } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { UserStorageUsage } from "@/components/UserStorageUsage";
import { useAuth } from "@/hooks/use-auth";
import { useStorageUsage } from "@/hooks/use-storage-usage";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { stripUsernamePrefix, validateUsername } from "@/lib/sharing";

/**
 * The account itself: who you are, and how much room you have left.
 *
 * Both were previously only visible to an administrator looking at somebody
 * else's account. An upload refused for want of space is the first an account
 * heard about its quota, and a username — the handle other people share with —
 * could be changed through the API but nowhere in the interface.
 */
export function AccountSettingsCard() {
  const { user, checkAuth } = useAuth();
  const { toast } = useToast();
  const { usage, isLoading: isLoadingUsage } = useStorageUsage();
  // The field is a draft over the account's own username, not a second copy of
  // it: keyed by what the server last said, so a rename made anywhere else
  // replaces an untouched field rather than being overwritten by it.
  const [draft, setDraft] = useState({ forUsername: null, text: "" });
  const [isSaving, setIsSaving] = useState(false);

  const stored = user?.username || "";
  const username = draft.forUsername === stored ? draft.text : stored;
  const setUsername = (text) => setDraft({ forUsername: stored, text });

  const trimmed = stripUsernamePrefix(username);
  const unchanged = trimmed === (user?.username || "");
  // Only once something has been typed: telling somebody their own username is
  // wrong the moment the page loads would be an odd greeting.
  const problem = unchanged ? null : validateUsername(trimmed);

  const saveUsername = async () => {
    setIsSaving(true);

    try {
      await api.put("/api/users/username", { username: trimmed });
      // Back to reading the account, so the field shows the form the server
      // stored rather than whatever was typed to get there.
      setDraft({ forUsername: null, text: "" });
      await checkAuth();
      toast({
        title: "Username changed",
        description: `Other people now see you as @${trimmed}. `
          + "Anyone using your old username will need the new one. Your U- code still works.",
      });
    } catch (error) {
      logger.error("Changing the username failed:", error);
      toast({
        title: "Could not change your username",
        description: error.message || "Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2"><UserRound className="h-5 w-5" /> Your account</CardTitle>
        <CardDescription>
          Your username is how other people share comics with you. Your email address is never
          shown to them.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="space-y-2">
          <Label htmlFor="account-username">Username</Label>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
            <div className="flex-1 space-y-1">
              <Input
                id="account-username"
                value={username}
                onChange={(event) => setUsername(event.target.value)}
                maxLength={40}
                autoComplete="username"
                aria-describedby={problem ? "account-username-problem" : undefined}
                aria-invalid={problem ? "true" : undefined}
                disabled={isSaving}
              />
              {problem && (
                <p id="account-username-problem" className="text-sm text-destructive">{problem}</p>
              )}
            </div>
            <Button onClick={saveUsername} disabled={isSaving || unchanged || Boolean(problem)}>
              {isSaving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Change username
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">
            Changing it does not affect comics already shared with you. Anyone using your old
            username will need the new one, but your U- code stays the same unless you replace it.
          </p>
        </div>

        <div className="space-y-2 border-t pt-4">
          <h3 className="flex items-center gap-1.5 text-sm font-medium">
            <HardDrive className="h-4 w-4" /> Storage used
          </h3>
          {isLoadingUsage ? (
            <p className="text-sm text-muted-foreground">Loading storage usage…</p>
          ) : usage ? (
            <>
              <UserStorageUsage
                usedBytes={usage.storageUsedBytes}
                quotaBytes={usage.storageQuotaBytes}
                unmeasuredComicCount={usage.unmeasuredComicCount}
                className="max-w-md"
              />
              <p className="text-xs text-muted-foreground">
                {usage.comicCount === 1 ? "1 comic" : `${usage.comicCount} comics`} you own. Comics
                shared with you belong to their owner and do not count against your quota.
              </p>
            </>
          ) : (
            <p className="text-sm text-muted-foreground">
              Your storage usage could not be loaded. Reload the page to try again.
            </p>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
