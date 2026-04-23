<?php

namespace App\Studio\Rendering\Dto;

use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;

/**
 * Normalized, worker-local inputs for {@see \App\Studio\Rendering\Contracts\CompositionRenderer}.
 *
 * @param  list<RenderLayer>  $layers
 */
final readonly class CompositionRenderRequest
{
    /**
     * @param  list<RenderLayer>  $layers
     */
    public function __construct(
        public StudioCompositionVideoExportJob $exportJob,
        public Tenant $tenant,
        public User $user,
        public ?string $workspacePath,
        public ?RenderTimeline $timeline,
        public array $layers,
        public bool $includeAudio,
        /** Staged absolute paths keyed by original asset id or synthetic key */
        public array $stagedPathsByKey,
    ) {}
}
