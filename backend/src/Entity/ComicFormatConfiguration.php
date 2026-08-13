<?php

namespace App\Entity;

use App\Enum\ComicSourceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ComicFormatConfiguration
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $enabledFormats = ['cbz'];

    /** @return list<ComicSourceType> */
    public function getEnabledFormats(): array
    {
        $formats = [];
        foreach ($this->enabledFormats as $value) {
            $type = ComicSourceType::tryFrom($value);
            if ($type !== null) $formats[$type->value] = $type;
        }
        $formats[ComicSourceType::CBZ->value] = ComicSourceType::CBZ;
        return array_values($formats);
    }

    /** @param list<ComicSourceType> $formats */
    public function setEnabledFormats(array $formats): self
    {
        $values = [ComicSourceType::CBZ->value => ComicSourceType::CBZ->value];
        foreach ($formats as $format) $values[$format->value] = $format->value;
        $this->enabledFormats = array_values($values);
        return $this;
    }
}
