<?php

namespace App\Studio\Animation\Support;

final class StudioAnimationDriftBlockedException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $decision
     */
    public function __construct(
        public readonly array $decision = [],
    ) {
        $reason = (string) ($decision['blocked_reason'] ?? 'drift_blocked');
        parent::__construct('Snapshot drift gate blocked this submission ('.$reason.').');
    }
}
