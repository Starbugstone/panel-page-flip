<?php

namespace App\Service;

use App\Entity\ContentReport;
use App\Enum\ReportedReferenceType;
use App\EventSubscriber\DeferredWork;
use Doctrine\ORM\EntityManagerInterface;

final class ContentReportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly ContentReportNotifier $notifier,
        private readonly ContentReportTargetResolver $targetResolver,
        private readonly ContentReportLinkService $linker,
        private readonly PublicUrl $publicUrl,
        private readonly DeferredWork $deferred,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function submit(array $data): ?ContentReport
    {
        if (array_key_exists('website', $data)
            && (!is_string($data['website']) || trim($data['website']) !== '')) {
            return null;
        }

        $values = [
            'reporterName' => $this->stringValue($data, 'reporterName'),
            'reporterOrganization' => $this->stringValue($data, 'reporterOrganization'),
            'reporterRole' => $this->stringValue($data, 'reporterRole'),
            'reporterEmail' => mb_strtolower($this->stringValue($data, 'reporterEmail')),
            'category' => $this->stringValue($data, 'category'),
            'referenceType' => $this->stringValue($data, 'referenceType') ?: ReportedReferenceType::Other->value,
            'reportedReference' => $this->stringValue($data, 'reportedReference'),
            'reportedContentTitle' => $this->stringValue($data, 'reportedContentTitle'),
            'reportedAccountReference' => $this->stringValue($data, 'reportedAccountReference'),
            'sourceContext' => $this->stringValue($data, 'sourceContext'),
            'explanation' => $this->stringValue($data, 'explanation'),
        ];

        $errors = $this->validate($values, ($data['goodFaithAcknowledged'] ?? null) === true);
        if ($errors !== []) {
            throw new ContentReportValidationException($errors);
        }

        $report = (new ContentReport(
            $values['reporterName'],
            $values['reporterEmail'],
            $values['category'],
            $values['reportedReference'],
            $values['explanation'],
        ))
            ->setReporterOrganization($values['reporterOrganization'] ?: null)
            ->setReporterRole($values['reporterRole'] ?: null)
            ->identifyTarget(
                $values['referenceType'],
                $values['reportedContentTitle'] ?: null,
                $values['reportedAccountReference'] ?: null,
                $values['sourceContext'] ?: null,
            );

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::CONTENT_REPORT_RECEIVED, [
            'target_type' => 'content_report',
            'target_id' => $report->getId(),
            'report_id' => $report->getId(),
            'category' => $report->getCategory(),
        ]);
        try {
            $target = $this->targetResolver->exactTarget($report);
            if ($target !== null) {
                $this->linker->select($report, $target['type'], $target['id'], $target['method']);
                $this->entityManager->flush();
                $this->auditLogger->audit(
                    SecurityAuditLogger::CONTENT_REPORT_TARGET_LINKED,
                    ContentReportLinkService::targetLinkedPayload($report, ['user' => null, 'comic' => null, 'share' => null]) + [
                        'resolved_target_type' => $target['type'],
                        'resolved_target_id' => $target['id'],
                    ],
                );
            }
        } catch (\Throwable) {
            $this->auditLogger->security(SecurityAuditLogger::DATA_INTEGRITY_FAILURE, [
                'target_type' => 'content_report',
                'target_id' => $report->getId(),
                'report_id' => $report->getId(),
                'operation' => 'content_report_target_resolution',
                'reason' => 'private target resolution failed',
            ], result: SecurityAuditLogger::RESULT_FAILED);
        }

        // Both messages are best effort and happen only after the durable row
        // exists. A transport failure cannot turn success into a duplicate POST.
        //
        // Deferred to kernel.terminate because mail is sent inline here: two
        // SMTP round trips would otherwise hold a worker open on a public,
        // unauthenticated endpoint while the reporter's browser waits.
        $this->deferred->defer(function () use ($report): void {
            $this->notifier->notifyOperator($report);
            $this->notifier->acknowledge($report);
        });

        return $report;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function validate(array $values, bool $goodFaith): array
    {
        $errors = [];
        if (mb_strlen($values['reporterName']) < 2 || mb_strlen($values['reporterName']) > 200) {
            $errors['reporterName'] = 'Provide the name of the person or organization submitting the report.';
        }
        if (!filter_var($values['reporterEmail'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['reporterEmail']) > 320) {
            $errors['reporterEmail'] = 'Provide a valid email address.';
        }
        if (!in_array($values['category'], ContentReport::CATEGORIES, true)) {
            $errors['category'] = 'Select a valid report category.';
        }
        if (ReportedReferenceType::tryFrom($values['referenceType']) === null) {
            $errors['referenceType'] = 'Select how the reported material can be identified.';
        }
        if (mb_strlen($values['reportedReference']) < 3 || mb_strlen($values['reportedReference']) > 2000) {
            $errors['reportedReference'] = 'Provide enough information to identify the reported material.';
        } elseif ($this->hasUnsafeScheme($values['reportedReference'])) {
            $errors['reportedReference'] = 'URL references must use http or https.';
        }
        $referenceType = ReportedReferenceType::tryFrom($values['referenceType']);
        $referenceError = $referenceType?->validate($values['reportedReference'], $this->publicUrl);
        if ($referenceError !== null) {
            $errors['reportedReference'] = $referenceError;
        }
        if (mb_strlen($values['reportedContentTitle']) > 255) {
            $errors['reportedContentTitle'] = 'Content title must be 255 characters or fewer.';
        } elseif ($referenceType?->requiresContentTitle() === true && mb_strlen($values['reportedContentTitle']) < 2) {
            $errors['reportedContentTitle'] = 'Provide the title of the reported comic or publication.';
        }
        if (mb_strlen($values['reportedAccountReference']) > 320) {
            $errors['reportedAccountReference'] = 'Account reference must be 320 characters or fewer.';
        }
        if (mb_strlen($values['sourceContext']) > 2000) {
            $errors['sourceContext'] = 'Source context must be 2000 characters or fewer.';
        }
        if (mb_strlen($values['explanation']) < 40 || mb_strlen($values['explanation']) > 10000) {
            $errors['explanation'] = 'Explain the allegation and your authority in at least 40 characters.';
        }
        if (mb_strlen($values['reporterOrganization']) > 200) {
            $errors['reporterOrganization'] = 'Organization must be 200 characters or fewer.';
        }
        if (mb_strlen($values['reporterRole']) > 200) {
            $errors['reporterRole'] = 'Role must be 200 characters or fewer.';
        }
        if (!$goodFaith) {
            $errors['goodFaithAcknowledged'] = 'Confirm that the report is accurate to the best of your knowledge and submitted in good faith.';
        }

        return $errors;
    }


    private function hasUnsafeScheme(string $reference): bool
    {
        if (!preg_match('/^([a-z][a-z0-9+.-]*):/i', $reference, $matches)) {
            return false;
        }

        return !in_array(mb_strtolower($matches[1]), ['http', 'https'], true);
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
