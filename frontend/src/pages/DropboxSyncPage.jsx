import { PageLayout, PageHeader, PageLoading } from "@/components/layout/PageLayout";
import { Link } from "react-router-dom";
import { Cloud, Info } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card.jsx";
import { DropboxConnectionPanel } from "@/components/dropbox/DropboxConnectionPanel";
import { DropboxOrganizationGuide } from "@/components/dropbox/DropboxOrganizationGuide";
import { APP_FOLDER } from "@/components/dropbox/dropbox-guide-content";
import { useDropboxSync } from "@/hooks/use-dropbox-sync";

const inlineCode = "rounded bg-amber-100 px-1 py-0.5 text-xs dark:bg-amber-900";

/** Importing comics from the Dropbox app folder, and the tagging that comes with it. */
function DropboxSyncPage() {
  const dropbox = useDropboxSync();

  if (dropbox.loading) return <PageLayout width="settings"><PageLoading label="Loading Dropbox status..." /></PageLayout>;

  return (
    <PageLayout width="settings" className="space-y-6">
      <PageHeader title="Dropbox Import" description="Import comics from your Dropbox app folder into Panel Page Flip."
        actions={dropbox.isConfigured && <DropboxOrganizationGuide />}
      >
        <p className="max-w-3xl text-sm leading-6 text-muted-foreground">
          Connecting authorizes Panel Page Flip to read file names and import files in enabled comic formats
          from its Dropbox app folder. Tokens are encrypted locally and can be disconnected at any time.{" "}
          <Link className="text-primary underline" to="/privacy">Privacy information</Link>
        </p>
      </PageHeader>

        {dropbox.isConfigured && <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950">
          <div className="flex items-start gap-2">
            <Info className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600 dark:text-amber-400" />
            <div className="text-sm">
              <span className="font-medium text-amber-800 dark:text-amber-200">Pro Tip:</span>
              <span className="ml-1 text-amber-700 dark:text-amber-300">
                Add supported comic files to your <code className={inlineCode}>{APP_FOLDER}</code>.
                Organize in subfolders like <code className={inlineCode}>superHero/</code> or <code className={inlineCode}>Manga/Action/</code> for automatic tagging!
              </span>
            </div>
          </div>
        </div>}

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Cloud className="h-6 w-6" />
              Connection Status
            </CardTitle>
            <CardDescription>Manage your Dropbox connection and imports</CardDescription>
          </CardHeader>
          <CardContent>
            <DropboxConnectionPanel dropbox={dropbox} />
          </CardContent>
        </Card>
    </PageLayout>
  );
}

export default DropboxSyncPage;
