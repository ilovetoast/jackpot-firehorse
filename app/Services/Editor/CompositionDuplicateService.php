<?php

namespace App\Services\Editor;

use App\Models\Asset;
use App\Models\Composition;
use App\Models\CompositionVersion;
use App\Models\User;
use App\Services\CompositionThumbnailAssetService;
use Illuminate\Support\Facades\DB;

/**
 * Deep-clone a {@link Composition} row (+ seed manual history snapshot), mirroring
 * {@see \App\Http\Controllers\Editor\EditorCompositionController::duplicate()}.
 */
class CompositionDuplicateService
{
    public function __construct(
        protected CompositionThumbnailAssetService $thumbnailAssets,
    ) {}

    public function duplicate(Composition $source, User $user, ?string $name = null, string $versionLabel = 'Duplicated'): Composition
    {
        $tenant = $source->tenant;
        $brand = $source->brand;
        if (! $tenant || ! $brand) {
            throw new \InvalidArgumentException('Composition is missing tenant or brand.');
        }

        $doc = $source->document_json ?? [];
        $resolvedName = $name ?? ($source->name.' (copy)');

        return DB::transaction(function () use ($source, $tenant, $brand, $user, $doc, $resolvedName, $versionLabel): Composition {
            $visibility = $source->visibility ?? Composition::VISIBILITY_SHARED;

            $c = Composition::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'visibility' => $visibility,
                'name' => $resolvedName,
                'document_json' => $doc,
            ]);

            CompositionVersion::query()->create([
                'composition_id' => $c->id,
                'document_json' => $doc,
                'label' => $versionLabel,
                'kind' => CompositionVersion::KIND_MANUAL,
                'created_at' => now(),
            ]);

            $dupThumbId = $source->thumbnail_asset_id
                ? $this->thumbnailAssets->duplicateAsset($source->thumbnail_asset_id, $tenant, $brand, $user)
                : null;
            if ($dupThumbId !== null && $dupThumbId !== '') {
                $c->thumbnail_asset_id = $dupThumbId;
                $c->save();
            }

            $this->pruneOldVersions($c->fresh());

            return $c->fresh();
        });
    }

    private const MAX_MANUAL_VERSIONS = 50;

    private const MAX_AUTOSAVE_VERSIONS = 10;

    private function pruneOldVersions(Composition $composition): void
    {
        $this->pruneVersionsOfKind($composition, CompositionVersion::KIND_MANUAL, self::MAX_MANUAL_VERSIONS);
        $this->pruneVersionsOfKind($composition, CompositionVersion::KIND_AUTOSAVE, self::MAX_AUTOSAVE_VERSIONS);
    }

    private function pruneVersionsOfKind(Composition $composition, string $kind, int $max): void
    {
        $compositionId = $composition->id;
        $count = CompositionVersion::query()
            ->where('composition_id', $compositionId)
            ->where('kind', $kind)
            ->count();
        if ($count <= $max) {
            return;
        }

        $deleteCount = $count - $max;
        $toDelete = CompositionVersion::query()
            ->where('composition_id', $compositionId)
            ->where('kind', $kind)
            ->orderBy('id')
            ->limit($deleteCount)
            ->get();

        foreach ($toDelete as $v) {
            if ($v->thumbnail_asset_id) {
                Asset::query()->whereKey($v->thumbnail_asset_id)->delete();
            }
            $v->delete();
        }
    }
}
