import { FolderOpen, Info, Tag } from "lucide-react";
import { Badge } from "@/components/ui/badge.jsx";
import { Button } from "@/components/ui/button.jsx";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog.jsx";
import {
  APP_FOLDER,
  BEST_PRACTICES,
  FOLDER_TREE,
  NAMING_CONVENTIONS,
  ORGANISATION_EXAMPLES,
} from "@/components/dropbox/dropbox-guide-content";

const INDENT = ["", "ml-4", "ml-8", "ml-12"];

function Section({ icon: Icon, title, children }) {
  return (
    <div>
      <h3 className="mb-3 flex items-center gap-2 font-semibold">
        {Icon && <Icon className="h-4 w-4" />}
        {title}
      </h3>
      {children}
    </div>
  );
}

/** How to lay out a Dropbox folder so imports get useful tags. */
export function DropboxOrganizationGuide() {
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" className="flex items-center gap-2">
          <Info className="h-4 w-4" />
          How to organize files
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[80vh] max-w-4xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <FolderOpen className="h-5 w-5" />
            Dropbox File Organization Guide
          </DialogTitle>
          <DialogDescription>
            Learn how to organize supported comic files in Dropbox for automatic tagging
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-6">
          <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950">
            <h3 className="mb-2 font-semibold text-blue-900 dark:text-blue-100">Quick Summary</h3>
            <p className="text-sm text-blue-800 dark:text-blue-200">
              Create folders inside your <code className="rounded bg-blue-100 px-1 py-0.5 dark:bg-blue-900">{APP_FOLDER}</code>.
              Each folder becomes a tag automatically! Supports nested folders and smart naming conversion.
            </p>
          </div>

          <Section icon={FolderOpen} title="Folder Structure Examples">
            <div className="rounded-lg bg-muted p-4 font-mono text-sm">
              <div className="space-y-1">
                {FOLDER_TREE.map((entry) => (
                  <div key={`${entry.depth}-${entry.label}`} className={INDENT[entry.depth]}>
                    {entry.label}
                    {entry.tags && <Badge variant="outline" className="ml-2 text-xs">→ {entry.tags}</Badge>}
                  </div>
                ))}
              </div>
            </div>
          </Section>

          <Section icon={Tag} title="Supported Naming Conventions">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {NAMING_CONVENTIONS.map((convention) => (
                <div key={convention.name} className="rounded-lg border p-3">
                  <div className="text-sm font-medium">{convention.name}</div>
                  <div className="text-xs text-muted-foreground">{convention.example}</div>
                </div>
              ))}
            </div>
          </Section>

          <Section title="Best Practices">
            <div className="space-y-2 text-sm">
              {BEST_PRACTICES.map((practice) => (
                <div key={practice.text} className="flex items-start gap-2">
                  <div className={`mt-2 h-2 w-2 flex-shrink-0 rounded-full ${practice.note ? "bg-blue-500" : "bg-green-500"}`} />
                  <div>{practice.text}</div>
                </div>
              ))}
            </div>
          </Section>

          <Section title="Common Organization Examples">
            <div className="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
              {ORGANISATION_EXAMPLES.map((example) => (
                <div key={example.title} className="space-y-2">
                  <div className="font-medium">{example.title}</div>
                  <div className="space-y-1 pl-4 text-muted-foreground">
                    {example.folders.map((folder) => <div key={folder}>{folder}</div>)}
                  </div>
                </div>
              ))}
            </div>
          </Section>
        </div>
      </DialogContent>
    </Dialog>
  );
}
