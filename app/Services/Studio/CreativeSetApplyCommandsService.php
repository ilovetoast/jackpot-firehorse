<?php

namespace App\Services\Studio;

use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\User;
use App\Support\StudioCrossCompositionLayerResolver;
use App\Support\StudioDocumentSyncRoleFinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies allowlisted semantic commands from one composition to sibling variants in a Creative Set.
 * Does not accept arbitrary layer patches from clients — only explicit command types with validated payloads.
 */
final class CreativeSetApplyCommandsService
{
    public const MAX_COMMANDS = 25;

    public function __construct(
        protected StudioCrossCompositionLayerResolver $layerResolver,
        protected StudioDocumentSyncRoleFinder $roleFinder,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @return array{
     *     updated: array<int, string>,
     *     skipped: array<int, array{composition_id: string, reason: string}>,
     *     sibling_compositions_targeted: int,
     *     sibling_compositions_updated: int,
     *     commands_applied: int
     * }
     */
    public function applyToAllVariants(
        CreativeSet $set,
        User $user,
        int $sourceCompositionId,
        array $commands,
    ): array {
        $this->validateCommands($commands);

        $source = Composition::query()
            ->where('id', $sourceCompositionId)
            ->where('tenant_id', $set->tenant_id)
            ->where('brand_id', $set->brand_id)
            ->visibleToUser($user)
            ->first();
        if (! $source) {
            throw ValidationException::withMessages(['source_composition_id' => 'Source composition not found.']);
        }

        $sourceDoc = is_array($source->document_json) ? $source->document_json : [];

        $updated = [];
        $skipped = [];

        $variantCompositionIds = $set->variants()
            ->pluck('composition_id')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $siblings = array_values(array_filter($variantCompositionIds, static fn (int $cid) => $cid !== $sourceCompositionId));
        $targeted = count($siblings);

        foreach ($siblings as $cid) {
            $composition = Composition::query()
                ->where('id', $cid)
                ->where('tenant_id', $set->tenant_id)
                ->where('brand_id', $set->brand_id)
                ->visibleToUser($user)
                ->first();
            if (! $composition) {
                $skipped[] = ['composition_id' => (string) $cid, 'reason' => 'Composition not found or not visible to you'];

                continue;
            }

            $doc = is_array($composition->document_json) ? $composition->document_json : [];
            $changed = false;

            foreach ($commands as $i => $cmd) {
                if (! is_array($cmd)) {
                    $skipped[] = ['composition_id' => (string) $cid, 'reason' => "Command #{$i} is invalid"];

                    continue 2;
                }
                $type = (string) ($cmd['type'] ?? '');
                $nextDoc = match ($type) {
                    'update_text_content' => $this->applyUpdateTextContent($sourceDoc, $doc, $cmd),
                    'update_layer_visibility' => $this->applyUpdateLayerVisibility($sourceDoc, $doc, $cmd),
                    'update_text_alignment' => $this->applyUpdateTextAlignment($sourceDoc, $doc, $cmd),
                    'update_role_transform' => $this->applyUpdateRoleTransform($sourceDoc, $doc, $cmd),
                    default => null,
                };
                if ($nextDoc === null) {
                    $skipped[] = ['composition_id' => (string) $cid, 'reason' => "Could not apply command #{$i} ({$type})"];

                    continue 2;
                }
                $doc = $nextDoc;
                $changed = true;
            }

            if ($changed) {
                DB::transaction(function () use ($composition, $doc): void {
                    $composition->document_json = $doc;
                    $composition->save();
                });
                $updated[] = (string) $cid;
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'sibling_compositions_targeted' => $targeted,
            'sibling_compositions_updated' => count($updated),
            'commands_applied' => count($commands),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     */
    private function validateCommands(array $commands): void
    {
        if ($commands === []) {
            throw ValidationException::withMessages(['commands' => 'Provide at least one command.']);
        }
        if (count($commands) > self::MAX_COMMANDS) {
            throw ValidationException::withMessages(['commands' => 'Too many commands in one request.']);
        }

        foreach ($commands as $i => $cmd) {
            if (! is_array($cmd)) {
                throw ValidationException::withMessages(['commands' => "Command #{$i} must be an object."]);
            }
            $type = (string) ($cmd['type'] ?? '');
            match ($type) {
                'update_text_content' => $this->assertUpdateTextContent($cmd, $i),
                'update_layer_visibility' => $this->assertUpdateLayerVisibility($cmd, $i),
                'update_text_alignment' => $this->assertUpdateTextAlignment($cmd, $i),
                'update_role_transform' => $this->assertUpdateRoleTransform($cmd, $i),
                default => throw ValidationException::withMessages([
                    'commands' => "Unsupported command type: {$type}",
                ]),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $cmd
     */
    private function assertUpdateTextContent(array $cmd, int $i): void
    {
        $role = (string) ($cmd['role'] ?? '');
        if (! in_array($role, StudioDocumentSyncRoleFinder::TEXT_CONTENT_ROLES, true)) {
            throw ValidationException::withMessages(['commands' => "Command #{$i}: invalid role for update_text_content."]);
        }
        $text = $cmd['text'] ?? null;
        if (! is_string($text) || mb_strlen($text) > 16_384) {
            throw ValidationException::withMessages(['commands' => "Command #{$i}: text must be a string (max 16384 chars)."]);
        }
    }

    /**
     * @param  array<string, mixed>  $cmd
     */
    private function assertUpdateLayerVisibility(array $cmd, int $i): void
    {
        $role = (string) ($cmd['role'] ?? '');
        if (! in_array($role, StudioDocumentSyncRoleFinder::VISIBILITY_ROLES, true)) {
            throw ValidationException::withMessages(['commands' => "Command #{$i}: invalid role for update_layer_visibility."]);
        }
        if (! isset($cmd['visible']) || ! is_bool($cmd['visible'])) {
            throw ValidationException::withMessages(['commands' => "Command #{$i}: visible must be a boolean."]);
        }
    }

    /**
     * @param  array<string, mixed>  $cmd
     */
    private function assertUpdateTextAlignment(array $cmd, int $i): void
    {
        $role = (string) ($cmd['role'] ?? '');
        if (! in_array($role, StudioDocumentSyncRoleFinder::TEXT_ALIGN_ROLES, true)) {
            throw ValidationException::withMessages(['commands' => "Command #{$i}: invalid role for update_text_alignment."]);
        }
        $a = (string) ($cmd['alignment'] ?? '');
        if (! in_array($a, ['left', 'center', 'right'], true)) {
            throw ValidationException::withMessages(['commands' => "Command #{$i}: invalid alignment."]);
        }
    }

    /**
     * @param  array<string, mixed>  $cmd
     */
    private function assertUpdateRoleTransform(array $cmd, int $i): void
    {
        $role = (string) ($cmd['role'] ?? '');
        if (! in_array($role, StudioDocumentSyncRoleFinder::TRANSFORM_ROLES, true)) {
            throw ValidationException::withMessages(['commands' => "Command #{$i}: invalid role for update_role_transform."]);
        }
        foreach (['x', 'y'] as $k) {
            if (! isset($cmd[$k]) || ! is_numeric($cmd[$k])) {
                throw ValidationException::withMessages(['commands' => "Command #{$i}: {$k} is required and numeric."]);
            }
        }
        foreach (['width', 'height'] as $k) {
            if (array_key_exists($k, $cmd) && $cmd[$k] !== null && ! is_numeric($cmd[$k])) {
                throw ValidationException::withMessages(['commands' => "Command #{$i}: {$k} must be numeric when set."]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $sourceDocument
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $cmd
     * @return array<string, mixed>|null
     */
    private function applyUpdateTextContent(array $sourceDocument, array $document, array $cmd): ?array
    {
        $role = (string) $cmd['role'];
        $text = (string) $cmd['text'];
        $sid = $this->roleFinder->findTextLayerIdForRole($sourceDocument, $role);
        if ($sid === null) {
            return null;
        }
        $tid = $this->layerResolver->resolveTargetLayerId($sourceDocument, $document, $sid);
        if ($tid === null) {
            return null;
        }

        return $this->applyPatchToDocument($document, $tid, ['content' => $text], 'text');
    }

    /**
     * @param  array<string, mixed>  $sourceDocument
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $cmd
     * @return array<string, mixed>|null
     */
    private function applyUpdateLayerVisibility(array $sourceDocument, array $document, array $cmd): ?array
    {
        $role = (string) $cmd['role'];
        $visible = (bool) $cmd['visible'];
        $sourceIds = $this->roleFinder->findLayerIdsForVisibilityRole($sourceDocument, $role);
        if ($sourceIds === []) {
            return null;
        }
        $doc = $document;
        foreach ($sourceIds as $sid) {
            $tid = $this->layerResolver->resolveTargetLayerId($sourceDocument, $doc, $sid);
            if ($tid === null) {
                return null;
            }
            $type = $this->layerTypeForId($sourceDocument, $sid);
            if ($type === null) {
                return null;
            }
            $next = $this->applyPatchToDocument($doc, $tid, ['visible' => $visible], $type);
            if ($next === null) {
                return null;
            }
            $doc = $next;
        }

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $sourceDocument
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $cmd
     * @return array<string, mixed>|null
     */
    private function applyUpdateTextAlignment(array $sourceDocument, array $document, array $cmd): ?array
    {
        $role = (string) $cmd['role'];
        $alignment = (string) $cmd['alignment'];
        $sid = $this->roleFinder->findTextLayerIdForAlignmentRole($sourceDocument, $role);
        if ($sid === null) {
            return null;
        }
        $tid = $this->layerResolver->resolveTargetLayerId($sourceDocument, $document, $sid);
        if ($tid === null) {
            return null;
        }

        return $this->applyPatchToDocument($document, $tid, [
            'style' => ['textAlign' => $alignment],
        ], 'text');
    }

    /**
     * @param  array<string, mixed>  $sourceDocument
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $cmd
     * @return array<string, mixed>|null
     */
    private function applyUpdateRoleTransform(array $sourceDocument, array $document, array $cmd): ?array
    {
        $role = (string) $cmd['role'];
        $sid = $this->roleFinder->findLayerIdForTransformRole($sourceDocument, $role);
        if ($sid === null) {
            return null;
        }
        $tid = $this->layerResolver->resolveTargetLayerId($sourceDocument, $document, $sid);
        if ($tid === null) {
            return null;
        }
        $type = $this->layerTypeForId($sourceDocument, $sid);
        if ($type === null) {
            return null;
        }

        $x = (float) $cmd['x'];
        $y = (float) $cmd['y'];
        if (! is_finite($x) || ! is_finite($y)) {
            return null;
        }
        $t = ['x' => $x, 'y' => $y];
        if (isset($cmd['width']) && is_numeric($cmd['width'])) {
            $w = (float) $cmd['width'];
            if ($w >= 1 && $w <= 100_000 && is_finite($w)) {
                $t['width'] = $w;
            }
        }
        if (isset($cmd['height']) && is_numeric($cmd['height'])) {
            $h = (float) $cmd['height'];
            if ($h >= 1 && $h <= 100_000 && is_finite($h)) {
                $t['height'] = $h;
            }
        }

        return $this->applyPatchToDocument($document, $tid, ['transform' => $t], $type);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function layerTypeForId(array $document, string $layerId): ?string
    {
        $layers = $document['layers'] ?? [];
        if (! is_array($layers)) {
            return null;
        }
        foreach ($layers as $layer) {
            if (is_array($layer) && (string) ($layer['id'] ?? '') === $layerId) {
                return (string) ($layer['type'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>|null
     */
    private function applyPatchToDocument(array $document, string $targetLayerId, array $patch, string $expectedType): ?array
    {
        $layers = $document['layers'] ?? null;
        if (! is_array($layers)) {
            return null;
        }

        $target = null;
        foreach ($layers as $layer) {
            if (is_array($layer) && (string) ($layer['id'] ?? '') === $targetLayerId) {
                $target = $layer;
                break;
            }
        }
        if (! is_array($target)) {
            return null;
        }
        if ((string) ($target['type'] ?? '') !== $expectedType) {
            return null;
        }

        $type = (string) ($target['type'] ?? '');
        $allowed = match ($type) {
            'text' => ['content', 'visible', 'locked', 'transform', 'style', 'name'],
            'image' => ['visible', 'locked', 'transform', 'name', 'fit'],
            'fill' => [
                'visible', 'locked', 'transform', 'name', 'fillKind', 'color',
                'gradientStartColor', 'gradientEndColor', 'gradientAngleDeg', 'borderRadius',
                'kind', 'fillRole', 'textBoostStyle', 'textBoostColor', 'textBoostOpacity', 'textBoostSource',
                'borderStrokeWidth', 'borderStrokeColor',
            ],
            'generative_image' => ['visible', 'locked', 'transform', 'name', 'fit'],
            default => ['visible', 'locked', 'transform', 'name'],
        };

        $filtered = array_intersect_key($patch, array_flip($allowed));
        if ($filtered === []) {
            return null;
        }

        $outLayers = [];
        $found = false;
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                $outLayers[] = $layer;

                continue;
            }
            if ((string) ($layer['id'] ?? '') !== $targetLayerId) {
                $outLayers[] = $layer;

                continue;
            }
            $found = true;
            $merged = $layer;
            foreach ($filtered as $k => $v) {
                if ($k === 'style' && is_array($v) && is_array($merged['style'] ?? null)) {
                    $merged['style'] = array_merge($merged['style'], $v);
                } elseif ($k === 'transform' && is_array($v) && is_array($merged['transform'] ?? null)) {
                    $merged['transform'] = array_merge($merged['transform'], $v);
                } else {
                    $merged[$k] = $v;
                }
            }
            $outLayers[] = $merged;
        }

        if (! $found) {
            return null;
        }

        $document['layers'] = $outLayers;
        $document['updated_at'] = now()->toIso8601String();

        return $document;
    }
}
