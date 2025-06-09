import React from 'react';
// You might have a custom Button component, e.g., from '@/components/ui/button.jsx'
// For now, using a standard HTML button styled with Tailwind.
// import { Button } from "@/components/ui/button.jsx"; // If you have this, uncomment and use it

function DropboxSyncPage() {
  // In a real app, you'd fetch user's Dropbox connection status here
  const isConnected = false; // Placeholder: replace with actual state
  const dropboxUser = null; // Placeholder: replace with actual state

  const handleConnectDropbox = () => {
    // Redirect to the backend endpoint that starts the OAuth flow
    window.location.href = '/api/dropbox/connect';
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="bg-card text-card-foreground p-6 shadow-lg rounded-lg">
        <h1 className="text-3xl font-bold mb-6 text-center">Dropbox Sync</h1>

        {isConnected ? (
          <div className="text-center">
            <h2 className="text-xl font-semibold mb-2">Connected to Dropbox</h2>
            <p className="mb-4">Account: {dropboxUser || 'N/A'}</p>
            {/* Add Disconnect button and sync options here */}
            <button
              onClick={() => alert('Disconnect logic to be implemented')}
              className="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out"
            >
              Disconnect Dropbox
            </button>
          </div>
        ) : (
          <div className="text-center">
            <p className="mb-4 text-muted-foreground">
              Connect your Dropbox account to sync your comic collection.
            </p>
            <p className="mb-6 text-sm text-muted-foreground">
              Your comics should be placed in the <code className="bg-muted text-muted-foreground px-1 py-0.5 rounded">Apps/StarbugStoneComics</code> folder in your Dropbox.
            </p>
            <button 
              onClick={handleConnectDropbox}
              // If using Shadcn Button: <Button onClick={handleConnectDropbox} className="w-full md:w-auto">
              className="bg-primary hover:bg-primary/90 text-primary-foreground font-bold py-3 px-6 rounded-lg text-lg transition duration-150 ease-in-out shadow-md hover:shadow-lg transform hover:-translate-y-0.5"
            >
              Connect to Dropbox
            </button>
            {/* If using Shadcn Button: </Button> */}
          </div>
        )}
        {/* Future: Add sync status, manual sync button, logs, etc. */}
      </div>
    </div>
  );
}

export default DropboxSyncPage;
