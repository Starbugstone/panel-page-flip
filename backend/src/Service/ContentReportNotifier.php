<?php

namespace App\Service;

use App\Entity\ContentReport;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class ContentReportNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly Environment $twig,
        private readonly PublicUrl $publicUrl,
        #[Autowire('%mailer_from_address%')] private readonly string $fromAddress,
        #[Autowire('%mailer_from_name%')] private readonly string $fromName,
        #[Autowire('%legal_email%')] private readonly string $legalEmail,
    ) {
    }

    public function acknowledge(ContentReport $report): bool
    {
        return $this->send(fn () => (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($report->getReporterEmail())
            ->replyTo($this->legalEmail)
            ->subject(sprintf('Content report %s received', $report->getReference()))
            ->text($this->twig->render('emails/content_report_acknowledgement.txt.twig', [
                'report' => $report,
                'receiptReference' => $this->receiptReference($report),
            ])), $report);
    }

    public function notifyOperator(ContentReport $report): bool
    {
        return $this->send(fn () => (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($this->legalEmail)
            ->subject(sprintf('New content report %s', $report->getReference()))
            ->text($this->twig->render('emails/content_report_operator.txt.twig', [
                'report' => $report,
                'reviewUrl' => $this->publicUrl->to('/admin?tab=content-reports&report='.$report->getId()),
            ])), $report);
    }

    public function notifyOwner(ContentReport $report, User $owner, string $action): bool
    {
        return $this->send(fn () => (new Email())
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

    /** @param callable(): Email $message */
    private function send(callable $message, ContentReport $report): bool
    {
        try {
            $this->mailer->send($message());
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

    private function receiptReference(ContentReport $report): string
    {
        return $report->referenceKind()->maskForReceipt($report->getReportedReference());
    }
}
