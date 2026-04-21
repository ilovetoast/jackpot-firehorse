<?php

namespace App\Services\Studio;

use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\User;
use App\Support\StudioApplySkipReason;
use App\Support\StudioCrossCompositionLayerResolver;
use App\Support\StudioDocumentSyncRoleFinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * @param  list<int>|null  $onlySiblingCompositionIds  When non-null, only these sibling compositions are considered (must each be a variant of {@code $set} other than the source). Order is preserved.
     * @return array{
     *     updated: array<int, string>,
     *     skipped: array<int, array{composition_id: string, reason: string, reason_code: string, command_index?: int, command_type?: string}>,
     *     skipped_by_reason: array<string, int>,
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
        ?array $onlySiblingCompositionIds = null,
    ): array {
        return $this->runApplyAcrossSiblings($set, $user, $sourceCompositionId, $commands, true, $onlySiblingCompositionIds);
    }

    /**
     * Same validation and mapping rules as {@see applyToAllVariants} without persisting — for UX preview counts.
     *
     * @param  array<int, array<string, mixed>>  $commands
     * @param  list<int>|null  $onlySiblingCompositionIds  Same semantics as {@see applyToAllVariants}.
     * @return array{
     *     skipped: array<int, array{composition_id: string, reason: string, reason_code: string, command_index?: int, command_type?: string}>,
     *     skipped_by_reason: array<string, int>,
     *     sibling_compositions_targeted: int,
     *     sibling_compositions_eligible: int,
     *     sibling_compositions_would_skip: int,
     *     commands_considered: int
     * }
     */
    public function previewApplyToAllVariants(
        CreativeSet $set,
        User $user,
        int $sourceCompositionId,
        array $commands,
        ?array $onlySiblingCompositionIds = null,
    ): array {
        $out = $this->runApplyAcrossSiblings($set, $user, $sourceCompositionId, $commands, false, $onlySiblingCompositionIds);

        return [
            'skipped' => $out['skipped'],
            'skipped_by_reason' => $out['skipped_by_reason'],
            'sibling_compositions_targeted' => $out['sibling_compositions_targeted'],
            'sibling_compositions_eligible' => count($out['updated']),
            'sibling_compositions_would_skip' => count($out['skipped']),
            'commands_considered' => $out['commands_applied'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @return array{
     *     updated: array<int, string>,
     *     skipped: array<int, array{composition_id: string, reason: string, reason_code: string, command_index?: int, command_type?: string}>,
     *     skipped_by_reason: array<string, int>,
     *     sibling_compositions_targeted: int,
     *     sibling_compositions_updated: int,
     *     commands_applied: int
     * }
     */
    /**
     * @param  list<int>|null  $onlySiblingCompositionIds
     */
    private function runApplyAcrossSiblings(
        CreativeSet $set,
        User $user,
        int $sourceCompositionId,
        array $commands,
        bool $persist,
        ?array $onlySiblingCompositionIds = null,
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

        $allSiblings = array_values(array_filter($variantCompositionIds, static fn (int $cid) => $cid !== $sourceCompositionId));
        $siblingMemberSet = array_fill_keys($allSiblings, true);

        if ($onlySiblingCompositionIds !== null) {
            $siblings = [];
            $seen = [];
            foreach ($onlySiblingCompositionIds as $cid) {
                $i = (int) $cid;
                if ($i === $sourceCompositionId || $i < 1) {
                    continue;
                }
                if (! isset($siblingMemberSet[$i])) {
                    continue;
                }
                if (isset($seen[$i])) {
                    continue;
                }
                $seen[$i] = true;
                $siblings[] = $i;
            }
        } else {
            $siblings = $allSiblings;
        }

        $targeted = count($siblings);

        foreach ($siblings as $cid) {
            $composition = Composition::query()
                ->where('id', $cid)
                ->where('tenant_id', $set->tenant_id)
                ->where('brand_id', $set->brand_id)
                ->visibleToUser($user)
                ->first();
            if (! $composition) {
                $this->pushSkip(
                    $skipped,
                    (string) $cid,
                    StudioApplySkipReason::COMPOSITION_NOT_FOUND_OR_INACCESSIBLE,
                    'That version’s document is not available (missing composition or no access).',
                    null,
                    null,
                    $set->id,
                );

                continue;
            }

            $doc = is_array($composition->document_json) ? $composition->document_json : [];
            $apply = $this->applyAllCommandsToSiblingDocument($sourceDoc, $doc, $commands);
            if (! $apply['ok']) {
                $f = $apply['failure'];
                $this->pushSkip(
                    $skipped,
                    (string) $cid,
                    $f['reason_code'],
                    $f['reason'],
                    $f['command_index'] ?? null,
                    $f['command_type'] ?? null,
                    $set->id,
                );

                continue;
            }

            if ($persist) {
                $finalDoc = $apply['document'];
                DB::transaction(function () use ($composition, $finalDoc): void {
                    $composition->document_json = $finalDoc;
                    $composition->save();
                });
            }
            $updated[] = (string) $cid;
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'skipped_by_reason' => $this->aggregateSkippedByReason($skipped),
            'sibling_compositions_targeted' => $targeted,
            'sibling_compositions_updated' => count($updated),
            'commands_applied' => count($commands),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @return array{ok: bool, document: array<string, mixed>, failure?: array{reason_code: string, reason: string, command_index: int, command_type: string}}
     */
    private function applyAllCommandsToSiblingDocument(array $sourceDoc, array $document, array $commands): array
    {
        $doc = $document;
        foreach ($commands as $i => $cmd) {
            if (! is_array($cmd)) {
                return [
                    'ok' => false,
                    'document' => $doc,
                    'failure' => [
                        'reason_code' => StudioApplySkipReason::INVALID_COMMAND_PAYLOAD,
                        'reason' => "Command #{$i} is not a valid object.",
                        'command_index' => $i,
                        'command_type' => '',
                    ],
                ];
            }
            $type = (string) ($cmd['type'] ?? '');
            $outcome = match ($type) {
                'update_text_content' => $this->applyUpdateTextContentOutcome($sourceDoc, $doc, $cmd),
                'update_layer_visibility' => $this->applyUpdateLayerVisibilityOutcome($sourceDoc, $doc, $cmd),
                'update_text_alignment' => $this->applyUpdateTextAlignmentOutcome($sourceDoc, $doc, $cmd),
                'update_role_transform' => $this->applyUpdateRoleTransformOutcome($sourceDoc, $doc, $cmd),
                default => [
                    'document' => null,
                    'failure_code' => StudioApplySkipReason::UNSUPPORTED_COMMAND_TYPE,
                    'failure_detail' => "Unsupported command type: {$type}",
                ],
            };
            if (($outcome['failure_code'] ?? null) !== null) {
                return [
                    'ok' => false,
                    'document' => $doc,
                    'failure' => [
                        'reason_code' => (string) $outcome['failure_code'],
                        'reason' => (string) $outcome['failure_detail'],
                        'command_index' => $i,
                        'command_type' => $type,
                    ],
                ];
            }
            $doc = $outcome['document'];
        }

        return ['ok' => true, 'document' => $doc];
    }

    /**
     * @param  array<int, array{composition_id: string, reason: string, reason_code: string, command_index?: int, command_type?: string}>  $skipped
     * @return array<string, int>
     */
    private function aggregateSkippedByReason(array $skipped): array
    {
        $map = [];
        foreach ($skipped as $row) {
            $code = (string) ($row['reason_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $map[$code] = ($map[$code] ?? 0) + 1;
        }

        return $map;
    }

    /**
     * @param  array<int, array{composition_id: string, reason: string, reason_code: string, command_index?: int, command_type?: string}>  $skipped
     */
    private function pushSkip(
        array &$skipped,
        string $compositionId,
        string $reasonCode,
        string $reason,
        ?int $commandIndex,
        ?string $commandType,
        int $creativeSetId,
    ): void {
        $row = [
            'composition_id' => $compositionId,
            'reason' => $reason,
            'reason_code' => $reasonCode,
        ];
        if ($commandIndex !== null) {
            $row['command_index'] = $commandIndex;
        }
        if ($commandType !== null && $commandType !== '') {
            $row['command_type'] = $commandType;
        }
        $skipped[] = $row;

        Log::info('studio.creative_set.apply.skip', [
            'creative_set_id' => $creativeSetId,
            'composition_id' => $compositionId,
            'reason_code' => $reasonCode,
            'command_index' => $commandIndex,
            'command_type' => $commandType,
        ]);
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
     * @return array{document: array<string, mixed>|null, failure_code: string|null, failure_detail: string|null}
     */
    private function applyUpdateTextContentOutcome(array $sourceDocument, array $document, array $cmd): array
    {
        $role = (string) $cmd['role'];
        $text = (string) $cmd['text'];
        $sid = $this->roleFinder->findTextLayerIdForRole($sourceDocument, $role);
        if ($sid === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::SOURCE_ROLE_LAYER_NOT_FOUND,
                'failure_detail' => "This layout has no recognizable {$role} text layer to drive sync (add a template role or rename the layer).",
            ];
        }
        $tid = $this->layerResolver->resolveTargetLayerId($sourceDocument, $document, $sid);
        if ($tid === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_LAYER_MAPPING_FAILED,
                'failure_detail' => "Could not match the source {$role} layer to a layer in this version (stack order or names may differ).",
            ];
        }
        $tgtType = $this->layerTypeForId($document, $tid);
        if ($tgtType !== 'text') {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_LAYER_TYPE_MISMATCH,
                'failure_detail' => 'The mapped target layer is not a text layer, so headline-style text sync was skipped.',
            ];
        }
        $next = $this->applyPatchToDocument($document, $tid, ['content' => $text], 'text');
        if ($next === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_PATCH_REJECTED,
                'failure_detail' => 'Could not merge text content into the target layer.',
            ];
        }

        return ['document' => $next, 'failure_code' => null, 'failure_detail' => null];
    }

    /**
     * @param  array<string, mixed>  $sourceDocument
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $cmd
     * @return array{document: array<string, mixed>|null, failure_code: string|null, failure_detail: string|null}
     */
    private function applyUpdateLayerVisibilityOutcome(array $sourceDocument, array $document, array $cmd): array
    {
        $role = (string) $cmd['role'];
        $visible = (bool) $cmd['visible'];
        $sourceIds = $this->roleFinder->findLayerIdsForVisibilityRole($sourceDocument, $role);
        if ($sourceIds === []) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::SOURCE_ROLE_LAYER_NOT_FOUND,
                'failure_detail' => $role === 'cta'
                    ? 'No CTA group or CTA-shaped layers were found on the source layout.'
                    : "No layer for sync role '{$role}' was found on the source layout.",
            ];
        }
        $doc = $document;
        foreach ($sourceIds as $sid) {
            $tid = $this->layerResolver->resolveTargetLayerId($sourceDocument, $doc, $sid);
            if ($tid === null) {
                return [
                    'document' => null,
                    'failure_code' => StudioApplySkipReason::TARGET_LAYER_MAPPING_FAILED,
                    'failure_detail' => "Could not map a {$role} layer from the source stack onto this version (CTA groups must align across versions).",
                ];
            }
            $type = $this->layerTypeForId($sourceDocument, $sid);
            if ($type === null) {
                return [
                    'document' => null,
                    'failure_code' => StudioApplySkipReason::INVALID_DOCUMENT_STRUCTURE,
                    'failure_detail' => 'Source document layers are malformed.',
                ];
            }
            $tgtType = $this->layerTypeForId($doc, $tid);
            if ($tgtType !== $type) {
                return [
                    'document' => null,
                    'failure_code' => StudioApplySkipReason::TARGET_LAYER_TYPE_MISMATCH,
                    'failure_detail' => "Target layer type does not match the source {$role} layer for visibility sync.",
                ];
            }
            $next = $this->applyPatchToDocument($doc, $tid, ['visible' => $visible], $type);
            if ($next === null) {
                return [
                    'document' => null,
                    'failure_code' => StudioApplySkipReason::TARGET_PATCH_REJECTED,
                    'failure_detail' => 'Could not apply visibility to the mapped target layer.',
                ];
            }
            $doc = $next;
        }

        return ['document' => $doc, 'failure_code' => null, 'failure_detail' => null];
    }

    /**
     * @param  array<string, mixed>  $sourceDocument
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $cmd
     * @return array{document: array<string, mixed>|null, failure_code: string|null, failure_detail: string|null}
     */
    private function applyUpdateTextAlignmentOutcome(array $sourceDocument, array $document, array $cmd): array
    {
        $role = (string) $cmd['role'];
        $alignment = (string) $cmd['alignment'];
        $sid = $this->roleFinder->findTextLayerIdForAlignmentRole($sourceDocument, $role);
        if ($sid === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::SOURCE_ROLE_LAYER_NOT_FOUND,
                'failure_detail' => "No text layer for sync role '{$role}' on the source layout.",
            ];
        }
        $tid = $this->layerResolver->resolveTargetLayerId($sourceDocument, $document, $sid);
        if ($tid === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_LAYER_MAPPING_FAILED,
                'failure_detail' => "Could not match the source {$role} text layer to this version.",
            ];
        }
        if ($this->layerTypeForId($document, $tid) !== 'text') {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_LAYER_TYPE_MISMATCH,
                'failure_detail' => 'Mapped target is not a text layer; alignment sync was skipped.',
            ];
        }
        $next = $this->applyPatchToDocument($document, $tid, [
            'style' => ['textAlign' => $alignment],
        ], 'text');
        if ($next === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_PATCH_REJECTED,
                'failure_detail' => 'Could not merge text alignment on the target layer.',
            ];
        }

        return ['document' => $next, 'failure_code' => null, 'failure_detail' => null];
    }

    /**
     * @param  array<string, mixed>  $sourceDocument
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $cmd
     * @return array{document: array<string, mixed>|null, failure_code: string|null, failure_detail: string|null}
     */
    private function applyUpdateRoleTransformOutcome(array $sourceDocument, array $document, array $cmd): array
    {
        $role = (string) $cmd['role'];
        $sid = $this->roleFinder->findLayerIdForTransformRole($sourceDocument, $role);
        if ($sid === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::SOURCE_ROLE_LAYER_NOT_FOUND,
                'failure_detail' => "No layer for sync role '{$role}' was found on the source layout.",
            ];
        }
        $tid = $this->layerResolver->resolveTargetLayerId($sourceDocument, $document, $sid);
        if ($tid === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_LAYER_MAPPING_FAILED,
                'failure_detail' => "Could not match the source {$role} layer to a layer in this version.",
            ];
        }
        $type = $this->layerTypeForId($sourceDocument, $sid);
        if ($type === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::INVALID_DOCUMENT_STRUCTURE,
                'failure_detail' => 'Source document layers are malformed.',
            ];
        }
        if ($this->layerTypeForId($document, $tid) !== $type) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_LAYER_TYPE_MISMATCH,
                'failure_detail' => 'Mapped target layer type does not match the source layer for transform sync.',
            ];
        }

        $x = (float) $cmd['x'];
        $y = (float) $cmd['y'];
        if (! is_finite($x) || ! is_finite($y)) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::INVALID_COMMAND_PAYLOAD,
                'failure_detail' => 'Transform coordinates are not finite numbers.',
            ];
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

        $next = $this->applyPatchToDocument($document, $tid, ['transform' => $t], $type);
        if ($next === null) {
            return [
                'document' => null,
                'failure_code' => StudioApplySkipReason::TARGET_PATCH_REJECTED,
                'failure_detail' => 'Could not merge transform into the mapped target layer.',
            ];
        }

        return ['document' => $next, 'failure_code' => null, 'failure_detail' => null];
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
