<?php

namespace App\Enums;

enum ImpersonationMode: string
{
    case ReadOnly = 'read_only';
    case Full = 'full';
    /** Tier 2 — reserved; currently enforced like read-only at the HTTP/policy layer. */
    case Assisted = 'assisted';

    public function label(): string
    {
        return match ($this) {
            self::ReadOnly => 'Read-only',
            self::Full => 'Full',
            self::Assisted => 'Assisted',
        };
    }

    public function isWriteCapable(): bool
    {
        return $this === self::Full;
    }
}
