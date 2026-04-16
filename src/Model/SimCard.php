<?php

declare(strict_types=1);

namespace Krasselt\M2mDataUsage\Model;

class SimCard
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public readonly string $cardId,
        public readonly string $iccid,
        public readonly string $rufnummer,
        public readonly string $tarif,
        public readonly string $status,
        public readonly string $bestellt,
        public readonly string $aktivierung,
        public readonly array  $tags,
        public readonly string $currentUsage,
    ) {
    }

    public function isActive(): bool
    {
        return stripos($this->status, 'Aktiv') !== false
            && stripos($this->status, 'Deaktiviert') === false;
    }

    public function tagsAsString(): string
    {
        return implode(', ', $this->tags);
    }
}
