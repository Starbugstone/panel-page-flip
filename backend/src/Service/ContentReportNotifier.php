<?php

namespace App\Service;

use App\Entity\ContentReport;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class ContentReportNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%mailer_from_address%')] private readonly string $fromAddress,
        #[Autowire('%mailer_from_name%')] private readonly string $fromName,
        #[Autowire('%legal_email%')] private readonly string $legalEmail,
    ) {
    }

    public function acknowledge(ContentReport $report): bool
    {
        return $this->send((new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($report->getReporterEmail())
            ->replyTo($this->legalEmail)
            ->subject(sprintf('Content report %s received', $report->getReference()))
            ->text(sprintf(
                "Your report has been received and will be reviewed.\n\nReference: %s\n\nKeep this reference if you need to contact the site operator. This acknowledgement does not reproduce the allegation or promise a specific response time.",
                $report->getReference()
            )), $report);
    }

    public function notifyOwner(ContentReport $report, User $owner, string $action): bool
    {
        return $this->send((new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to((string) $owner->getEmail())
            ->replyTo($this->legalEmail)
            ->subject('Administrative action affecting your Panel Page Flip content')
            ->text(sprintf(
                "A content report was received and an administrator took action affecting your account or content.\n\nReport reference: %s\nAction: %s\n\nReporter contact details and private allegation material are not included. Contact %s if you believe this action is mistaken.",
                $report->getReference(),
                str_replace('_', ' ', $action),
                $this->legalEmail
            )), $report);
    }

    private function send(Email $email, ContentReport $report): bool
    {
        try {
            $this->mailer->send($email);
            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Content report notification could not be sent.', [
                'report_id' => $report->getId(),
                // Mailer exceptions can include headers such as the reporter's
                // address. Keep report personal data out of ordinary logs.
                'exception_class' => $exception::class,
            ]);
            return false;
        }
    }
}
