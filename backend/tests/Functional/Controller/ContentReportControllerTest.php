<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\ShareClaimCode;
use App\Entity\ShareInvitationToken;
use App\Enum\ShareCodeType;
use App\Service\SecurityAuditLogger;
use App\Service\SharingCodeFormat;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use App\Tests\Support\SwitchableMailer;
use Doctrine\ORM\EntityManagerInterface;

final class ContentReportControllerTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    private const APP_URL = 'http://localhost:8080';

    public function testAnonymousVisitorCanSubmitAnActionableReport(): void
    {
        $payload = $this->postJson('/api/content-reports', [
            'reporterName' => 'Example Rights Holder',
            'reporterOrganization' => 'Example Publishing',
            'reporterRole' => 'Authorized representative',
            'reporterEmail' => 'rights@example.com',
            'category' => ContentReport::CATEGORY_COPYRIGHT,
            'reportedReference' => 'https://panel.example/share/invitation/example-reference',
            'explanation' => 'I represent the publisher and this specific shared edition reproduces our protected work without authorization.',
            'goodFaithAcknowledged' => true,
            'website' => '',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Your report has been received and will be reviewed.', $payload['message']);
        self::assertMatchesRegularExpression('/^CR-\d{8}-\d+$/', $payload['reference']);

        $report = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ContentReport::class)
            ->findOneBy(['reporterEmail' => 'rights@example.com']);

        self::assertInstanceOf(ContentReport::class, $report);
        self::assertSame(ContentReport::STATUS_RECEIVED, $report->getStatus());
        self::assertSame(ContentReport::REFERENCE_OTHER, $report->getReferenceType());
    }

    /** @dataProvider structuredLocatorProvider */
    public function testAnonymousReporterCanSubmitEveryStructuredLocator(string $type, string $reference, array $extra = []): void
    {
        $payload = $this->postJson('/api/content-reports', array_replace($this->validReport(), [
            'reporterEmail' => $type.'@example.com',
            'referenceType' => $type,
            'reportedReference' => $reference,
        ], $extra));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Your report has been received and will be reviewed.', $payload['message']);
    }

    public function structuredLocatorProvider(): iterable
    {
        yield 'invitation URL' => [ContentReport::REFERENCE_INVITATION_URL, self::APP_URL.'/share/invitation/ABC123'];
        yield 'content code' => [ContentReport::REFERENCE_SHARING_CODE, 'C-1234-5678-9ABC'];
        yield 'user code' => [ContentReport::REFERENCE_USER_CODE, 'U-1234-5678-9ABC'];
        yield 'account' => [ContentReport::REFERENCE_ACCOUNT, 'known-account'];
        yield 'comic metadata' => [ContentReport::REFERENCE_COMIC, 'Issue 17, Example Publishing', ['reportedContentTitle' => 'The Example #17']];
        yield 'panel URL' => [ContentReport::REFERENCE_PANEL_URL, self::APP_URL.'/read/123'];
        yield 'other' => [ContentReport::REFERENCE_OTHER, 'External evidence reference EX-153'];
    }

    /** @dataProvider malformedStructuredLocatorProvider */
    public function testMalformedStructuredLocatorsAreRejected(string $type, string $reference): void
    {
        $this->postJson('/api/content-reports', array_replace($this->validReport(), [
            'referenceType' => $type,
            'reportedReference' => $reference,
        ]));

        self::assertResponseStatusCodeSame(400);
    }

    public function malformedStructuredLocatorProvider(): iterable
    {
        yield 'unsafe invitation scheme' => [ContentReport::REFERENCE_INVITATION_URL, 'file:///share/invitation/ABC'];
        yield 'wrong invitation path' => [ContentReport::REFERENCE_INVITATION_URL, 'https://panel.example/admin/ABC'];
        yield 'credentials in URL' => [ContentReport::REFERENCE_PANEL_URL, 'https://user:pass@panel.example/read/1'];
        yield 'foreign invitation origin' => [ContentReport::REFERENCE_INVITATION_URL, 'https://foreign.example/share/invitation/ABC'];
        yield 'foreign panel origin' => [ContentReport::REFERENCE_PANEL_URL, 'https://foreign.example/read/1'];
        yield 'invalid content code' => [ContentReport::REFERENCE_SHARING_CODE, 'C-not-a-code'];
        yield 'wrong code type' => [ContentReport::REFERENCE_USER_CODE, 'G-1234-5678-9ABC'];
    }

    public function testInvitationResolvesPrivatelyButPublicResponsesStayGeneric(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $share = new ComicShare($comic, $owner, 'recipient@example.com');
        [$plaintext, $hash] = ShareInvitationToken::generate();
        $token = new ShareInvitationToken($share, $hash, new \DateTimeImmutable('+7 days'));
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($share);
        $entityManager->persist($token);
        $entityManager->flush();
        $this->clearSecurityLog();

        $resolved = $this->postJson('/api/content-reports', array_replace($this->validReport(), [
            'referenceType' => ContentReport::REFERENCE_INVITATION_URL,
            'reportedReference' => self::APP_URL.'/share/invitation/'.$plaintext,
        ]));
        $unresolved = $this->postJson('/api/content-reports', array_replace($this->validReport(), [
            'reporterEmail' => 'second@example.com',
            'referenceType' => ContentReport::REFERENCE_INVITATION_URL,
            'reportedReference' => self::APP_URL.'/share/invitation/'.str_repeat('0', 64),
        ]));

        self::assertSame($resolved['message'], $unresolved['message']);
        $report = $entityManager->getRepository(ContentReport::class)->findOneBy(['reporterEmail' => 'rights@example.com']);
        self::assertSame($share->getId(), $report->getLinkedShare()?->getId());
        self::assertSame($comic->getId(), $report->getLinkedComic()?->getId());
        self::assertSame($owner->getId(), $report->getLinkedUser()?->getId());
        self::assertSame($comic->getId(), $report->getLinkedComicIdSnapshot());
        self::assertSame('invitation_url', $report->getResolutionMethod());
        $audit = $this->assertLoggedAuditEvent(SecurityAuditLogger::CONTENT_REPORT_TARGET_LINKED);
        self::assertNull($audit->context['previous_linked_user_id']);
        self::assertSame($owner->getId(), $audit->context['linked_user_id']);
        self::assertNull($audit->context['previous_linked_comic_id']);
        self::assertSame($comic->getId(), $audit->context['linked_comic_id']);
        self::assertNull($audit->context['previous_linked_share_id']);
        self::assertSame($share->getId(), $audit->context['linked_share_id']);
        self::assertSame('share', $audit->context['resolved_target_type']);
        self::assertSame($share->getId(), $audit->context['resolved_target_id']);
        self::assertSame('invitation_url', $audit->context['resolution_method']);
    }

    public function testExactContentAndUserCodesResolveWhileGroupCodeOnlyOffersCandidates(): void
    {
        $owner = UserFactory::createOne()->object();
        $owner->assignUserCode('123456789ABC');
        $first = ComicFactory::createOne(['owner' => $owner, 'title' => 'First candidate'])->object();
        $second = ComicFactory::createOne(['owner' => $owner, 'title' => 'Second candidate'])->object();
        $comicToken = 'ABCDEF123456';
        $groupToken = '987654321ABC';
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ShareClaimCode($owner, ShareCodeType::COMIC, SharingCodeFormat::hash(ShareCodeType::COMIC, $comicToken), [$first], 1, new \DateTimeImmutable('+7 days')));
        $entityManager->persist(new ShareClaimCode($owner, ShareCodeType::GROUP, SharingCodeFormat::hash(ShareCodeType::GROUP, $groupToken), [$first, $second], 1, new \DateTimeImmutable('+7 days')));
        $entityManager->flush();

        $this->postJson('/api/content-reports', array_replace($this->validReport(), [
            'reporterEmail' => 'comic-code@example.com',
            'referenceType' => ContentReport::REFERENCE_SHARING_CODE,
            'reportedReference' => SharingCodeFormat::forDisplay(ShareCodeType::COMIC, $comicToken),
        ]));
        $this->postJson('/api/content-reports', array_replace($this->validReport(), [
            'reporterEmail' => 'user-code@example.com',
            'referenceType' => ContentReport::REFERENCE_USER_CODE,
            'reportedReference' => SharingCodeFormat::forDisplay(ShareCodeType::USER, $owner->getUserCode()),
        ]));
        $this->postJson('/api/content-reports', array_replace($this->validReport(), [
            'reporterEmail' => 'group-code@example.com',
            'referenceType' => ContentReport::REFERENCE_SHARING_CODE,
            'reportedReference' => SharingCodeFormat::forDisplay(ShareCodeType::GROUP, $groupToken),
        ]));

        $repository = $entityManager->getRepository(ContentReport::class);
        self::assertSame($first->getId(), $repository->findOneBy(['reporterEmail' => 'comic-code@example.com'])->getLinkedComic()?->getId());
        self::assertSame($owner->getId(), $repository->findOneBy(['reporterEmail' => 'user-code@example.com'])->getLinkedUser()?->getId());
        $groupReport = $repository->findOneBy(['reporterEmail' => 'group-code@example.com']);
        self::assertNull($groupReport->getLinkedComic());

        $this->createAndLoginAdmin();
        $detail = $this->getJson('/api/admin/content-reports/'.$groupReport->getId());
        self::assertSame('candidates', $detail['report']['targetResolution']['status']);
        self::assertCount(2, $detail['report']['targetResolution']['candidates']);
    }

    public function testAcknowledgementAndDedicatedOperatorEmailContainTheRightCaseDetail(): void
    {
        $token = str_repeat('A', 64);
        $explanation = 'This exact invitation contains a protected edition and this sentence is the detailed allegation.';
        $accountReference = 'account-secret-'.str_repeat('B', 24);
        $sourceContext = 'Invitation https://panel.example/share/invitation/'.str_repeat('C', 64);
        $this->postJson('/api/content-reports', array_replace($this->validReport(), [
            'referenceType' => ContentReport::REFERENCE_INVITATION_URL,
            'reportedReference' => self::APP_URL.'/share/invitation/'.$token,
            'reportedAccountReference' => $accountReference,
            'sourceContext' => $sourceContext,
            'explanation' => $explanation,
        ]));

        self::assertEmailCount(2);
        $messages = self::getMailerMessages();
        $operator = current(array_filter($messages, static fn ($message): bool => $message->getTo()[0]->getAddress() === 'legal@example.test')) ?: null;
        $receipt = current(array_filter($messages, static fn ($message): bool => $message->getTo()[0]->getAddress() === 'rights@example.com')) ?: null;
        self::assertNotNull($operator);
        self::assertNotNull($receipt);
        self::assertSame('legal@example.test', $operator->getTo()[0]->getAddress());
        self::assertStringContainsString($token, (string) $operator->getTextBody());
        self::assertStringContainsString($accountReference, (string) $operator->getTextBody());
        self::assertStringContainsString($sourceContext, (string) $operator->getTextBody());
        self::assertStringContainsString($explanation, (string) $operator->getTextBody());
        self::assertSame('rights@example.com', $receipt->getTo()[0]->getAddress());
        self::assertStringContainsString('[masked-token]', (string) $receipt->getTextBody());
        self::assertStringNotContainsString($token, (string) $receipt->getTextBody());
        self::assertStringNotContainsString($accountReference, (string) $receipt->getTextBody());
        self::assertStringNotContainsString($sourceContext, (string) $receipt->getTextBody());
        self::assertStringNotContainsString($explanation, (string) $receipt->getTextBody());
    }

    public function testMailFailureDoesNotRollBackTheDurableReport(): void
    {
        SwitchableMailer::failEverything();
        $this->postJson('/api/content-reports', $this->validReport());

        self::assertResponseStatusCodeSame(201);
        self::assertNotNull(static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ContentReport::class)->findOneBy(['reporterEmail' => 'rights@example.com']));
    }

    /**
     * @dataProvider invalidReportProvider
     */
    public function testInvalidReportsAreRejected(array $override): void
    {
        $payload = $this->postJson('/api/content-reports', array_replace([
            'reporterName' => 'Example Rights Holder',
            'reporterEmail' => 'rights@example.com',
            'category' => ContentReport::CATEGORY_OTHER_ILLEGAL,
            'reportedReference' => 'A share link supplied in correspondence reference 1234',
            'explanation' => 'This report identifies the material and explains why it is alleged to be unlawful in enough detail for review.',
            'goodFaithAcknowledged' => true,
            'website' => '',
        ], $override));

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('errors', $payload);
    }

    public function invalidReportProvider(): iterable
    {
        yield 'invalid email' => [['reporterEmail' => 'not-an-email']];
        yield 'missing category' => [['category' => '']];
        yield 'unsafe URL scheme' => [['reportedReference' => 'javascript:alert(1)']];
        yield 'insubstantial explanation' => [['explanation' => 'Illegal.']];
        yield 'no good-faith acknowledgement' => [['goodFaithAcknowledged' => false]];
        yield 'non-string reporter name' => [['reporterName' => ['unexpected']]];
    }

    public function testHoneypotSubmissionGetsGenericResponseWithoutCreatingARecord(): void
    {
        $payload = $this->postJson('/api/content-reports', [
            'reporterName' => 'Spam Bot',
            'reporterEmail' => 'spam@example.com',
            'category' => ContentReport::CATEGORY_OTHER_ILLEGAL,
            'reportedReference' => 'https://example.com/reference',
            'explanation' => str_repeat('spam ', 20),
            'goodFaithAcknowledged' => true,
            'website' => 'https://spam.example',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Your report has been received and will be reviewed.', $payload['message']);
        self::assertNull(static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ContentReport::class)
            ->findOneBy(['reporterEmail' => 'spam@example.com']));
    }

    public function testReportQueueRequiresAnAdministrator(): void
    {
        $this->createAndLoginUser();
        $this->getJson('/api/admin/content-reports');

        self::assertResponseStatusCodeSame(403);
    }

    public function testQueueIsSummaryOnlyAndReviewFetchesSensitiveDetail(): void
    {
        $report = new ContentReport('Reporter', 'private@example.com', ContentReport::CATEGORY_COPYRIGHT, 'private reference', 'This private allegation is sufficiently long to form a real content report for review.');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->flush();
        $this->createAndLoginAdmin();

        $queue = $this->getJson('/api/admin/content-reports');
        $summary = array_values(array_filter($queue['reports'], static fn (array $item): bool => $item['id'] === $report->getId()))[0];
        self::assertArrayNotHasKey('reporterEmail', $summary);
        self::assertArrayNotHasKey('reportedReference', $summary);
        self::assertArrayNotHasKey('explanation', $summary);

        $detail = $this->getJson('/api/admin/content-reports/'.$report->getId());
        self::assertSame('private@example.com', $detail['report']['reporterEmail']);
        self::assertSame('private reference', $detail['report']['reportedReference']);
    }

    public function testContradictoryLegacyTargetsAndStaleUserActionsAreRejected(): void
    {
        $firstOwner = UserFactory::createOne()->object();
        $secondOwner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $secondOwner])->object();
        $report = new ContentReport('Reporter', 'reporter@example.com', ContentReport::CATEGORY_COPYRIGHT, 'Reference 153', 'This allegation has enough information to support a review of the selected canonical target.');
        $report->linkUser($firstOwner)->linkComic($comic);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->flush();
        $this->createAndLoginAdmin();

        $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'status' => ContentReport::STATUS_ACTION_TAKEN,
            'action' => 'restrict_user_sharing',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertFalse($firstOwner->isSharingRestricted());

        $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'linkedComicId' => $comic->getId(),
            'linkedUserId' => $firstOwner->getId(),
            'action' => 'none',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testCanonicalSelectionAuditsChangesAndSnapshotSurvivesComicDeletion(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'title' => 'Durable title'])->object();
        $report = new ContentReport('Reporter', 'reporter@example.com', ContentReport::CATEGORY_COPYRIGHT, 'Reference 153', 'This allegation has enough information to support a review and durable target correlation.');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->flush();
        $this->createAndLoginAdmin();
        $this->clearSecurityLog();

        $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'targetType' => 'comic',
            'targetId' => $comic->getId(),
            'status' => ContentReport::STATUS_UNDER_REVIEW,
            'action' => 'none',
        ]);
        self::assertResponseIsSuccessful();
        $audit = $this->assertLoggedAuditEvent(SecurityAuditLogger::CONTENT_REPORT_TARGET_LINKED);
        self::assertSame($comic->getId(), $audit->context['linked_comic_id']);

        $comicId = $comic->getId();
        $entityManager->remove($entityManager->find(\App\Entity\Comic::class, $comicId));
        $entityManager->flush();
        $entityManager->clear();
        $stored = $entityManager->find(ContentReport::class, $report->getId());
        self::assertNull($stored->getLinkedComic());
        self::assertSame($comicId, $stored->getLinkedComicIdSnapshot());
        self::assertSame('Durable title', $stored->getLinkedComicTitleSnapshot());
    }

    /**
     * A report is linked automatically at submission from the reference the
     * reporter typed, so a wrong target is ordinary. An admin who can swap one
     * wrong record for another but never clear it is stuck asserting that some
     * comic is the subject of a legal notice.
     */
    public function testAdminCanClearALinkedTarget(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $report = new ContentReport('Reporter', 'reporter@example.com', ContentReport::CATEGORY_COPYRIGHT, 'Reference 161', 'This allegation has enough information to support a review and a target that turns out to be wrong.');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->flush();
        $this->createAndLoginAdmin();

        $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'targetType' => 'comic',
            'targetId' => $comic->getId(),
            'action' => 'none',
        ]);
        self::assertResponseIsSuccessful();

        $payload = $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'targetType' => null,
            'targetId' => null,
            'action' => 'none',
        ]);
        self::assertResponseIsSuccessful();
        self::assertNull($payload['report']['linkedComic']);
        self::assertNull($payload['report']['linkedUser']);
        self::assertNull($payload['report']['linkedShare']);
        // The snapshot goes too, or the queue still names the cleared record.
        self::assertNull($payload['report']['targetSnapshot']['comicId']);
        self::assertNull($payload['report']['targetSnapshot']['comicTitle']);

        $entityManager->clear();
        $stored = $entityManager->find(ContentReport::class, $report->getId());
        self::assertNull($stored->getLinkedComic());
        self::assertNull($stored->getLinkedComicIdSnapshot());
    }

    /** The legacy spelling of the same thing: every named id explicitly null. */
    public function testAdminCanClearALinkedTargetWithLegacyKeys(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $report = new ContentReport('Reporter', 'reporter@example.com', ContentReport::CATEGORY_COPYRIGHT, 'Reference 162', 'This allegation has enough information to support a review and a target that turns out to be wrong.');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->flush();
        $this->createAndLoginAdmin();

        $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'linkedComicId' => $comic->getId(),
            'action' => 'none',
        ]);
        self::assertResponseIsSuccessful();

        $payload = $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'linkedComicId' => null,
            'action' => 'none',
        ]);
        self::assertResponseIsSuccessful();
        self::assertNull($payload['report']['linkedComic']);
        self::assertNull($payload['report']['targetSnapshot']['comicId']);
    }

    public function testAdminCanReviewLinkAndRestrictAReportedComic(): void
    {
        $owner = UserFactory::createOne()->object();
        $recipient = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $report = new ContentReport(
            'Reporter',
            'reporter@example.com',
            ContentReport::CATEGORY_COPYRIGHT,
            'https://panel.example/reference',
            'This is a sufficiently detailed allegation that identifies the work and the asserted rights involved.'
        );
        $share = new ComicShare($comic, $owner, (string) $recipient->getEmail());
        $share->markPending(new \DateTimeImmutable('+1 day'))->markAccepted($recipient);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->persist($share);
        $entityManager->flush();

        $this->createAndLoginAdmin();
        $payload = $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'status' => ContentReport::STATUS_ACTION_TAKEN,
            'linkedComicId' => $comic->getId(),
            'resolutionCode' => 'sharing_restricted',
            'resolutionNote' => 'Sharing disabled while the rights claim is assessed.',
            'action' => 'restrict_sharing',
            'notifyOwner' => false,
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['report']['linkedComic']['sharingRestricted']);

        $this->loginAs($recipient);
        $this->getJson('/api/comics/'.$comic->getId());
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($owner);
        $this->getJson('/api/comics/'.$comic->getId());
        self::assertResponseIsSuccessful();

        // A restricted comic is not shareable, so the bulk endpoint refuses the
        // whole request and creates nothing.
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'another@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanLiftARestrictionWithoutRecreatingShares(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $comic->restrictSharing();
        $report = new ContentReport(
            'Reporter',
            'reporter@example.com',
            ContentReport::CATEGORY_COPYRIGHT,
            'Reference 1234',
            'This is a sufficiently detailed allegation that identifies the work and the asserted rights involved.'
        );
        $report->linkComic($comic);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->flush();

        $this->createAndLoginAdmin();
        $payload = $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'status' => ContentReport::STATUS_CLOSED,
            'action' => 'lift_sharing_restriction',
            'resolutionCode' => 'restriction_lifted',
            'resolutionNote' => 'The claim was resolved and sharing may resume.',
            'notifyOwner' => false,
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($payload['report']['linkedComic']['sharingRestricted']);
    }

    public function testQuarantineBlocksTheOwnerButPreservesAdministratorAccess(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $report = new ContentReport(
            'Reporter',
            'reporter@example.com',
            ContentReport::CATEGORY_OTHER_ILLEGAL,
            'Reference 5678',
            'This report identifies specific allegedly illegal material with enough detail for an administrator to assess it.'
        );

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->flush();

        $admin = $this->createAndLoginAdmin();
        $payload = $this->patchJson('/api/admin/content-reports/'.$report->getId(), [
            'status' => ContentReport::STATUS_ACTION_TAKEN,
            'linkedComicId' => $comic->getId(),
            'action' => 'quarantine_content',
            'notifyOwner' => false,
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['report']['linkedComic']['quarantined']);

        $this->loginAs($owner);
        $this->getJson('/api/comics/'.$comic->getId());
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($admin);
        $this->getJson('/api/comics/'.$comic->getId());
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function validReport(): array
    {
        return [
            'reporterName' => 'Example Rights Holder',
            'reporterOrganization' => 'Example Publishing',
            'reporterRole' => 'Authorized representative',
            'reporterEmail' => 'rights@example.com',
            'category' => ContentReport::CATEGORY_COPYRIGHT,
            'referenceType' => ContentReport::REFERENCE_OTHER,
            'reportedReference' => 'External evidence reference EX-153',
            'reportedContentTitle' => '',
            'reportedAccountReference' => '',
            'sourceContext' => 'The reporter received this reference in correspondence.',
            'explanation' => 'I represent the publisher and this specific shared edition reproduces our protected work without authorization.',
            'goodFaithAcknowledged' => true,
            'website' => '',
        ];
    }
}
