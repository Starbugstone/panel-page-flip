<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give every account a unique username and a `U-` code, and type the content
 * codes.
 *
 * The backfill is written out longhand rather than calling into the
 * application's own generator. A migration is a record of what happened to a
 * database on a particular day, and one that delegates to a service is one
 * whose behaviour changes the next time that service is edited — so a
 * re-run against a restored backup would no longer reproduce the same rows.
 * The word lists here are a snapshot, and they are allowed to drift from
 * {@see \App\Service\UsernameGenerator}.
 */
final class Version20260816120000 extends AbstractMigration
{
    private const ADJECTIVES = [
        'Amber', 'Ancient', 'Azure', 'Bold', 'Brave', 'Bright', 'Bronze', 'Calm',
        'Clever', 'Copper', 'Coral', 'Cosmic', 'Crimson', 'Curious', 'Daring',
        'Dusty', 'Eager', 'Electric', 'Emerald', 'Fearless', 'Gentle', 'Gilded',
        'Golden', 'Hidden', 'Indigo', 'Iron', 'Jade', 'Keen', 'Lucky', 'Lunar',
        'Merry', 'Midnight', 'Nimble', 'Noble', 'Northern', 'Quiet', 'Rapid',
        'Restless', 'Roaming', 'Ruby', 'Rustic', 'Sapphire', 'Scarlet', 'Silent',
        'Silver', 'Solar', 'Steady', 'Stellar', 'Sunlit', 'Swift', 'Thunder',
        'Tidal', 'Timber', 'Velvet', 'Vivid', 'Wandering', 'Wild', 'Winter',
        'Wistful', 'Zephyr',
    ];

    private const NOUNS = [
        'Albatross', 'Antler', 'Badger', 'Beacon', 'Bison', 'Boulder', 'Canyon',
        'Cedar', 'Comet', 'Compass', 'Condor', 'Coyote', 'Cricket', 'Dolphin',
        'Ember', 'Falcon', 'Fern', 'Finch', 'Fjord', 'Glacier', 'Harbour',
        'Heron', 'Ibex', 'Jackal', 'Kestrel', 'Lantern', 'Lynx', 'Magpie',
        'Mantis', 'Marlin', 'Meadow', 'Meteor', 'Mongoose', 'Nebula', 'Otter',
        'Panther', 'Pelican', 'Pine', 'Quartz', 'Quill', 'Raven', 'Reef',
        'Rocket', 'Sable', 'Salmon', 'Sparrow', 'Stallion', 'Summit', 'Tempest',
        'Thistle', 'Tiger', 'Toucan', 'Vulture', 'Walrus', 'Willow', 'Wolf',
        'Wombat', 'Yak', 'Zebra', 'Zenith',
    ];

    /** Crockford base32, matching {@see \App\Service\SharingCodeFormat}. */
    private const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function getDescription(): string
    {
        return 'Add unique usernames, backfill user codes for every account, and type content codes as C- or G-.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        // Executed rather than queued, unlike every other migration here.
        // `addSql` collects statements and runs them after this method returns,
        // which is fine when the whole change is declarative — and wrong here,
        // because the backfill in the middle has to see the columns the
        // statements before it add, and the constraints after it have to see
        // the rows the backfill writes. Three phases with a PHP loop in the
        // middle is an ordering this migration cannot delegate.
        $execute = fn (string $sql) => $this->connection->executeStatement($sql);

        // Nullable first, filled in second, constrained third. A NOT NULL
        // column cannot be added to a table that already has rows without
        // inventing a value for every one of them in the same statement, and
        // the value here has to be unique per row.
        $execute('ALTER TABLE `user` ADD username VARCHAR(32) DEFAULT NULL, ADD username_canonical VARCHAR(32) DEFAULT NULL');
        $execute('ALTER TABLE `user` CHANGE sharing_code user_code VARCHAR(16) DEFAULT NULL');

        $this->backfillIdentities();

        $execute('ALTER TABLE `user` CHANGE username username VARCHAR(32) NOT NULL, CHANGE username_canonical username_canonical VARCHAR(32) NOT NULL, CHANGE user_code user_code VARCHAR(16) NOT NULL');
        // Doctrine's own generated names, so `doctrine:schema:validate` stays
        // clean. A hand-picked name here reads better and costs a permanent
        // diff against the mapping, which is worse.
        $execute('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON `user` (username)');
        // The one that actually enforces the rule people care about: two
        // accounts cannot differ only in capitalisation, whatever the column
        // collation happens to be.
        $execute('CREATE UNIQUE INDEX UNIQ_8D93D64992FC23A8 ON `user` (username_canonical)');
        // The sharing-code index came over with the column rename and still
        // carries its old name.
        $execute('ALTER TABLE `user` RENAME INDEX UNIQ_8D93D649CFA8625F TO UNIQ_8D93D649D947C51');

        // Content codes gain their type and the size of the package they were
        // issued for. Existing rows are classified by what they carry: one
        // comic is a C-, anything else a G-. There is nothing else to go on,
        // and it is the classification their owners would recognise.
        // Added with a default so existing rows have something, then stripped
        // of it: the mapping declares no default, and a column that keeps one
        // would let a future insert omit the type and silently get a `C-`.
        $execute("ALTER TABLE share_claim_code ADD type VARCHAR(1) DEFAULT 'C' NOT NULL, ADD issued_comic_count INT DEFAULT 0 NOT NULL");
        $execute(<<<'SQL'
            UPDATE share_claim_code c
            SET c.issued_comic_count = (
                SELECT COUNT(*) FROM share_claim_code_comic j WHERE j.share_claim_code_id = c.id
            )
            SQL);
        $execute("UPDATE share_claim_code SET type = 'G' WHERE issued_comic_count <> 1");
        $execute('ALTER TABLE share_claim_code CHANGE type type VARCHAR(1) NOT NULL');

        // Their hashes covered the token alone; they now cover the type as
        // well, so nothing already issued can still be redeemed. Withdrawing
        // them is honest about that: an owner sees a withdrawn code rather than
        // a live one that silently no longer works. It is why this release
        // makes a clean break — a compatibility path would mean carrying an
        // untyped lookup for ever, and content codes are short-lived by design,
        // so the oldest live one on any installation is a day old.
        $execute('UPDATE share_claim_code SET revoked_at = NOW() WHERE revoked_at IS NULL AND expires_at > NOW()');

        $execute('ALTER TABLE comic_share CHANGE recipient_sharing_code recipient_user_code VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE comic_share CHANGE recipient_user_code recipient_sharing_code VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE share_claim_code DROP type, DROP issued_comic_count');
        $this->addSql('DROP INDEX UNIQ_8D93D64992FC23A8 ON `user`');
        $this->addSql('DROP INDEX UNIQ_8D93D649F85E0677 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP username, DROP username_canonical');
        $this->addSql('ALTER TABLE `user` CHANGE user_code sharing_code VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` RENAME INDEX UNIQ_8D93D649D947C51 TO UNIQ_8D93D649CFA8625F');
    }

    /**
     * Give every existing account a username, and a user code if it has none.
     *
     * Run in the migration rather than deferred to a command, because the NOT
     * NULL and the unique index that follow it are what make the invariant
     * real, and both need every row filled in first. A deployment that could
     * finish this migration and forget the command would be a deployment that
     * could leave accounts nobody can share with.
     */
    private function backfillIdentities(): void
    {
        $taken = [];
        foreach ($this->connection->fetchFirstColumn('SELECT username_canonical FROM `user` WHERE username_canonical IS NOT NULL') as $existing) {
            $taken[(string) $existing] = true;
        }

        $codesTaken = [];
        foreach ($this->connection->fetchFirstColumn('SELECT user_code FROM `user` WHERE user_code IS NOT NULL') as $existing) {
            $codesTaken[(string) $existing] = true;
        }

        $rows = $this->connection->fetchAllAssociative('SELECT id, username, user_code FROM `user`');

        foreach ($rows as $row) {
            $updates = [];
            $parameters = ['id' => $row['id']];

            if (($row['username'] ?? null) === null) {
                $username = $this->freeUsername($taken);
                $taken[strtolower($username)] = true;

                $updates[] = 'username = :username';
                $updates[] = 'username_canonical = :canonical';
                $parameters['username'] = $username;
                $parameters['canonical'] = strtolower($username);
            }

            if (($row['user_code'] ?? null) === null) {
                $code = $this->freeUserCode($codesTaken);
                $codesTaken[$code] = true;

                $updates[] = 'user_code = :code';
                $parameters['code'] = $code;
            }

            if ($updates === []) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE `user` SET ' . implode(', ', $updates) . ' WHERE id = :id',
                $parameters
            );
        }
    }

    /** @param array<string, true> $taken */
    private function freeUsername(array $taken): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            // Widening the suffix once the short space starts colliding, so an
            // installation with more accounts than the four-digit space
            // comfortably holds still finishes.
            $digits = $attempt < 10 ? 4 : 10;
            $candidate = self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)]
                . self::NOUNS[random_int(0, count(self::NOUNS) - 1)]
                . str_pad((string) random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);

            // 32 is the column width and the policy ceiling; the longest pair
            // above plus ten digits stays inside it, but truncating keeps that
            // true if the lists are ever edited.
            $candidate = substr($candidate, 0, 32);

            if (!isset($taken[strtolower($candidate)])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not allocate a unique username during backfill.');
    }

    /** @param array<string, true> $taken */
    private function freeUserCode(array $taken): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $code = '';
            for ($i = 0; $i < 12; ++$i) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            if (!isset($taken[$code])) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not allocate a unique user code during backfill.');
    }
}
