import { Link } from "react-router-dom";
import { Cloud, Info, Loader2 } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card.jsx";
import { DropboxConnectionPanel } from "@/components/dropbox/DropboxConnectionPanel";
import { DropboxOrganizationGuide } from "@/components/dropbox/DropboxOrganizationGuide";
import { APP_FOLDER } from "@/components/dropbox/dropbox-guide-content";
import { useDropboxSync } from "@/hooks/use-dropbox-sync";

const inlineCode = "rounded bg-amber-100 px-1 py-0.5 text-xs dark:bg-amber-900";

/** Importing comics from the Dropbox app folder, and the tagging that comes with it. */
function DropboxSyncPage() {
  const dropbox = useDropboxSync();

  if (dropbox.loading) {
    return (
      <div className="container mx-auto px-4 py-8">
        <div className="flex items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin" />
          <span className="ml-2">Loading Dropbox status...</span>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="mx-auto max-w-4xl space-y-6">
        <div className="mb-4 flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="min-w-0">
            <h1 className="mb-2 text-3xl font-bold">Dropbox Import</h1>
            <p className="text-muted-foreground">
              Import comics from your Dropbox app folder into Panel Page Flip.
            </p>
            <p className="mt-2 text-sm text-muted-foreground">
              Connecting authorizes Panel Page Flip to read file names and import files in enabled comic formats
              from its Dropbox app folder. Tokens are encrypted locally and can
              be disconnected at any time.{" "}
              <Link className="underline" to="/privacy">Privacy information</Link>
            </p>
          </div>
          {dropbox.isConfigured && <DropboxOrganizationGuide />}
        </div>

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
      </div>
    </div>
  );
}

export default DropboxSyncPage;
