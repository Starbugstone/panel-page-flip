import { Cloud, Download, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge.jsx";
import { Button } from "@/components/ui/button.jsx";
import { APP_FOLDER } from "@/components/dropbox/dropbox-guide-content";

function DropboxFileRow({ file, isImporting, onImport }) {
  // A file at the top of the app folder has no folder worth naming.
  const inSubfolder = file.path && file.path !== `/${file.name}`;

  return (
    <div className="flex items-center justify-between rounded-lg border p-3">
      <div className="flex-1">
        <div className="mb-1 flex items-center gap-2">
          <p className="font-medium">{file.name}</p>
          <Badge variant={file.synced ? "default" : "secondary"}>
            {file.synced ? "Imported" : "Available"}
          </Badge>
        </div>
        {inSubfolder && <p className="mb-1 text-xs text-muted-foreground">📁 {file.path}</p>}
        <p className="text-sm text-muted-foreground">
          {file.size} • Modified: {new Date(file.modified).toLocaleDateString()}
        </p>
        {file.tags?.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1">
            {file.tags.map((tag) => <Badge key={tag} variant="outline" className="text-xs">{tag}</Badge>)}
          </div>
        )}
      </div>
      {!file.synced && (
        <Button
          size="sm"
          variant="outline"
          onClick={onImport}
          disabled={isImporting}
          className="ml-3 border-blue-600 text-blue-600 hover:bg-blue-50"
        >
          {isImporting
            ? <><Loader2 className="mr-1 h-3 w-3 animate-spin" />Importing...</>
            : <><Download className="mr-1 h-3 w-3" />Import</>}
        </Button>
      )}
    </div>
  );
}

/** Every comic the app folder holds, imported or not. */
export function DropboxFileList({ files, importingPaths, onImport }) {
  return (
    <div>
      <h3 className="mb-3 text-lg font-semibold">Dropbox Comics</h3>
      <p className="mb-4 text-sm text-muted-foreground">
        Comics found in your <code className="rounded bg-muted px-1 py-0.5">{APP_FOLDER}</code> folder
      </p>

      {files.length > 0 ? (
        <div className="grid gap-2">
          {files.map((file) => (
            <DropboxFileRow
              key={file.path ?? file.name}
              file={file}
              isImporting={importingPaths.has(file.path)}
              onImport={() => onImport(file)}
            />
          ))}
        </div>
      ) : (
        <div className="py-8 text-center text-muted-foreground">
          <Cloud className="mx-auto mb-4 h-12 w-12 opacity-50" />
          <p>No comics found in your Dropbox folder</p>
          <p className="text-sm">
            Add an enabled comic format to the <code>{APP_FOLDER}</code> folder in your Dropbox
          </p>
        </div>
      )}
    </div>
  );
}
