<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\EvidenceSource;
use App\Enums\EvidenceWeight;

final class EvidenceItem
{
    public EvidenceSource $type;
    public string $detail;
    public EvidenceWeight $weight;

    public function __construct(
        EvidenceSource $type,
        string $detail,
        EvidenceWeight $weight,
    ) {
        $this->type = $type;
        $this->detail = $detail;
        $this->weight = $weight;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'detail' => $this->detail,
            'weight' => $this->weight->value,
        ];
    }

    public static function hard(EvidenceSource $type, string $detail): self
    {
        return new self($type, $detail, EvidenceWeight::HARD);
    }

    public static function soft(EvidenceSource $type, string $detail): self
    {
        return new self($type, $detail, EvidenceWeight::SOFT);
    }

    public static function readiness(EvidenceSource $type, string $detail): self
    {
        return new self($type, $detail, EvidenceWeight::READINESS);
    }
}
