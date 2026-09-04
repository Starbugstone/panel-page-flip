<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AdminAuditLog;
use App\Entity\Comic;
use App\Entity\ComicFormatConfiguration;
use App\Entity\ComicReadingProgress;
use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\LibraryFolder;
use App\Entity\LibraryFolderItem;
use App\Entity\MetadataProviderConfiguration;
use App\Entity\ShareClaimCode;
use App\Entity\ShareClaimCodeRedemption;
use App\Entity\ShareInvitationToken;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\UserWarning;
use App\Enum\ReadingDirection;
use App\Enum\ReportedReferenceType;
use App\Enum\ShareCodeType;
use App\Metadata\Classification;
use App\Service\AppDataEncryptionService;
use App\Service\SharingCodeFormat;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures
{
    public const PASSWORD = 'DemoPassword123!';

    private const FILE_PREFIX = 'demo-fixture-';
    private const USER_EMAILS = [
        'admin@example.test',
        'alex@example.test',
        'blair@example.test',
        'casey@example.test',
        'drew@example.test',
        'erin@example.test',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AppDataEncryptionService $encryption,
        #[Autowire('%comics_directory%')]
        private readonly string $comicsDirectory,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory,
    ) {
    }

    /** @return bool whether the demo graph was created */
    public function load(ObjectManager $manager): bool
    {
        $existingFixtureAccounts = 0;
        foreach (self::USER_EMAILS as $email) {
            if ($manager->getRepository(User::class)->findOneBy(['email' => $email]) instanceof User) {
                ++$existingFixtureAccounts;
            }
        }

        if ($existingFixtureAccounts === count(self::USER_EMAILS)) {
            return false;
        }
        if ($existingFixtureAccounts !== 0) {
            throw new \RuntimeException(
                'Some demo fixture accounts already exist, but the fixture set is incomplete. '
                .'Remove or rename those accounts before loading the demo data.'
            );
        }

        $this->removeOldDemoFiles();
        if (!$manager->find(ComicFormatConfiguration::class, 1) instanceof ComicFormatConfiguration) {
            $manager->persist(new ComicFormatConfiguration());
        }
        if (!$manager->find(MetadataProviderConfiguration::class, 1) instanceof MetadataProviderConfiguration) {
            $manager->persist(new MetadataProviderConfiguration());
        }

        $users = $this->createUsers($manager);
        $manager->flush();
        $tags = $this->createTags($manager, $users);
        $comics = $this->createComics($manager, $users, $tags, $this->createDemoArchive());
        $manager->flush();

        $folders = $this->createFolders($manager, $users);
        $manager->flush();
        $this->createFolderItems($manager, $users, $comics, $folders);
        $this->createReadingProgress($manager, $users, $comics);
        $shares = $this->createShares($manager, $users, $comics);
        $codes = $this->createClaimCodes($manager, $users, $comics, $shares);
        $manager->flush();
        $this->createModerationRecords($manager, $users, $comics, $shares, $codes);
        $manager->flush();

        return true;
    }

    /** @return array<string, User> */
    private function createUsers(ObjectManager $manager): array
    {
        $definitions = [
            'admin' => ['admin@example.test', 'Site Administrator', 'demo_admin', 'ADM1N2D3M006', ['ROLE_ADMIN', 'ROLE_USER'], true],
            'alex' => ['alex@example.test', 'Alex Morgan', 'alex_reader', 'A1EX2D3M4001', ['ROLE_USER'], true],
            'blair' => ['blair@example.test', 'Blair Chen', 'blair_books', 'B1A1R2D3M002', ['ROLE_USER'], true],
            'casey' => ['casey@example.test', 'Casey Rivera', 'casey_panels', 'CA5EY2D3M003', ['ROLE_USER'], true],
            'drew' => ['drew@example.test', 'Drew Patel', 'drew_ink', 'DREW2D3M0004', ['ROLE_USER'], false],
            'erin' => ['erin@example.test', 'Erin Okafor', 'erin_reads', 'ER1N2D3M0005', ['ROLE_USER'], true],
        ];

        $users = [];
        foreach ($definitions as $key => [$email, $name, $username, $code, $roles, $verified]) {
            $user = (new User())
                ->setEmail($email)
                ->setName($name)
                ->setUsername($username)
                ->assignUserCode($code)
                ->setRoles($roles)
                ->setIsEmailVerified($verified)
                ->setCreatedAt(new \DateTimeImmutable('-8 months'))
                ->setLastLoginAt($verified ? new \DateTimeImmutable('-2 hours') : null);
            $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
            if ($key === 'alex') {
                $user->setReaderPreferences([
                    'schemaVersion' => 1,
                    'settings' => [
                        'mode' => 'double',
                        'direction' => 'ltr',
                        'fit' => 'contain',
                        'autoHideControls' => true,
                        'showProgress' => true,
                        'wakeLock' => true,
                        'coverAlone' => true,
                    ],
                    'overrides' => [],
                    'dismissedSuggestions' => [],
                ]);
            }
            if ($key === 'erin') {
                $user->restrictSharing();
            }
            $manager->persist($user);
            $users[$key] = $user;
        }

        return $users;
    }

    /**
     * @param array<string, User> $users
     * @return array<string, Tag>
     */
    private function createTags(ObjectManager $manager, array $users): array
    {
        $tags = [
            'action' => (new Tag())->setName('Action')->setIsGlobal(true),
            'sci-fi' => (new Tag())->setName('Science fiction')->setIsGlobal(true),
            'manga' => (new Tag())->setName('Manga')->setIsGlobal(true),
            'archived' => (new Tag())->setName('Archived')->setIsGlobal(true)->setHideFromLibrary(true),
            'alex-backlog' => (new Tag())->setName('Weekend backlog')->setCreator($users['alex']),
            'blair-favorite' => (new Tag())->setName('Favourites')->setCreator($users['blair']),
            'casey-club' => (new Tag())->setName('Book club')->setCreator($users['casey']),
            'erin-review' => (new Tag())->setName('Needs review')->setCreator($users['erin']),
        ];
        foreach ($tags as $tag) {
            $manager->persist($tag);
        }

        return $tags;
    }

    /**
     * @param array<string, User> $users
     * @param array<string, Tag> $tags
     * @return array<string, Comic>
     */
    private function createComics(ObjectManager $manager, array $users, array $tags, string $archive): array
    {
        $definitions = [
            'clockwork-1' => ['alex', 'The Clockwork Citadel #1', 'Clockwork Citadel', '1', 'Mara Vale', 'Copper Finch Press', ['action', 'sci-fi']],
            'clockwork-2' => ['alex', 'The Clockwork Citadel #2', 'Clockwork Citadel', '2', 'Mara Vale', 'Copper Finch Press', ['action', 'sci-fi']],
            'harbor-lights' => ['alex', 'Harbor Lights', 'Harbor Lights', '1', 'Jon Bell', 'Northline Comics', ['alex-backlog']],
            'silent-orbit' => ['alex', 'Silent Orbit', 'Silent Orbit', '3', 'Nia Stone', 'Red Comet', ['sci-fi']],
            'paper-dragons' => ['alex', 'Paper Dragons', 'Paper Dragons', 'Annual 1', 'Akira Moss', 'Folded Moon', ['action', 'alex-backlog']],
            'forgotten-sketches' => ['alex', 'Forgotten Sketches', 'Studio Notes', '0', 'Alex Morgan', 'Self-published', ['archived']],
            'midnight-1' => ['blair', 'Midnight Express #1', 'Midnight Express', '1', 'Sofia Grant', 'Night Train', ['action', 'blair-favorite']],
            'midnight-2' => ['blair', 'Midnight Express #2', 'Midnight Express', '2', 'Sofia Grant', 'Night Train', ['action']],
            'midnight-3' => ['blair', 'Midnight Express #3', 'Midnight Express', '3', 'Sofia Grant', 'Night Train', ['action']],
            'garden-between' => ['blair', 'The Garden Between Worlds', 'Garden Between Worlds', '1', 'Emil Novak', 'Softcover House', ['sci-fi', 'blair-favorite']],
            'moonblade-1' => ['casey', 'Moonblade #1', 'Moonblade', '1', 'Rei Tanaka', 'Kitsune Works', ['manga', 'casey-club']],
            'moonblade-2' => ['casey', 'Moonblade #2', 'Moonblade', '2', 'Rei Tanaka', 'Kitsune Works', ['manga', 'casey-club']],
            'tin-soldiers' => ['casey', 'Tin Soldiers', 'Tin Soldiers', '4', 'Inez Ward', 'Copper Finch Press', ['action']],
            'winter-archive' => ['casey', 'The Winter Archive', 'Winter Archive', '1', 'Owen Pike', 'Northline Comics', ['casey-club']],
            'signal-zero' => ['drew', 'Signal Zero', 'Signal Zero', '1', 'Drew Patel', 'Signal House', ['sci-fi']],
            'northstar' => ['drew', 'Northstar Dispatch', 'Northstar Dispatch', '5', 'Lena Park', 'Signal House', ['action']],
            'after-dark' => ['erin', 'After Dark', 'After Dark', '1', 'T. K. James', 'Late Edition', ['erin-review']],
            'restricted-frequency' => ['erin', 'Restricted Frequency', 'Restricted Frequency', '2', 'Erin Okafor', 'Late Edition', ['sci-fi', 'erin-review']],
        ];

        $comics = [];
        foreach ($definitions as $key => [$ownerKey, $title, $series, $issue, $author, $publisher, $tagKeys]) {
            $filename = self::FILE_PREFIX.$key.'.cbz';
            $comic = (new Comic())
                ->setOwner($users[$ownerKey])
                ->setTitle($title)
                ->setSeries($series)
                ->setIssueNumber($issue)
                ->setIssueCount(6)
                ->setVolume(1)
                ->setAuthor($author)
                ->setPublisher($publisher)
                ->setDescription(sprintf('A fixture comic from %s, with realistic metadata and one shared demo page.', $publisher))
                ->setOriginalFilename($title.'.cbz')
                ->setFilePath($filename)
                ->setFileSize($this->installComicArchive($users[$ownerKey], $filename, $archive))
                ->setPageCount(1)
                ->setPublishedAt(new \DateTimeImmutable(sprintf('-%d months', (count($comics) % 12) + 1)))
                ->setLanguageCode($ownerKey === 'casey' && str_starts_with($key, 'moonblade') ? 'ja' : 'en')
                ->setAgeRating($key === 'after-dark' ? '18+' : 'Teen')
                ->setReadingDirection($ownerKey === 'casey' && str_starts_with($key, 'moonblade')
                    ? ReadingDirection::RightToLeft
                    : ReadingDirection::LeftToRight)
                ->setCreators(['writer' => [$author], 'artist' => ['Sam Demo']])
                ->setPageMetadata([['page' => 1, 'type' => 'FrontCover', 'width' => 1024, 'height' => 1024]])
                ->setClassification(new Classification(
                    genres: in_array('sci-fi', $tagKeys, true) ? ['Science fiction'] : ['Adventure'],
                    characters: ['The Demo Reader'],
                    locations: ['Sample City'],
                ));
            if ($key === 'after-dark') {
                $comic->setExplicitContent(true);
            }
            if ($key === 'restricted-frequency') {
                $comic->quarantine();
            }
            if ($key === 'garden-between') {
                $comic->setDropboxPath('/Demo Library/The Garden Between Worlds.cbz');
            }
            if ($key === 'clockwork-1') {
                $comic->setMetadataOrigin('comic_vine', 'demo-clockwork-1', new \DateTimeImmutable('-3 days'));
            }
            foreach ($tagKeys as $tagKey) {
                $comic->addTag($tags[$tagKey]);
            }
            $manager->persist($comic);
            $comics[$key] = $comic;
        }

        return $comics;
    }

    /**
     * @param array<string, User> $users
     * @return array<string, LibraryFolder>
     */
    private function createFolders(ObjectManager $manager, array $users): array
    {
        $folders = [
            'alex-series' => $this->folder($manager, $users['alex'], 'Series'),
            'alex-shared' => $this->folder($manager, $users['alex'], 'Shared with me'),
            'blair-favorites' => $this->folder($manager, $users['blair'], 'Favourites'),
            'blair-lend' => $this->folder($manager, $users['blair'], 'Lend next'),
            'casey-manga' => $this->folder($manager, $users['casey'], 'Manga'),
            'casey-club' => $this->folder($manager, $users['casey'], 'Book club'),
            'erin-review' => $this->folder($manager, $users['erin'], 'Under review'),
        ];
        $manager->flush();
        $folders['alex-space'] = $this->folder($manager, $users['alex'], 'Space opera', $folders['alex-series']);

        return $folders;
    }

    private function folder(ObjectManager $manager, User $owner, string $name, ?LibraryFolder $parent = null): LibraryFolder
    {
        $folder = (new LibraryFolder())->setOwner($owner)->setName($name)->setParent($parent);
        $manager->persist($folder);

        return $folder;
    }

    /**
     * @param array<string, User> $users
     * @param array<string, Comic> $comics
     * @param array<string, LibraryFolder> $folders
     */
    private function createFolderItems(ObjectManager $manager, array $users, array $comics, array $folders): void
    {
        $placements = [
            ['alex', 'clockwork-1', 'alex-space'], ['alex', 'clockwork-2', 'alex-space'],
            ['alex', 'silent-orbit', 'alex-space'], ['alex', 'midnight-1', 'alex-shared'],
            ['blair', 'garden-between', 'blair-favorites'], ['blair', 'clockwork-1', 'blair-lend'],
            ['casey', 'moonblade-1', 'casey-manga'], ['casey', 'moonblade-2', 'casey-manga'],
            ['casey', 'midnight-2', 'casey-club'], ['erin', 'restricted-frequency', 'erin-review'],
        ];
        foreach ($placements as [$user, $comic, $folder]) {
            $manager->persist((new LibraryFolderItem())
                ->setUser($users[$user])->setComic($comics[$comic])->setFolder($folders[$folder]));
        }
    }

    /**
     * @param array<string, User> $users
     * @param array<string, Comic> $comics
     */
    private function createReadingProgress(ObjectManager $manager, array $users, array $comics): void
    {
        $rows = [
            ['alex', 'clockwork-1', true, '-1 hour'], ['alex', 'clockwork-2', false, '-1 day'],
            ['alex', 'harbor-lights', true, '-3 days'], ['alex', 'midnight-1', true, '-4 days'],
            ['blair', 'midnight-1', true, '-2 hours'], ['blair', 'garden-between', false, '-5 days'],
            ['blair', 'clockwork-1', true, '-8 days'], ['casey', 'moonblade-1', false, '-6 hours'],
        ];
        foreach ($rows as $revision => [$user, $comic, $completed, $lastRead]) {
            $manager->persist((new ComicReadingProgress())
                ->setUser($users[$user])->setComic($comics[$comic])->setCurrentPage(1)
                ->setCompleted($completed)->setLastReadAt(new \DateTimeImmutable($lastRead))->setRevision($revision + 1));
        }
    }

    /**
     * @param array<string, User> $users
     * @param array<string, Comic> $comics
     * @return array<string, ComicShare>
     */
    private function createShares(ObjectManager $manager, array $users, array $comics): array
    {
        $shares = [];
        $shares['blair-to-alex'] = $this->acceptedShare($manager, $comics['midnight-1'], $users['blair'], $users['alex']);
        $shares['casey-to-alex-removed'] = $this->acceptedShare($manager, $comics['tin-soldiers'], $users['casey'], $users['alex'])->markRecipientRemoved();

        $batchId = 'DEMOFOLDERBATCH0000000000000001';
        $shares['casey-batch-1'] = $this->pendingShare($manager, $comics['moonblade-1'], $users['casey'], $users['alex'])->joinInvitationBatch($batchId, 'Moonblade', 2);
        $shares['casey-batch-2'] = $this->pendingShare($manager, $comics['moonblade-2'], $users['casey'], $users['alex'])->joinInvitationBatch($batchId, 'Moonblade', 2);
        $this->addInvitationToken($manager, $shares['casey-batch-1'], 'casey-moonblade-batch');

        $shares['alex-to-blair'] = $this->acceptedShare($manager, $comics['clockwork-1'], $users['alex'], $users['blair']);
        $shares['alex-to-casey'] = $this->pendingShare($manager, $comics['harbor-lights'], $users['alex'], $users['casey'])->markNotificationFailed();
        $this->addInvitationToken($manager, $shares['alex-to-casey'], 'alex-harbor-casey');

        $shares['blair-to-external'] = (new ComicShare($comics['garden-between'], $users['blair'], 'future.reader@example.test'))
            ->acceptSenderResponsibility()->markPending(new \DateTimeImmutable('+7 days'))->awaitNotification();
        $manager->persist($shares['blair-to-external']);
        $this->addInvitationToken($manager, $shares['blair-to-external'], 'blair-external-reader');

        $shares['casey-declined'] = (new ComicShare($comics['winter-archive'], $users['casey'], $users['blair']->getEmail()))
            ->acceptSenderResponsibility()->markDeclined($users['blair'])->markNotified();
        $manager->persist($shares['casey-declined']);
        $shares['alex-revoked'] = (new ComicShare($comics['silent-orbit'], $users['alex'], $users['drew']->getEmail()))
            ->acceptSenderResponsibility()->linkRecipientUser($users['drew'])->markRevoked()->markNotified();
        $manager->persist($shares['alex-revoked']);
        $shares['erin-explicit'] = $this->acceptedShare($manager, $comics['after-dark'], $users['erin'], $users['alex']);

        return $shares;
    }

    private function acceptedShare(ObjectManager $manager, Comic $comic, User $owner, User $recipient): ComicShare
    {
        $share = (new ComicShare($comic, $owner, $recipient->getEmail()))
            ->acceptSenderResponsibility()->markAccepted($recipient)->markNotified();
        $manager->persist($share);

        return $share;
    }

    private function pendingShare(ObjectManager $manager, Comic $comic, User $owner, User $recipient): ComicShare
    {
        $share = (new ComicShare($comic, $owner, $recipient->getEmail()))
            ->acceptSenderResponsibility()->linkRecipientUser($recipient)
            ->hideRecipientBehindSharingCode($recipient->getUserCode(), $recipient->getName())
            ->markPending(new \DateTimeImmutable('+7 days'))->markNotified();
        $manager->persist($share);

        return $share;
    }

    private function addInvitationToken(ObjectManager $manager, ComicShare $share, string $seed): void
    {
        $manager->persist(new ShareInvitationToken(
            $share,
            ShareInvitationToken::hash('demo-fixture-'.$seed),
            new \DateTimeImmutable('+7 days'),
        ));
    }

    /**
     * @param array<string, User> $users
     * @param array<string, Comic> $comics
     * @param array<string, ComicShare> $shares
     * @return array<string, ShareClaimCode>
     */
    private function createClaimCodes(ObjectManager $manager, array $users, array $comics, array &$shares): array
    {
        $single = $this->claimCode($users['alex'], ShareCodeType::COMIC, 'C0M1CDEMA001', [$comics['paper-dragons']], 3, '+7 days');
        $manager->persist($single);
        $group = $this->claimCode($users['blair'], ShareCodeType::GROUP, 'GR0VPACK0001', [$comics['midnight-2'], $comics['midnight-3']], 4, '+5 days');
        $group->spendUse();
        $manager->persist($group);
        $manager->persist(new ShareClaimCodeRedemption($group, $users['casey']));
        foreach (['midnight-2', 'midnight-3'] as $comicKey) {
            $share = (new ComicShare($comics[$comicKey], $users['blair'], $users['casey']->getEmail()))
                ->hideRecipientBehindSharingCode($users['casey']->getUserCode(), $users['casey']->getName())
                ->inheritSenderResponsibility($group->getSenderResponsibilityAcceptedAt())
                ->markAccepted($users['casey']);
            $manager->persist($share);
            $shares['group-'.$comicKey] = $share;
        }
        $revoked = $this->claimCode($users['casey'], ShareCodeType::COMIC, 'W1THDRAW0003', [$comics['tin-soldiers']], 1, '+2 days')->revoke();
        $manager->persist($revoked);

        return ['single' => $single, 'group' => $group, 'revoked' => $revoked];
    }

    /** @param list<Comic> $comics */
    private function claimCode(User $owner, ShareCodeType $type, string $token, array $comics, int $uses, string $expiry): ShareClaimCode
    {
        return new ShareClaimCode(
            $owner,
            $type,
            SharingCodeFormat::hash($type, $token),
            $comics,
            $uses,
            new \DateTimeImmutable($expiry),
            $this->encryption->encrypt(SharingCodeFormat::forDisplay($type, $token)),
        );
    }

    /**
     * @param array<string, User> $users
     * @param array<string, Comic> $comics
     * @param array<string, ComicShare> $shares
     * @param array<string, ShareClaimCode> $codes
     */
    private function createModerationRecords(ObjectManager $manager, array $users, array $comics, array $shares, array $codes): void
    {
        $received = (new ContentReport('Jamie Reporter', 'reporter.one@example.test', ContentReport::CATEGORY_COPYRIGHT, 'After Dark by T. K. James', 'This fixture report is open so the administrator queue has an unresolved copyright example.'))
            ->identifyTarget(ReportedReferenceType::Comic->value, 'After Dark', '@erin_reads', '/read/demo')
            ->linkUser($users['erin'])->linkComic($comics['after-dark'])->setLegalHold(true);
        $manager->persist($received);
        $underReview = (new ContentReport('Robin Example', 'reporter.two@example.test', ContentReport::CATEGORY_OTHER_ILLEGAL, 'C-C0M1-CDEM-A001', 'This fixture report demonstrates an active review connected to a share and a content code.'))
            ->identifyTarget(ReportedReferenceType::SharingCode->value, null, null, 'Shared in a public demo channel')
            ->linkUser($users['alex'])->linkComic($comics['paper-dragons'])->linkShare($shares['alex-to-casey'])
            ->reviewAs($users['admin'], ContentReport::STATUS_UNDER_REVIEW);
        $manager->persist($underReview);
        $closed = (new ContentReport('Taylor Sample', 'reporter.three@example.test', ContentReport::CATEGORY_COPYRIGHT, '@erin_reads', 'This fixture report has already been reviewed and closed, preserving a useful history example.'))
            ->identifyTarget(ReportedReferenceType::Account->value, null, '@erin_reads', null)
            ->linkUser($users['erin'])->reviewAs($users['admin'], ContentReport::STATUS_CLOSED)
            ->resolve('no_action', 'The supplied information did not identify an infringement.');
        $manager->persist($closed);
        $manager->flush();
        $received->snapshotTarget('fixture_exact_match');
        $underReview->snapshotTarget('fixture_code_match');
        $closed->snapshotTarget('fixture_account_match');

        $manager->persist((new UserWarning($users['alex'], $users['admin'], 'Please review the metadata on this comic before sharing it again.', UserWarning::SUBJECT_COMIC, 'Paper Dragons'))
            ->linkComic($comics['paper-dragons'])->recordEmailSent());
        $manager->persist((new UserWarning($users['blair'], $users['admin'], 'Thanks for checking your sharing settings. This fixture warning has already been acknowledged.'))->acknowledge());
        $manager->persist((new UserWarning($users['casey'], $users['admin'], 'A demo sharing notice could not be delivered; verify the recipient before retrying.', UserWarning::SUBJECT_SHARE, 'Harbor Lights invitation'))->recordEmailFailed());

        $logs = [
            ['user_update', 'user', $users['erin']->getId(), ['sharing_restricted' => true]],
            ['comic_update', 'comic', $comics['restricted-frequency']->getId(), ['quarantined' => true]],
            ['content_report_review', 'content_report', $underReview->getId(), ['status' => ContentReport::STATUS_UNDER_REVIEW]],
            ['share_revoke', 'comic_share', $shares['alex-revoked']->getId(), ['reason' => 'fixture_example']],
            ['share_claim_code_revoke', 'share_content_code', $codes['revoked']->getId(), ['code_type' => ShareCodeType::COMIC->value]],
        ];
        foreach ($logs as [$action, $type, $id, $payload]) {
            $manager->persist((new AdminAuditLog())->setAdminUser($users['admin'])->setAction($action)
                ->setTargetType($type)->setTargetId($id)->setPayload($payload));
        }
    }

    private function createDemoArchive(): string
    {
        $directory = $this->comicsDirectory.'/.fixtures';
        $this->ensureDirectory($directory);
        $archive = $directory.'/'.self::FILE_PREFIX.'one-page.cbz';
        $image = $this->projectDirectory.'/public/comic.png';
        if (!is_file($image)) {
            throw new \RuntimeException(sprintf('The demo page image "%s" does not exist.', $image));
        }
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(sprintf('Unable to create the demo comic archive "%s".', $archive));
        }
        if (!$zip->addFile($image, 'page-01.png')) {
            $zip->close();
            throw new \RuntimeException('Unable to add the demo page to the fixture archive.');
        }
        if (!$zip->close()) {
            throw new \RuntimeException(sprintf('Unable to finish the demo comic archive "%s".', $archive));
        }

        return $archive;
    }

    private function installComicArchive(User $owner, string $filename, string $source): int
    {
        $ownerId = $owner->getId();
        if ($ownerId === null) {
            throw new \LogicException('Fixture users must be persisted before their comic files are installed.');
        }
        $directory = $this->comicsDirectory.'/'.$ownerId;
        $this->ensureDirectory($directory);
        $destination = $directory.'/'.$filename;
        if (is_file($destination) && !unlink($destination)) {
            throw new \RuntimeException(sprintf('Unable to replace the old demo comic "%s".', $destination));
        }
        if (!@link($source, $destination) && !copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Unable to install the demo comic "%s".', $destination));
        }
        $size = filesize($destination);
        if ($size === false) {
            throw new \RuntimeException(sprintf('Unable to read the demo comic size for "%s".', $destination));
        }

        return $size;
    }

    private function removeOldDemoFiles(): void
    {
        if (!is_dir($this->comicsDirectory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->comicsDirectory,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($files as $file) {
            if ($file->isFile() && str_starts_with($file->getFilename(), self::FILE_PREFIX)
                && !unlink($file->getPathname())) {
                throw new \RuntimeException(sprintf('Unable to remove the old demo fixture file "%s".', $file->getPathname()));
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create the fixture directory "%s".', $directory));
        }
    }
}
