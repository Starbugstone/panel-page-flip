<?php

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: "unique_tag_per_creator", columns: ["name", "creator_id"])]
#[ORM\UniqueConstraint(name: "unique_global_tag_name", columns: ["global_name_key"])]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private ?string $name = null;

    #[ORM\ManyToMany(targetEntity: Comic::class, mappedBy: 'tags')]
    private Collection $comics;

    #[ORM\ManyToOne(inversedBy: 'createdTags')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $creator = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isGlobal = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $hideFromLibrary = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Written by MySQL, never by PHP. UNIQUE (name, creator_id) treats every
     * NULL creator_id as distinct, so it cannot stop two globals sharing a
     * name; this generated column carries the lowercased name for globals only
     * and NULL otherwise, which the unique index above can enforce.
     *
     * Mapped purely so the schema tool knows it exists — without it the column
     * lives only in the migration, which means schema:validate reports drift
     * and the test database (built from mapping) never gets the constraint.
     */
    #[ORM\Column(
        length: 50,
        nullable: true,
        insertable: false,
        updatable: false,
        generated: 'ALWAYS',
        columnDefinition: "VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN is_global = 1 THEN LOWER(name) ELSE NULL END) STORED",
    )]
    private ?string $globalNameKey = null;

    public function __construct()
    {
        $this->comics = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return Collection<int, Comic>
     */
    public function getComics(): Collection
    {
        return $this->comics;
    }

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(?User $creator): static
    {
        $this->creator = $creator;
        return $this;
    }

    public function isGlobal(): bool
    {
        return $this->isGlobal;
    }

    public function setIsGlobal(bool $isGlobal): static
    {
        $this->isGlobal = $isGlobal;
        if ($isGlobal) {
            $this->creator = null;
        }

        return $this;
    }

    public function hidesFromLibrary(): bool
    {
        return $this->hideFromLibrary;
    }

    public function setHideFromLibrary(bool $hideFromLibrary): static
    {
        $this->hideFromLibrary = $hideFromLibrary;
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
}
