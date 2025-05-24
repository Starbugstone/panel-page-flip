import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { usePendingShares } from '@/hooks/use-pending-shares';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useToast } from '@/hooks/use-toast';
import { BookOpen, X, Gift, Clock, Check, XCircle } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

export function PendingSharesAlert() {
  const { pendingShares, loading, refetch } = usePendingShares();
  const [dismissedShares, setDismissedShares] = useState([]);
  const [refusingShares, setRefusingShares] = useState({});
  const navigate = useNavigate();
  const { toast } = useToast();

  if (loading) {
    return null;
  }
  
  // Filter out dismissed shares
  const visibleShares = pendingShares.filter(share => !dismissedShares.includes(share.id));
  
  if (visibleShares.length === 0) {
    return null;
  }

  const handleDismiss = (shareId) => {
    setDismissedShares([...dismissedShares, shareId]);
  };

  const handleAccept = (token) => {
    navigate(`/share/accept/${token}`);
  };
  
  const handleRefuse = async (shareId, token) => {
    try {
      setRefusingShares(prev => ({ ...prev, [shareId]: true }));
      
      const response = await fetch(`/api/share/refuse/${token}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
      });
      
      if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.error || 'Failed to refuse share');
      }
      
      // Remove the share from the UI
      setDismissedShares(prev => [...prev, shareId]);
      
      toast({
        title: 'Share Refused',
        description: 'You have refused this shared comic.',
        variant: 'default',
      });
      
    } catch (error) {
      console.error('Error refusing share:', error);
      toast({
        title: 'Error',
        description: error.message || 'Failed to refuse share',
        variant: 'destructive',
      });
    } finally {
      setRefusingShares(prev => ({ ...prev, [shareId]: false }));
    }
  };

  return (
    <div className="mb-6 space-y-4">
      <Alert variant="default" className="bg-gradient-to-r from-purple-50 to-blue-50 dark:from-purple-950/30 dark:to-blue-950/30 border-purple-200 dark:border-purple-800">
        <Gift className="h-5 w-5 text-purple-500" />
        <AlertTitle className="text-purple-700 dark:text-purple-300 font-medium">Pending Shares</AlertTitle>
        <AlertDescription className="text-purple-600 dark:text-purple-400">
          You have {visibleShares.length} pending comic {visibleShares.length === 1 ? 'share' : 'shares'} to review.
        </AlertDescription>
      </Alert>
      
      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
        {visibleShares.map(share => (
          <Card key={share.id} className="overflow-hidden border-purple-100 dark:border-purple-900 shadow-md">
            <div className="relative">
              <Button 
                variant="ghost" 
                size="icon" 
                className="absolute top-2 right-2 bg-background/80 backdrop-blur-sm hover:bg-background/90 z-10"
                onClick={() => handleDismiss(share.id)}
              >
                <X className="h-4 w-4" />
              </Button>
            </div>
            
            <CardContent className="p-4 space-y-4">
              <div className="flex items-center gap-2 text-purple-700 dark:text-purple-300">
                <BookOpen className="h-4 w-4" />
                <h3 className="font-medium">{share.comic.title}</h3>
              </div>
              
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Clock className="h-3 w-3" />
                <span>Expires in {new Date(share.expiresAt).toLocaleDateString()}</span>
              </div>
              
              <p className="text-sm text-muted-foreground">
                Shared by <span className="font-medium">{share.sharedBy.name}</span>
              </p>
              
              <div className="flex gap-2">
                <Button 
                  className="flex-1" 
                  onClick={() => handleAccept(share.token)}
                  disabled={refusingShares[share.id]}
                >
                  <Check className="mr-2 h-4 w-4" />
                  Accept
                </Button>
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button 
                        variant="outline" 
                        className="flex-none" 
                        onClick={() => handleRefuse(share.id, share.token)}
                        disabled={refusingShares[share.id]}
                      >
                        {refusingShares[share.id] ? (
                          <span className="flex items-center">
                            <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-muted-foreground" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                          </span>
                        ) : (
                          <XCircle className="h-4 w-4" />
                        )}
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                      <p>Refuse this comic</p>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
