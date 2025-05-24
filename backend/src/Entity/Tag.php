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
    #[ORM\JoinColumn(nullable: false)]
    private ?User $creator = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function addComic(Comic $comic): static
    {
        if (!$this->comics->contains($comic)) {
            $this->comics->add($comic);
            $comic->addTag($this);
        }

        return $this;
    }

    public function removeComic(Comic $comic): static
    {
        if ($this->comics->removeElement($comic)) {
            $comic->removeTag($this);
        }

        return $this;
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
