<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use App\Service\UsernamePolicy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\Index(name: 'IDX_user_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
#[UniqueEntity(fields: ['usernameCanonical'], message: 'That username is already taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dropboxAccessToken = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dropboxRefreshToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dropboxLastSyncedAt = null;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    /**
     * The public handle this account is known by, as its owner writes it.
     *
     * Every account has one, and it is the identity sharing speaks in: the
     * address is private, and the display name is not unique, so neither can
     * answer "am I about to hand this comic to the right person?". A username
     * can.
     *
     * @see UsernamePolicy for what one may be
     */
    #[ORM\Column(length: 32, unique: true)]
    private string $username = '';

    /**
     * The same username, lowercased, which is what uniqueness is judged on.
     *
     * Two columns rather than a case-insensitive collation because the
     * collation is a property of the schema that a later migration can quietly
     * change; this is a property of the data, and the unique index on it means
     * `@SilverOtter` and `@silverotter` cannot both exist regardless.
     *
     * @see UsernamePolicy::canonicalise()
     */
    #[ORM\Column(length: 32, unique: true)]
    private string $usernameCanonical = '';

    /**
     * The `U-` code other people share with this account by.
     *
     * Not a credential and not a secret: it authenticates nobody, exposes
     * nothing about the account beyond the username somebody who already holds
     * it is shown, and grants no access on its own. That is why it is stored in
     * the clear where an invitation token is stored hashed — its owner has to
     * be able to read it back and hand it out again.
     *
     * Stable, but not permanent. It is meant to be pasted into chats, forums
     * and group threads, which is exactly the kind of place a thing escapes
     * from — and an identifier its owner cannot retire after that is one they
     * are stuck with. Rotation is theirs to trigger, and an administrator's on
     * their behalf; nothing rotates it on its own, because everybody who was
     * given the old one has to be told the new one.
     *
     * Stored as the bare twelve-character token. The `U-` that a person sees is
     * added on the way out, so the prefix cannot drift row by row.
     */
    #[ORM\Column(length: 16, unique: true)]
    private string $userCode = '';

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];
    
    /**
     * Whether the user's email has been verified
     */
    #[ORM\Column(type: 'boolean')]
    private bool $isEmailVerified = false;
    

    /**
     * @var string|null The hashed password, or null for a social-only account
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sharingRestrictedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $readerPreferences = null;

    /**
     * Whether this user may spend external metadata-provider allowance.
     *
     * On by default so existing installations behave as they did; an
     * administrator can withdraw it per user without disabling the provider for
     * everybody. Local sources — ComicInfo.xml and the filename parser — are
     * unaffected, because neither leaves the server.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $metadataApiEnabled = true;

    /**
     * Canonical-source storage this account may own, in bytes.
     *
     * Null means "inherit the installation default"; zero explicitly removes
     * the application-level quota for this account.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Assert\PositiveOrZero(message: 'The storage quota override cannot be negative.')]
    private ?int $storageQuotaOverrideBytes = null;

    /**
     * @var Collection<int, Comic>
     */
    #[ORM\OneToMany(targetEntity: Comic::class, mappedBy: 'owner', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $comics;
    
    /**
     * @var Collection<int, ComicReadingProgress>
     */
    #[ORM\OneToMany(targetEntity: ComicReadingProgress::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $readingProgress;
    
    /**
     * @var Collection<int, Tag>
     */
    #[ORM\OneToMany(targetEntity: Tag::class, mappedBy: 'creator')]
    private Collection $createdTags;
    
    /**
     * @var Collection<int, ResetPasswordToken>
     */
    #[ORM\OneToMany(targetEntity: ResetPasswordToken::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $resetPasswordTokens;

    /**
     * @var Collection<int, EmailVerificationToken>
     */
    #[ORM\OneToMany(targetEntity: EmailVerificationToken::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $emailVerificationTokens;

    /** @var Collection<int, UserOAuthIdentity> */
    #[ORM\OneToMany(targetEntity: UserOAuthIdentity::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $oauthIdentities;

    public function getDropboxAccessToken(): ?string
    {
        return $this->dropboxAccessToken;
    }

    public function setDropboxAccessToken(?string $dropboxAccessToken): static
    {
        $this->dropboxAccessToken = $dropboxAccessToken;
        return $this;
    }

    public function getDropboxRefreshToken(): ?string
    {
        return $this->dropboxRefreshToken;
    }

    public function setDropboxRefreshToken(?string $dropboxRefreshToken): static
    {
        $this->dropboxRefreshToken = $dropboxRefreshToken;
        return $this;
    }

    /**
     * Whether this account has a Dropbox connection to work with.
     *
     * Either credential counts. The refresh token is the durable half — an
     * access token is short-lived and may be absent, and the client mints a new
     * one from the refresh token on demand. Guarding on the access token alone
     * reported an account as disconnected precisely when it was recoverable.
     */
    public function hasDropboxConnection(): bool
    {
        return ($this->dropboxAccessToken ?? '') !== '' || ($this->dropboxRefreshToken ?? '') !== '';
    }

    public function getDropboxLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->dropboxLastSyncedAt;
    }

    public function setDropboxLastSyncedAt(?\DateTimeImmutable $dropboxLastSyncedAt): static
    {
        $this->dropboxLastSyncedAt = $dropboxLastSyncedAt;
        return $this;
    }

    public function __construct()
    {
        $this->comics = new ArrayCollection();
        $this->readingProgress = new ArrayCollection();
        $this->createdTags = new ArrayCollection();
        $this->resetPasswordTokens = new ArrayCollection();
        $this->emailVerificationTokens = new ArrayCollection();
        $this->oauthIdentities = new ArrayCollection();
        $this->isEmailVerified = false;

        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->roles = ['ROLE_USER'];
        $this->isEmailVerified = false;
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }
    
    public function getName(): ?string
    {
        return $this->name;
    }
    
    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUsernameCanonical(): string
    {
        return $this->usernameCanonical;
    }

    /**
     * Take a username, keeping the canonical form in step.
     *
     * The pair is set together and only here, so nothing can leave a row whose
     * unique index no longer describes the name it holds. Validation belongs to
     * {@see UsernameService}, which is also the thing that knows whether the
     * name is free.
     */
    public function setUsername(string $username): static
    {
        $this->username = trim($username);
        $this->usernameCanonical = UsernamePolicy::canonicalise($username);

        return $this;
    }

    public function getUserCode(): string
    {
        return $this->userCode;
    }

    /**
     * Give this account its first code, if it has none.
     *
     * Deliberately refuses to overwrite: issuing and rotating are different
     * acts, and only one of them is allowed to retire an identifier other
     * people are holding.
     */
    public function assignUserCode(string $userCode): static
    {
        if ($this->userCode === '') {
            $this->userCode = $userCode;
        }

        return $this;
    }

    /**
     * Retire the current code and take a new one.
     *
     * The only path that replaces an existing code, and it exists so a code
     * that has escaped further than its owner intended can be taken out of
     * circulation. Nothing else about the account changes — least of all the
     * shares already made through the old code, which are relationships and
     * not addresses.
     *
     * @see \App\Service\SharingCodeService::rotateCode()
     */
    public function replaceUserCode(string $userCode): static
    {
        $this->userCode = $userCode;

        return $this;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    /**
     * Use for accounts outside the current security token. When a token is
     * available, prefer isGranted() so the role hierarchy is honoured.
     */
    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    public function isSharingRestricted(): bool
    {
        return $this->sharingRestrictedAt !== null;
    }

    public function restrictSharing(): static
    {
        $this->sharingRestrictedAt ??= new \DateTimeImmutable();
        return $this;
    }

    public function liftSharingRestriction(): static
    {
        $this->sharingRestrictedAt = null;
        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function hasPassword(): bool
    {
        return ($this->password ?? '') !== '';
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // Nothing to erase: this entity never holds a plaintext password. The
        // submitted one is hashed by the authenticator and never assigned here.
    }
    
    public function isEmailVerified(): bool
    {
        return $this->isEmailVerified;
    }
    
    public function setIsEmailVerified(bool $isEmailVerified): static
    {
        $this->isEmailVerified = $isEmailVerified;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getReaderPreferences(): ?array
    {
        return $this->readerPreferences;
    }

    /** @param array<string, mixed>|null $readerPreferences */
    public function setReaderPreferences(?array $readerPreferences): static
    {
        $this->readerPreferences = $readerPreferences;

        return $this;
    }

    public function isMetadataApiEnabled(): bool
    {
        return $this->metadataApiEnabled;
    }

    public function setMetadataApiEnabled(bool $metadataApiEnabled): static
    {
        $this->metadataApiEnabled = $metadataApiEnabled;

        return $this;
    }

    public function getStorageQuotaOverrideBytes(): ?int
    {
        return $this->storageQuotaOverrideBytes;
    }

    public function setStorageQuotaOverrideBytes(?int $storageQuotaOverrideBytes): static
    {
        $this->storageQuotaOverrideBytes = $storageQuotaOverrideBytes;

        return $this;
    }


    /**
     * @return Collection<int, Comic>
     */
    public function getComics(): Collection
    {
        return $this->comics;
    }

    /**
     * @return Collection<int, ComicReadingProgress>
     */
    public function getReadingProgress(): Collection
    {
        return $this->readingProgress;
    }
    
    /**
     * @return Collection<int, Tag>
     */
    public function getCreatedTags(): Collection
    {
        return $this->createdTags;
    }

    /** @return Collection<int, UserOAuthIdentity> */
    public function getOAuthIdentities(): Collection
    {
        return $this->oauthIdentities;
    }

    public function addOAuthIdentity(UserOAuthIdentity $identity): static
    {
        if (!$this->oauthIdentities->contains($identity)) {
            $this->oauthIdentities->add($identity);
        }

        return $this;
    }

    public function removeOAuthIdentity(UserOAuthIdentity $identity): static
    {
        $this->oauthIdentities->removeElement($identity);

        return $this;
    }
    
}
