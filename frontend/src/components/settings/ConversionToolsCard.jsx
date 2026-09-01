import { Download, FileArchive } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { CONVERSION_TOOLS, CONVERSION_TOOLS_VERSION } from "@/lib/conversion-tools";

export function ConversionToolsCard() {
  return (
    <Card className="mt-6">
      <CardHeader>
        <CardTitle className="flex items-center gap-2"><FileArchive className="h-5 w-5" /> CBR to CBZ conversion tools</CardTitle>
        <CardDescription>
          Panel Page Flip accepts CBZ archives. These optional scripts convert every CBR file in a
          folder into a CBZ file. They require 7-Zip and run entirely on your own computer — nothing
          is uploaded and no conversion happens on the server.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="flex flex-col gap-3 sm:flex-row">
          {CONVERSION_TOOLS.map((tool) => (
            <Button key={tool.id} asChild variant="outline">
              <a href={tool.href} download={tool.fileName}>
                <Download className="mr-2 h-4 w-4" /> {tool.label}
              </a>
            </Button>
          ))}
        </div>

        <div className="space-y-2">
          <h3 className="text-sm font-medium">How to use it</h3>
          <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
            <li>Install <a className="underline" href="https://www.7-zip.org/" target="_blank" rel="noreferrer noopener">7-Zip</a>. On Linux, RAR support may be a separate package (<code>p7zip-rar</code>).</li>
            <li>Download and unzip the script for your system.</li>
            <li>Put it in the folder containing the CBR files you want to convert.</li>
            <li>
              Run it. On Windows, right-click the <code>.ps1</code> and choose “Run with PowerShell”;
              on Linux or macOS, run <code>./convert-cbr-to-cbz.sh</code> from a terminal.
            </li>
            <li>Check the generated CBZ files open correctly, then upload them here.</li>
          </ol>
          <p className="text-sm text-muted-foreground">
            Windows blocks scripts downloaded from the internet. Unblock just this one with{" "}
            <code>Unblock-File .\Convert-CbrToCbz.ps1</code> rather than changing your machine’s
            execution policy.
          </p>
        </div>

        <div className="space-y-2 rounded-md border p-3">
          <h3 className="text-sm font-medium">Before you run it</h3>
          <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
            <li>These are convenience tools supplied without warranty. Read them before running them.</li>
            <li>They only create files on your own computer, and never make network requests.</li>
            <li>Your original CBR files are kept. Keep backups anyway, and check the results before deleting anything.</li>
            <li>Password-protected, damaged, multipart or otherwise unusual RAR archives may fail to convert.</li>
            <li>7-Zip is not bundled or redistributed here; you install it yourself.</li>
          </ul>
        </div>

        <div className="space-y-1 text-xs text-muted-foreground">
          <p>Version {CONVERSION_TOOLS_VERSION}. SHA-256 of each download, if you want to verify it:</p>
          {CONVERSION_TOOLS.map((tool) => (
            <p key={tool.id} className="break-all font-mono">
              {tool.fileName}: {tool.sha256}
            </p>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
