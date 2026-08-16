<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Entity\User;
use App\Security\Voter\ComicVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Marking comics 18+ from inside the sharing flow.
 *
 * The moment somebody decides a comic is adult is almost always the moment they
 * are about to hand it to somebody else — so making them leave the share, find
 * the comic, edit it and come back is how a comic goes out unmarked. This is
 * the same reclassification the editor performs, reachable from the share
 * dialog.
 *
 * Two things it will not do.
 *
 * It only ever **promotes**. An unticked box in a share dialog is the absence
 * of a claim, not a claim that the comic is fine, and reading it as one would
 * mean a share could quietly strip a classification somebody made deliberately.
 * Clearing 18+ stays an intentional edit on the comic itself.
 *
 * And it does not flush. The promotion and the share it belongs to are one
 * decision, so they land in one unit of work or neither does: an ordinary share
 * created because the reclassification failed would be exactly the accident
 * this exists to prevent.
 */
final class ExplicitContentPromoter
{
    public function __construct(
        private readonly ComicShareService $shareService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    /**
     * Mark every one of these comics 18+, and re-gate what they already reach.
     *
     * @param list<Comic> $comics comics the caller has already been authorised
     *                            to share; only the owner may reclassify, which
     *                            is checked again here rather than assumed
     *
     * @return int how many were newly marked
     *
     * @throws ShareException when one of them is not the caller's to classify
     */
    public function promote(array $comics, User $actor): int
    {
        $promoted = 0;
        $records = [];

        foreach ($comics as $comic) {
            if (!$this->authorizationChecker->isGranted(ComicVoter::EDIT, $comic)) {
                throw new ShareException(
                    'You can only mark comics you own as explicit content.',
                    403
                );
            }

            if ($comic->isExplicitContent()) {
                // Already classified. Shown to the sender as such rather than
                // re-marked, so the count reports what actually changed.
                continue;
            }

            $comic->setExplicitContent(true);
            ++$promoted;

            // A recipient who accepted this comic agreed to read something that
            // was not classified 18+, and their old silence is not a
            // declaration about the comic as it is now. Fails closed: reading
            // stops until each of them declares their age again.
            $records[] = [
                'actor_user_id' => $actor->getId(),
                'target_type' => 'comic',
                'target_id' => $comic->getId(),
                'comic_id' => $comic->getId(),
                'owner_user_id' => $comic->getOwner()?->getId(),
                'explicit_before' => false,
                'explicit_after' => true,
                'shares_regated' => $this->shareService->regateSharesForComic($comic),
                'via' => 'share_dialog',
            ];
        }

        foreach ($records as $record) {
            $this->auditLogger->audit(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED, $record);
        }

        return $promoted;
    }
}
