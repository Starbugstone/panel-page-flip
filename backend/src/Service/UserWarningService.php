<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Entity\UserWarning;
use App\Repository\UserWarningRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Issuing, delivering and dismissing an administrator's notice to one account.
 *
 * The in-app notice is the delivery. The email is a copy of it, requested per
 * warning, and its failure is recorded rather than raised: a warning that
 * exists and is waiting on the recipient's next visit has done its job whether
 * or not the mail server was reachable that afternoon. Making the email
 * authoritative would mean an unreachable inbox silently swallowing a notice
 * the recipient is about to sign in and read.
 */
final class UserWarningService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserWarningRepository $warnings,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[Autowire('%mailer_from_address%')] private readonly string $fromAddress,
        #[Autowire('%mailer_from_name%')] private readonly string $fromName,
        #[Autowire('%legal_email%')] private readonly string $contactEmail,
    ) {
    }

    /**
     * Reduce what an administrator typed to something storable, or say why not.
     *
     * Whitespace-only is empty: a warning with no words in it tells the
     * recipient that something is wrong and nothing about what, which is worse
     * than not sending one.
     *
     * @return array{0: string|null, 1: string|null} The message, or null and a reason.
     */
    public static function normaliseMessage(mixed $candidate): array
    {
        if (!is_string($candidate)) {
            return [null, 'A message is required.'];
        }

        // Normalised before it is measured, so a message padded to the limit
        // with blank lines is not accepted and then stored shorter than it was
        // judged.
        $message = trim(preg_replace('/\R/u', "\n", $candidate) ?? '');

        if ($message === '') {
            return [null, 'A message is required.'];
        }

        if (mb_strlen($message) > UserWarning::MAX_MESSAGE_LENGTH) {
            return [null, sprintf(
                'A message can be at most %d characters.',
                UserWarning::MAX_MESSAGE_LENGTH,
            )];
        }

        return [$message, null];
    }

    /**
     * Warn an account, optionally emailing them a copy.
     *
     * The comic or share it is about is stored both ways round: as a reference
     * that follows the thing, and as a label written down now. The usual reason
     * to warn somebody about a comic is that the comic is about to be removed,
     * and a notice that then reads as a complaint about nothing in particular
     * is no use to the person receiving it.
     */
    public function issue(
        User $recipient,
        ?User $issuedBy,
        string $message,
        string $subject = UserWarning::SUBJECT_ACCOUNT,
        ?Comic $comic = null,
        ?ComicShare $share = null,
        bool $sendEmail = false,
    ): UserWarning {
        $warning = new UserWarning(
            $recipient,
            $issuedBy,
            $message,
            $subject,
            $this->describeSubject($subject, $comic, $share),
        );
        $warning->about($comic, $share);

        $this->entityManager->persist($warning);
        // Flushed before the email, so a mail server that hangs cannot leave the
        // recipient without the notice that is the actual delivery.
        $this->entityManager->flush();

        if ($sendEmail) {
            $warning->recordEmailSent();
            if (!$this->emailCopy($warning)) {
                $warning->recordEmailFailed();
            }
            $this->entityManager->flush();
        }

        $this->auditLogger->audit(SecurityAuditLogger::USER_WARNING_ISSUED, [
            'actor_user_id' => $issuedBy?->getId(),
            'target_type' => 'user',
            'target_id' => $recipient->getId(),
            // Identifiers and shape only. The message is the administrator's
            // words to one person; the durable copy of it is the row, and
            // repeating it into the log would put it somewhere with a different
            // retention and a different audience.
            'warning_id' => $warning->getId(),
            'subject' => $warning->getSubject(),
            'comic_id' => $comic?->getId(),
            'share_id' => $share?->getId(),
            'email_state' => $warning->getEmailState(),
        ]);

        return $warning;
    }

    /** @return list<UserWarning> */
    public function openFor(User $recipient): array
    {
        return $this->warnings->findOpenFor($recipient);
    }

    /**
     * Dismiss one of your own warnings.
     *
     * Somebody else's is reported as missing rather than forbidden, so an id
     * cannot be used to find out whether an account has been warned.
     */
    public function acknowledge(int $warningId, User $recipient): bool
    {
        $warning = $this->warnings->find($warningId);

        if ($warning === null || $warning->getRecipient()->getId() !== $recipient->getId()) {
            return false;
        }

        $warning->acknowledge();
        $this->entityManager->flush();

        return true;
    }

    private function describeSubject(string $subject, ?Comic $comic, ?ComicShare $share): ?string
    {
        if ($subject === UserWarning::SUBJECT_COMIC && $comic !== null) {
            return $this->truncateLabel((string) $comic->getTitle());
        }

        if ($subject === UserWarning::SUBJECT_SHARE) {
            $title = $share?->getComic()?->getTitle() ?? $comic?->getTitle();

            return $title === null ? null : $this->truncateLabel((string) $title);
        }

        return null;
    }

    /** The column is 255; a comic title is not guaranteed to be. */
    private function truncateLabel(string $label): string
    {
        return mb_strlen($label) > 255 ? mb_substr($label, 0, 252) . '…' : $label;
    }

    private function emailCopy(UserWarning $warning): bool
    {
        $address = $warning->getRecipient()->getEmail();

        if (!is_string($address) || $address === '') {
            return false;
        }

        try {
            $body = $this->twig->render('emails/user_warning.html.twig', [
                'site_name' => $this->fromName,
                'recipient_name' => $warning->getRecipient()->getName(),
                'message' => $warning->getMessage(),
                'subject' => $warning->getSubject(),
                'subject_label' => $warning->getSubjectLabel(),
                'contact_email' => $this->contactEmail,
            ]);

            $this->mailer->send((new Email())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to($address)
                ->replyTo($this->contactEmail)
                ->subject(sprintf('A notice about your %s account', $this->fromName))
                ->html($body));

            return true;
        } catch (\Throwable $exception) {
            // The message itself is not logged: it is the administrator's words
            // to one person, and a mailer exception can carry headers with the
            // recipient's address in them.
            $this->logger->error('A warning email could not be sent.', [
                'warning_id' => $warning->getId(),
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }
}
