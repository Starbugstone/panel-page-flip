import { useState } from "react";

import { AccountSettingsCard } from "@/components/AccountSettingsCard";
import { SignInMethodsCard } from "@/components/SignInMethodsCard";
import { UserMetadataCredentials } from "@/components/UserMetadataCredentials";
import { ConversionToolsCard } from "@/components/settings/ConversionToolsCard";
import { PersonalTagsCard } from "@/components/settings/PersonalTagsCard";
import { PrivacyAccountDataCard } from "@/components/settings/PrivacyAccountDataCard";
import { useSettingsOAuthResult } from "@/hooks/use-settings-oauth-result";

export default function UserSettings() {
  const oauthReauthenticated = useSettingsOAuthResult();
  const [oauthConnections, setOauthConnections] = useState([]);

  return (
    <div className="container mx-auto max-w-5xl px-4 py-8">
      <div className="mb-6">
        <h1 className="text-3xl font-comic">Settings</h1>
        <p className="mt-1 text-muted-foreground">Manage your account, your storage, and the tags that belong only to you.</p>
      </div>

      <div className="mb-6">
        <AccountSettingsCard />
      </div>

      <SignInMethodsCard onConnectionsChange={setOauthConnections} />
      <PersonalTagsCard />

      {/* Kept above account deletion so a download for an optional local tool
          never sits beside a destructive account action. */}
      <ConversionToolsCard />

      <div className="mt-6">
        <UserMetadataCredentials />
      </div>

      <PrivacyAccountDataCard
        oauthConnections={oauthConnections}
        initiallyOpen={oauthReauthenticated}
      />
    </div>
  );
}
