<?php

namespace App\Entity;

use App\Repository\UserRepository;
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
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
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
    private ?string $email = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    /**
     * The address other people share with this account by.
     *
     * Not a credential and not a secret: it authenticates nobody, exposes
     * nothing about the account beyond the display name somebody who already
     * holds it is shown, and grants no access on its own. That is why it is
     * stored in the clear where an invitation token is stored hashed — its
     * owner has to be able to read it back and hand it out again.
     *
     * Stable, but not permanent. It is meant to be pasted into chats, forums
     * and group threads, which is exactly the kind of place a thing escapes
     * from — and an identifier its owner cannot retire after that is one they
     * are stuck with. Rotation is theirs to trigger, and an administrator's on
     * their behalf; nothing rotates it on its own, because everybody who was
     * given the old one has to be told the new one.
     *
     * Nullable only so accounts that predate the column can be filled in on
     * first use rather than in a migration that would have to invent one for
     * every row at once.
     */
    #[ORM\Column(length: 16, unique: true, nullable: true)]
    private ?string $sharingCode = null;

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
     * Email verification token
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailVerificationToken = null;
    
    /**
     * When the email verification token expires
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificationTokenExpiresAt = null;

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sharingRestrictedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $readerPreferences = null;

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

    public function getEmail(): ?string
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
    
    public function getSharingCode(): ?string
    {
        return $this->sharingCode;
    }

    /**
     * Give this account its first code, if it has none.
     *
     * Deliberately refuses to overwrite: issuing and rotating are different
     * acts, and only one of them is allowed to retire an identifier other
     * people are holding.
     */
    public function assignSharingCode(string $sharingCode): static
    {
        if ($this->sharingCode === null) {
            $this->sharingCode = $sharingCode;
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
    public function replaceSharingCode(string $sharingCode): static
    {
        $this->sharingCode = $sharingCode;

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

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function isSharingRestricted(): bool
    {
        return $this->sharingRestrictedAt !== null;
    }

    public function getSharingRestrictedAt(): ?\DateTimeImmutable
    {
        return $this->sharingRestrictedAt;
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
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
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

    /**
     * @return Collection<int, Comic>
     */
    public function getComics(): Collection
    {
        return $this->comics;
    }

    public function addComic(Comic $comic): static
    {
        if (!$this->comics->contains($comic)) {
            $this->comics->add($comic);
            $comic->setOwner($this);
        }
        return $this;
    }

    public function removeComic(Comic $comic): static
    {
        if ($this->comics->removeElement($comic)) {
            // set the owning side to null (unless already changed)
            if ($comic->getOwner() === $this) {
                $comic->setOwner(null);
            }
        }
        return $this;
    }
    
    /**
     * @return Collection<int, ComicReadingProgress>
     */
    public function getReadingProgress(): Collection
    {
        return $this->readingProgress;
    }
    
    public function addReadingProgress(ComicReadingProgress $progress): static
    {
        if (!$this->readingProgress->contains($progress)) {
            $this->readingProgress->add($progress);
            $progress->setUser($this);
        }
        return $this;
    }
    
    public function removeReadingProgress(ComicReadingProgress $progress): static
    {
        if ($this->readingProgress->removeElement($progress)) {
            if ($progress->getUser() === $this) {
                $progress->setUser(null);
            }
        }
        return $this;
    }
    
    /**
     * @return Collection<int, Tag>
     */
    public function getCreatedTags(): Collection
    {
        return $this->createdTags;
    }
    
    public function addCreatedTag(Tag $tag): static
    {
        if (!$this->createdTags->contains($tag)) {
            $this->createdTags->add($tag);
            $tag->setCreator($this);
        }
        return $this;
    }
    
    public function removeCreatedTag(Tag $tag): static
    {
        if ($this->createdTags->removeElement($tag)) {
            if ($tag->getCreator() === $this) {
                $tag->setCreator(null);
            }
        }
        return $this;
    }
    
    /**
     * @return Collection<int, ResetPasswordToken>
     */
    public function getResetPasswordTokens(): Collection
    {
        return $this->resetPasswordTokens;
    }
    
    public function addResetPasswordToken(ResetPasswordToken $token): static
    {
        if (!$this->resetPasswordTokens->contains($token)) {
            $this->resetPasswordTokens->add($token);
            $token->setUser($this);
        }
        return $this;
    }
    
    public function removeResetPasswordToken(ResetPasswordToken $token): static
    {
        if ($this->resetPasswordTokens->removeElement($token)) {
            // set the owning side to null (unless already changed)
            if ($token->getUser() === $this) {
                $token->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailVerificationToken>
     */
    public function getEmailVerificationTokens(): Collection
    {
        return $this->emailVerificationTokens;
    }

    public function addEmailVerificationToken(EmailVerificationToken $token): static
    {
        if (!$this->emailVerificationTokens->contains($token)) {
            $this->emailVerificationTokens->add($token);
            $token->setUser($this);
        }

        return $this;
    }

    public function removeEmailVerificationToken(EmailVerificationToken $token): static
    {
        if ($this->emailVerificationTokens->removeElement($token)) {
            if ($token->getUser() === $this) {
                $token->setUser(null);
            }
        }

        return $this;
    }
    
    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function setEmailVerificationToken(?string $token): static
    {
        $this->emailVerificationToken = $token;
        return $this;
    }

    public function getEmailVerificationTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailVerificationTokenExpiresAt;
    }

    public function setEmailVerificationTokenExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->emailVerificationTokenExpiresAt = $expiresAt;
        return $this;
    }
    
    public function isEmailVerificationTokenExpired(): bool
    {
        if (!$this->emailVerificationToken || !$this->emailVerificationTokenExpiresAt) {
            return true;
        }
        
        return $this->emailVerificationTokenExpiresAt < new \DateTimeImmutable();
    }
    
    public function generateEmailVerificationToken(): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $this->emailVerificationToken = hash('sha256', $plainToken);
        $this->emailVerificationTokenExpiresAt = (new \DateTimeImmutable())->modify('+24 hours');
        
        return $plainToken;
    }
}
