<?php

namespace App\Service;

use App\Entity\ContentReport;
use Doctrine\ORM\EntityManagerInterface;

final class ContentReportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly SecurityAlertService $alerts,
        private readonly ContentReportNotifier $notifier,
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
            'reportedReference' => $this->stringValue($data, 'reportedReference'),
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
            ->setReporterRole($values['reporterRole'] ?: null);

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::CONTENT_REPORT_RECEIVED, [
            'target_type' => 'content_report',
            'target_id' => $report->getId(),
            'report_id' => $report->getId(),
            'category' => $report->getCategory(),
        ]);
        try {
            $this->alerts->alert(
                SecurityAuditLogger::CONTENT_REPORT_RECEIVED,
                SecurityAlertService::SEVERITY_HIGH,
                [
                    'report_id' => $report->getId(),
                    'category' => $report->getCategory(),
                    'review_path' => '/admin?tab=content-reports',
                ],
                'content-report'
            );
        } catch (\Throwable) {
            // The durable case already exists. A cache or mail failure must not
            // turn that success into a retry that creates a duplicate report.
            $this->auditLogger->security(SecurityAuditLogger::DATA_INTEGRITY_FAILURE, [
                'target_type' => 'content_report',
                'target_id' => $report->getId(),
                'report_id' => $report->getId(),
                'operation' => 'content_report_admin_notification',
                'reason' => 'administrator notification failed',
            ], result: SecurityAuditLogger::RESULT_FAILED);
        }
        $this->notifier->acknowledge($report);

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
        if (mb_strlen($values['reportedReference']) < 3 || mb_strlen($values['reportedReference']) > 2000) {
            $errors['reportedReference'] = 'Provide enough information to identify the reported material.';
        } elseif ($this->hasUnsafeScheme($values['reportedReference'])) {
            $errors['reportedReference'] = 'URL references must use http or https.';
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
