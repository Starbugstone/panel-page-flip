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

    public function __construct()
    {
        $this->comics = new ArrayCollection();
        $this->readingProgress = new ArrayCollection();
        $this->createdTags = new ArrayCollection();
        $this->resetPasswordTokens = new ArrayCollection();
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
        $this->emailVerificationToken = bin2hex(random_bytes(32));
        $this->emailVerificationTokenExpiresAt = (new \DateTimeImmutable())->modify('+24 hours');
        
        return $this->emailVerificationToken;
    }
}
