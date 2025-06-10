import React, { useState, useEffect } from 'react';
import { useAuth } from '@/hooks/use-auth.jsx';
import { useToast } from '@/hooks/use-toast.js';
import { Button } from '@/components/ui/button.jsx';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card.jsx';
import { Badge } from '@/components/ui/badge.jsx';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog.jsx';
import { Loader2, Cloud, Download, AlertCircle, CheckCircle, RefreshCw, Info, FolderOpen, Tag } from 'lucide-react';

// Organization Guide Component
const OrganizationGuide = () => {
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" className="flex items-center gap-2">
          <Info className="h-4 w-4" />
          How to organize files
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-4xl max-h-[80vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <FolderOpen className="h-5 w-5" />
            Dropbox File Organization Guide
          </DialogTitle>
          <DialogDescription>
            Learn how to organize your CBZ files in Dropbox for automatic tagging
          </DialogDescription>
        </DialogHeader>
        
        <div className="space-y-6">
          {/* Quick Summary */}
          <div className="p-4 bg-blue-50 dark:bg-blue-950 rounded-lg border border-blue-200 dark:border-blue-800">
            <h3 className="font-semibold text-blue-900 dark:text-blue-100 mb-2">Quick Summary</h3>
            <p className="text-blue-800 dark:text-blue-200 text-sm">
              Create folders in your <code className="bg-blue-100 dark:bg-blue-900 px-1 py-0.5 rounded">Applications/StarbugStoneComics</code> directory. 
              Each folder becomes a tag automatically! Supports nested folders and smart naming conversion.
            </p>
          </div>

          {/* Folder Structure Examples */}
          <div>
            <h3 className="font-semibold mb-3 flex items-center gap-2">
              <FolderOpen className="h-4 w-4" />
              Folder Structure Examples
            </h3>
            <div className="bg-muted p-4 rounded-lg font-mono text-sm">
              <div className="space-y-1">
                <div>📁 Applications/StarbugStoneComics/</div>
                <div className="ml-4">📄 Superman.cbz <Badge variant="outline" className="ml-2 text-xs">→ Dropbox</Badge></div>
                <div className="ml-4">📁 superHero/</div>
                <div className="ml-8">📄 Batman.cbz <Badge variant="outline" className="ml-2 text-xs">→ Dropbox, Super Hero</Badge></div>
                <div className="ml-8">📄 WonderWoman.cbz <Badge variant="outline" className="ml-2 text-xs">→ Dropbox, Super Hero</Badge></div>
                <div className="ml-4">📁 Manga/</div>
                <div className="ml-8">📄 naruto.cbz <Badge variant="outline" className="ml-2 text-xs">→ Dropbox, Manga</Badge></div>
                <div className="ml-8">📁 Anime/</div>
                <div className="ml-12">📄 blackCat.cbz <Badge variant="outline" className="ml-2 text-xs">→ Dropbox, Manga, Anime</Badge></div>
                <div className="ml-4">📁 sci-fi/</div>
                <div className="ml-8">📁 space_opera/</div>
                <div className="ml-12">📄 Foundation.cbz <Badge variant="outline" className="ml-2 text-xs">→ Dropbox, Sci Fi, Space Opera</Badge></div>
              </div>
            </div>
          </div>

          {/* Naming Conventions */}
          <div>
            <h3 className="font-semibold mb-3 flex items-center gap-2">
              <Tag className="h-4 w-4" />
              Supported Naming Conventions
            </h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-3">
                <div className="p-3 border rounded-lg">
                  <div className="font-medium text-sm">camelCase</div>
                  <div className="text-xs text-muted-foreground">superHero → "Super Hero"</div>
                </div>
                <div className="p-3 border rounded-lg">
                  <div className="font-medium text-sm">snake_case</div>
                  <div className="text-xs text-muted-foreground">space_opera → "Space Opera"</div>
                </div>
                <div className="p-3 border rounded-lg">
                  <div className="font-medium text-sm">kebab-case</div>
                  <div className="text-xs text-muted-foreground">sci-fi → "Sci Fi"</div>
                </div>
              </div>
              <div className="space-y-3">
                <div className="p-3 border rounded-lg">
                  <div className="font-medium text-sm">UPPERCASE</div>
                  <div className="text-xs text-muted-foreground">MANGA → "Manga"</div>
                </div>
                <div className="p-3 border rounded-lg">
                  <div className="font-medium text-sm">PascalCase</div>
                  <div className="text-xs text-muted-foreground">ActionAdventure → "Action Adventure"</div>
                </div>
                <div className="p-3 border rounded-lg">
                  <div className="font-medium text-sm">Mixed</div>
                  <div className="text-xs text-muted-foreground">Any combination works!</div>
                </div>
              </div>
            </div>
          </div>

          {/* Best Practices */}
          <div>
            <h3 className="font-semibold mb-3">Best Practices</h3>
            <div className="space-y-2 text-sm">
              <div className="flex items-start gap-2">
                <div className="w-2 h-2 bg-green-500 rounded-full mt-2 flex-shrink-0"></div>
                <div>Use descriptive folder names that make sense as tags</div>
              </div>
              <div className="flex items-start gap-2">
                <div className="w-2 h-2 bg-green-500 rounded-full mt-2 flex-shrink-0"></div>
                <div>Nest folders for hierarchical organization (Genre → Subgenre)</div>
              </div>
              <div className="flex items-start gap-2">
                <div className="w-2 h-2 bg-green-500 rounded-full mt-2 flex-shrink-0"></div>
                <div>Keep folder names concise but meaningful</div>
              </div>
              <div className="flex items-start gap-2">
                <div className="w-2 h-2 bg-green-500 rounded-full mt-2 flex-shrink-0"></div>
                <div>Use consistent naming conventions within your collection</div>
              </div>
              <div className="flex items-start gap-2">
                <div className="w-2 h-2 bg-blue-500 rounded-full mt-2 flex-shrink-0"></div>
                <div>Files in the root folder only get the "Dropbox" tag</div>
              </div>
            </div>
          </div>

          {/* Common Examples */}
          <div>
            <h3 className="font-semibold mb-3">Common Organization Examples</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div className="space-y-2">
                <div className="font-medium">By Genre:</div>
                <div className="pl-4 space-y-1 text-muted-foreground">
                  <div>📁 Action/</div>
                  <div>📁 Comedy/</div>
                  <div>📁 Drama/</div>
                  <div>📁 Fantasy/</div>
                  <div>📁 Horror/</div>
                </div>
              </div>
              <div className="space-y-2">
                <div className="font-medium">By Publisher:</div>
                <div className="pl-4 space-y-1 text-muted-foreground">
                  <div>📁 Marvel/</div>
                  <div>📁 DC_Comics/</div>
                  <div>📁 Image/</div>
                  <div>📁 Dark_Horse/</div>
                </div>
              </div>
              <div className="space-y-2">
                <div className="font-medium">By Series:</div>
                <div className="pl-4 space-y-1 text-muted-foreground">
                  <div>📁 Batman/</div>
                  <div>📁 Spider-Man/</div>
                  <div>📁 X-Men/</div>
                  <div>📁 Walking_Dead/</div>
                </div>
              </div>
              <div className="space-y-2">
                <div className="font-medium">Mixed Approach:</div>
                <div className="pl-4 space-y-1 text-muted-foreground">
                  <div>📁 Marvel/superHero/</div>
                  <div>📁 Manga/Action/</div>
                  <div>📁 Indie/sci-fi/</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
};

function DropboxSyncPage() {
  const { user } = useAuth();
  const { toast } = useToast();
  const [isConnected, setIsConnected] = useState(false);
  const [dropboxUser, setDropboxUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [syncStatus, setSyncStatus] = useState(null);
  const [lastSync, setLastSync] = useState(null);
  const [dropboxFiles, setDropboxFiles] = useState([]);
  const [importingFiles, setImportingFiles] = useState(new Set());
  const [refreshingFiles, setRefreshingFiles] = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);
  const [connecting, setConnecting] = useState(false);

  // Check connection status on component mount
  useEffect(() => {
    checkConnectionStatus();
    // Check for connection success from URL params
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'connected') {
      toast({
        title: "Dropbox Connected!",
        description: "Your Dropbox account has been successfully connected.",
      });
      // Clean up URL
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  }, []);

  const checkConnectionStatus = async () => {
    try {
      const response = await fetch('/api/dropbox/status', {
        credentials: 'include'
      });
      
      if (response.ok) {
        const data = await response.json();
        setIsConnected(data.connected);
        setDropboxUser(data.user);
        setLastSync(data.lastSync);
        if (data.connected) {
          await fetchDropboxFiles(false); // Don't show toast on initial load
        }
      }
    } catch (error) {
      console.error('Error checking Dropbox status:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchDropboxFiles = async (showToast = true) => {
    setRefreshingFiles(true);
    try {
      const response = await fetch('/api/dropbox/files', {
        credentials: 'include'
      });
      
      if (response.ok) {
        const data = await response.json();
        setDropboxFiles(data.files || []);
        if (showToast) {
          toast({
            title: "Files Refreshed",
            description: `Found ${data.files?.length || 0} comics in your Dropbox folder.`,
          });
        }
      } else {
        toast({
          title: "Refresh Failed",
          description: "Failed to refresh Dropbox files.",
          variant: "destructive"
        });
      }
    } catch (error) {
      console.error('Error fetching Dropbox files:', error);
      toast({
        title: "Refresh Failed",
        description: "Network error occurred while refreshing files.",
        variant: "destructive"
      });
    } finally {
      setRefreshingFiles(false);
    }
  };

  const handleConnectDropbox = () => {
    setConnecting(true);
    // Add a small delay to show the loading state before redirect
    setTimeout(() => {
      window.location.href = '/api/dropbox/connect';
    }, 100);
  };

  const handleDisconnectDropbox = async () => {
    setDisconnecting(true);
    try {
      const response = await fetch('/api/dropbox/disconnect', {
        method: 'POST',
        credentials: 'include'
      });
      
      if (response.ok) {
        setIsConnected(false);
        setDropboxUser(null);
        setDropboxFiles([]);
        toast({
          title: "Dropbox Disconnected",
          description: "Your Dropbox account has been disconnected.",
        });
      } else {
        toast({
          title: "Disconnect Failed",
          description: "Failed to disconnect Dropbox account.",
          variant: "destructive"
        });
      }
    } catch (error) {
      toast({
        title: "Disconnect Failed",
        description: "Network error occurred while disconnecting.",
        variant: "destructive"
      });
    } finally {
      setDisconnecting(false);
    }
  };

  const handleImportSingle = async (fileName) => {
    setImportingFiles(prev => new Set([...prev, fileName]));
    
    try {
      const response = await fetch('/api/dropbox/import', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({ fileName })
      });
      
      if (response.ok) {
        const data = await response.json();
        toast({
          title: "Import Successful",
          description: `${data.comic?.title || fileName} has been imported successfully.`,
        });
        // Refresh the files list to update sync status
        fetchDropboxFiles(false); // Don't show toast after import
      } else {
        const error = await response.json();
        toast({
          title: "Import Failed",
          description: error.error || 'Failed to import comic',
          variant: "destructive"
        });
      }
    } catch (error) {
      toast({
        title: "Import Failed",
        description: "Network error occurred during import.",
        variant: "destructive"
      });
    } finally {
      setImportingFiles(prev => {
        const newSet = new Set(prev);
        newSet.delete(fileName);
        return newSet;
      });
    }
  };

  const handleSync = async () => {
    setSyncing(true);
    setSyncStatus('Syncing...');
    
    try {
      const response = await fetch('/api/dropbox/sync', {
        method: 'POST',
        credentials: 'include'
      });
      
      if (response.ok) {
        const data = await response.json();
        setSyncStatus(`Sync completed: ${data.newFiles || 0} new files added`);
        setLastSync(new Date().toISOString());
        toast({
          title: "Sync Complete",
          description: `${data.newFiles || 0} new comics have been synced from Dropbox.`,
        });
        // Refresh the files list
        fetchDropboxFiles();
      } else {
        const error = await response.json();
        setSyncStatus(`Sync failed: ${error.message}`);
        toast({
          title: "Sync Failed",
          description: error.message,
          variant: "destructive"
        });
      }
    } catch (error) {
      setSyncStatus('Sync failed: Network error');
      toast({
        title: "Sync Failed",
        description: "Network error occurred during sync.",
        variant: "destructive"
      });
    } finally {
      setSyncing(false);
    }
  };

  if (loading) {
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
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Organization Guide Header */}
        <div className="flex items-center justify-between mb-4">
          <div>
            <h1 className="text-3xl font-bold mb-2">Dropbox Sync</h1>
            <p className="text-muted-foreground">
              Sync your comic collection with Dropbox for easy access across devices.
            </p>
          </div>
          <OrganizationGuide />
        </div>
        
        {/* Quick Organization Tip */}
        <div className="p-3 bg-amber-50 dark:bg-amber-950 rounded-lg border border-amber-200 dark:border-amber-800 mb-6">
          <div className="flex items-start gap-2">
            <Info className="h-4 w-4 text-amber-600 dark:text-amber-400 mt-0.5 flex-shrink-0" />
            <div className="text-sm">
              <span className="font-medium text-amber-800 dark:text-amber-200">Pro Tip:</span>
              <span className="text-amber-700 dark:text-amber-300 ml-1">
                Add CBZ files to your <code className="bg-amber-100 dark:bg-amber-900 px-1 py-0.5 rounded text-xs">Applications/StarbugStoneComics</code> folder. 
                Organize in subfolders like <code className="bg-amber-100 dark:bg-amber-900 px-1 py-0.5 rounded text-xs">superHero/</code> or <code className="bg-amber-100 dark:bg-amber-900 px-1 py-0.5 rounded text-xs">Manga/Action/</code> for automatic tagging!
              </span>
            </div>
          </div>
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Cloud className="h-6 w-6" />
              Connection Status
            </CardTitle>
            <CardDescription>
              Manage your Dropbox connection and sync settings
            </CardDescription>
          </CardHeader>
          <CardContent>
            {isConnected ? (
              <div className="space-y-6">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="flex items-center gap-2 mb-2">
                      <CheckCircle className="h-5 w-5 text-green-500" />
                      <span className="font-semibold">Connected to Dropbox</span>
                    </div>
                    {dropboxUser && (
                      <p className="text-sm text-muted-foreground">
                        Account: {dropboxUser}
                      </p>
                    )}
                    {lastSync && (
                      <p className="text-sm text-muted-foreground">
                        Last sync: {new Date(lastSync).toLocaleString()}
                      </p>
                    )}
                  </div>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      onClick={fetchDropboxFiles}
                      disabled={refreshingFiles}
                      className="flex items-center gap-2"
                    >
                      <RefreshCw className={`h-4 w-4 ${refreshingFiles ? 'animate-spin' : ''}`} />
                      {refreshingFiles ? 'Refreshing...' : 'Refresh Files'}
                    </Button>
                    <Button
                      variant="outline"
                      onClick={handleDisconnectDropbox}
                      disabled={disconnecting}
                      className="text-red-600 hover:text-red-700 disabled:text-red-400"
                    >
                      {disconnecting ? (
                        <>
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                          Disconnecting...
                        </>
                      ) : (
                        'Disconnect'
                      )}
                    </Button>
                  </div>
                </div>

                {syncStatus && (
                  <div className="p-3 bg-muted rounded-lg">
                    <p className="text-sm">{syncStatus}</p>
                  </div>
                )}

                <div>
                  <h3 className="text-lg font-semibold mb-3">Dropbox Comics</h3>
                  <p className="text-sm text-muted-foreground mb-4">
                    Comics found in your <code className="bg-muted px-1 py-0.5 rounded">Applications/StarbugStoneComics</code> folder
                  </p>
                  
                  {dropboxFiles.length > 0 ? (
                    <div className="grid gap-2">
                      {dropboxFiles.map((file, index) => (
                        <div key={index} className="flex items-center justify-between p-3 border rounded-lg">
                          <div className="flex-1">
                            <div className="flex items-center gap-2 mb-1">
                              <p className="font-medium">{file.name}</p>
                              <Badge variant={file.synced ? "default" : "secondary"}>
                                {file.synced ? "Synced" : "Pending"}
                              </Badge>
                            </div>
                            {file.path && file.path !== `/${file.name}` && (
                              <p className="text-xs text-muted-foreground mb-1">
                                📁 {file.path}
                              </p>
                            )}
                            <p className="text-sm text-muted-foreground">
                              {file.size} • Modified: {new Date(file.modified).toLocaleDateString()}
                            </p>
                            {file.tags && file.tags.length > 0 && (
                              <div className="flex flex-wrap gap-1 mt-2">
                                {file.tags.map((tag, tagIndex) => (
                                  <Badge key={tagIndex} variant="outline" className="text-xs">
                                    {tag}
                                  </Badge>
                                ))}
                              </div>
                            )}
                          </div>
                          {!file.synced && (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleImportSingle(file.name)}
                              disabled={importingFiles.has(file.name)}
                              className="ml-3 text-blue-600 border-blue-600 hover:bg-blue-50"
                            >
                              {importingFiles.has(file.name) ? (
                                <>
                                  <Loader2 className="w-3 h-3 mr-1 animate-spin" />
                                  Importing...
                                </>
                              ) : (
                                <>
                                  <Download className="w-3 h-3 mr-1" />
                                  Import
                                </>
                              )}
                            </Button>
                          )}
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="text-center py-8 text-muted-foreground">
                      <Cloud className="h-12 w-12 mx-auto mb-4 opacity-50" />
                      <p>No comics found in your Dropbox folder</p>
                      <p className="text-sm">
                        Add .cbz files to the <code>Applications/StarbugStoneComics</code> folder in your Dropbox
                      </p>
                    </div>
                  )}
                </div>
              </div>
            ) : (
              <div className="text-center space-y-4">
                <div className="flex items-center justify-center gap-2 text-muted-foreground">
                  <AlertCircle className="h-5 w-5" />
                  <span>Not connected to Dropbox</span>
                </div>
                <p className="text-muted-foreground">
                  Connect your Dropbox account to automatically sync your comic collection.
                </p>
                <p className="text-sm text-muted-foreground">
                  Your comics should be placed in the <code className="bg-muted px-1 py-0.5 rounded">Applications/StarbugStoneComics</code> folder in your Dropbox.
                </p>
                <Button 
                  onClick={handleConnectDropbox} 
                  disabled={connecting}
                  className="flex items-center gap-2"
                >
                  {connecting ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin" />
                      Connecting...
                    </>
                  ) : (
                    <>
                      <Cloud className="h-4 w-4" />
                      Connect to Dropbox
                    </>
                  )}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

export default DropboxSyncPage;
