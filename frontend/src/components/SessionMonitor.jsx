import { useEffect, useState } from 'react';
import { useAuth } from '@/hooks/use-auth';
import { useToast } from '@/hooks/use-toast';
import { AlertDialog, AlertDialogAction, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';

/**
 * SessionMonitor component
 * 
 * This component monitors the session status and shows a dialog when the session expires.
 * It also handles redirecting to login when necessary.
 */
const SessionMonitor = () => {
  const { user, sessionExpired, refreshSession } = useAuth();
  const { toast } = useToast();
  const [showSessionExpiredDialog, setShowSessionExpiredDialog] = useState(false);
  
  // Monitor for session expiration
  useEffect(() => {
    if (sessionExpired) {
      setShowSessionExpiredDialog(true);
    }
  }, [sessionExpired]);
  
  // Set up global fetch interceptor to detect 401/403 responses
  useEffect(() => {
    // Store the original fetch function
    const originalFetch = window.fetch;
    
    // Create our modified fetch function
    window.fetch = async (...args) => {
      try {
        // Call the original fetch
        const response = await originalFetch(...args);
        
        // Check if the response indicates authentication issues
        if (response.status === 401 || response.status === 403) {
          // Clone the response so we can both check it and return it
          const clonedResponse = response.clone();
          
          // Try to parse the response to check for specific auth errors
          try {
            const data = await clonedResponse.json();
            
            // If this is an auth error and we're currently logged in
            if (user && (data.error === 'Unauthorized' || data.error === 'Forbidden' || 
                data.message?.toLowerCase().includes('session') || 
                data.message?.toLowerCase().includes('login'))) {
              
              // Verify with a direct session check
              const isSessionValid = await refreshSession();
              
              if (!isSessionValid) {
                setShowSessionExpiredDialog(true);
              }
            }
          } catch (e) {
            // If we can't parse the JSON, just check the session directly
            if (user) {
              const isSessionValid = await refreshSession();
              if (!isSessionValid) {
                setShowSessionExpiredDialog(true);
              }
            }
          }
        }
        
        return response;
      } catch (error) {
        // For network errors, we don't need to check the session
        throw error;
      }
    };
    
    // Clean up - restore the original fetch when component unmounts
    return () => {
      window.fetch = originalFetch;
    };
  }, [user, refreshSession]);
  
  // Handle dialog close
  const handleDialogClose = () => {
    setShowSessionExpiredDialog(false);
    // Redirect to login page
    window.location.href = '/login';
  };
  
  return (
    <>
      {showSessionExpiredDialog && (
        <AlertDialog open={showSessionExpiredDialog} onOpenChange={setShowSessionExpiredDialog}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Session Expired</AlertDialogTitle>
              <AlertDialogDescription>
                Your session has expired. Please log in again to continue.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogAction onClick={handleDialogClose}>
                Log in
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      )}
    </>
  );
};

export default SessionMonitor;
