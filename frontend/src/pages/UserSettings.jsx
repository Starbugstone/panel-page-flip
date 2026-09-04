import { PageLayout, PageHeader } from "@/components/layout/PageLayout";
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
    <PageLayout width="settings">
      <PageHeader title="Settings" description="Manage your account, your storage, and the tags that belong only to you." />

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
    </PageLayout>
  );
}
