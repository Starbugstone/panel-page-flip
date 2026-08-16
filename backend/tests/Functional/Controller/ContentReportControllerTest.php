<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ContentReportControllerTest extends AbstractApiTestCase
{
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

        // Reported per comic by the bulk endpoint, which judges each one and
        // creates nothing rather than refusing the whole request.
        $blocked = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'another@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertSame(0, $blocked['created']);
        self::assertSame('not_available', $blocked['results'][0]['status']);
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
}
