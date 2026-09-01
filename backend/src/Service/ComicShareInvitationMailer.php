<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/** Renders and transports notices for already-created sharing relationships. */
final class ComicShareInvitationMailer
{
    public const MAX_LISTED_INVITATIONS = 20;

    private const SUMMARY_SAMPLE_SIZE = 10;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly PublicUrl $publicUrl,
        #[Autowire('%mailer_from_address%')]
        private readonly string $mailerFromAddress,
        #[Autowire('%mailer_from_name%')]
        private readonly string $mailerFromName,
    ) {
    }

    public function send(ComicShare $share, Comic $comic, User $owner, string $plaintextToken): void
    {
        $ownerName = $owner->getName() ?: '@'.$owner->getUsername();

        // Email is previewed on lock screens and read by scanners. Explicit
        // comic identity therefore never enters its template context.
        $body = $this->twig->render('emails/share_comic.html.twig', [
            'comic' => $comic,
            'explicitContent' => $comic->isExplicitContent(),
            'userName' => $ownerName,
            'siteName' => $this->mailerFromName,
            'shareLink' => $this->invitationUrl($plaintextToken),
            'privacyUrl' => $this->publicUrl->to('/privacy'),
            'expiresAt' => $share->getExpiresAt(),
        ]);

        $email = (new Email())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->replyTo((string) $owner->getEmail())
            ->to($share->getRecipientEmailNormalized())
            ->subject($ownerName.' shared a comic with you!')
            ->html($body);

        $this->mailer->send($email);
    }

    /**
     * A hand-picked bulk share keeps one link per comic until the message would
     * become unwieldy. A folder share always carries its one batch link.
     *
     * @param list<PreparedInvitation> $prepared
     */
    public function sendGrouped(array $prepared, User $owner, ?string $folderName = null): void
    {
        $isFolderBatch = $prepared[0]->share->getInvitationBatchId() !== null;
        if ($isFolderBatch && $folderName === null) {
            $folderName = $prepared[0]->share->getInvitationBatchName();
        }

        if (count($prepared) === 1 && !$isFolderBatch) {
            $only = $prepared[0];
            $this->send($only->share, $only->comic, $owner, $only->plaintextToken);

            return;
        }

        $ownerName = $owner->getName() ?: '@'.$owner->getUsername();
        $recipient = $prepared[0]->share->getRecipientEmailNormalized();
        $expiresAt = $prepared[0]->share->getExpiresAt();
        $listLinks = !$isFolderBatch && count($prepared) <= self::MAX_LISTED_INVITATIONS;

        $invitations = array_map(
            function (PreparedInvitation $invitation) use ($listLinks): array {
                $explicit = $invitation->comic->isExplicitContent();

                return [
                    'title' => $explicit ? null : $invitation->comic->getTitle(),
                    'author' => $explicit ? null : $invitation->comic->getAuthor(),
                    'explicitContent' => $explicit,
                    'shareLink' => $listLinks ? $this->invitationUrl($invitation->plaintextToken) : null,
                ];
            },
            $prepared
        );

        $body = $this->twig->render('emails/share_comics.html.twig', [
            'invitations' => $invitations,
            'listLinks' => $listLinks,
            'isFolderBatch' => $isFolderBatch,
            'batchLink' => $isFolderBatch
                ? $this->invitationUrl($prepared[0]->plaintextToken)
                : null,
            'sampleTitles' => $listLinks ? [] : $this->summaryTitles($invitations),
            'sharingUrl' => $this->publicUrl->to('/sharing'),
            'folderName' => $folderName,
            'comicCount' => count($invitations),
            'explicitCount' => count(array_filter($invitations, static fn (array $invitation): bool => $invitation['explicitContent'])),
            'userName' => $ownerName,
            'siteName' => $this->mailerFromName,
            'privacyUrl' => $this->publicUrl->to('/privacy'),
            'expiresAt' => $expiresAt,
        ]);

        $email = (new Email())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->replyTo((string) $owner->getEmail())
            ->to($recipient)
            ->subject($this->subject($ownerName, count($invitations), $folderName))
            ->html($body);

        $this->mailer->send($email);
    }

    public function invitationUrl(string $plaintextToken): string
    {
        return $this->publicUrl->to('/share/invitation/'.$plaintextToken);
    }

    private function subject(string $ownerName, int $comicCount, ?string $folderName): string
    {
        $noun = $comicCount === 1 ? 'comic' : 'comics';
        if ($folderName === null) {
            return sprintf('%s shared %d %s with you!', $ownerName, $comicCount, $noun);
        }

        return sprintf('%s shared %d %s from "%s" with you!', $ownerName, $comicCount, $noun, $folderName);
    }

    /**
     * @param list<array{title: string|null, explicitContent: bool}> $invitations
     *
     * @return list<string>
     */
    private function summaryTitles(array $invitations): array
    {
        $titles = [];
        foreach ($invitations as $invitation) {
            if ($invitation['explicitContent'] || $invitation['title'] === null) {
                continue;
            }

            $titles[] = $invitation['title'];
            if (count($titles) === self::SUMMARY_SAMPLE_SIZE) {
                break;
            }
        }

        return $titles;
    }
}
