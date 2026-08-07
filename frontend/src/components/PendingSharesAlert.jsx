import { Link } from 'react-router-dom';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Gift } from 'lucide-react';
import { useSharing } from '@/hooks/use-sharing';

/**
 * A one-line prompt on the dashboard when invitations are waiting.
 *
 * Deliberately not a card per invitation: the collection is for reading, and
 * deciding what to do with an invitation belongs on the Sharing page, which has
 * room to say what is actually being offered.
 */
export function PendingSharesAlert() {
  const { summary } = useSharing();
  const count = summary.pendingInvitations;

  if (count <= 0) {
    return null;
  }

  return (
    <Alert className="mb-6 flex flex-wrap items-center justify-between gap-3 border-purple-200 bg-gradient-to-r from-purple-50 to-blue-50 dark:border-purple-800 dark:from-purple-950/30 dark:to-blue-950/30">
      <div className="flex items-start gap-3">
        <Gift className="h-5 w-5 text-purple-500" />
        <div>
          <AlertTitle className="font-medium text-purple-700 dark:text-purple-300">
            {count === 1 ? 'You have a comic invitation.' : `You have ${count} comic invitations.`}
          </AlertTitle>
          <AlertDescription className="text-purple-600 dark:text-purple-400">
            Somebody wants to share a comic with you.
          </AlertDescription>
        </div>
      </div>
      <Button asChild size="sm" variant="secondary">
        <Link to="/sharing">Review invitations</Link>
      </Button>
    </Alert>
  );
}
