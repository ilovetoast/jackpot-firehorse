<?php

namespace App\Support;

/**
 * Locates layers in a Studio editor {@code document_json} for safe cross-version sync.
 * Prefers {@code studioSyncRole} when present (set during template materialization); falls back to name heuristics.
 *
 * Official cross-version sync roles (narrow Phase 3 allowlist):
 * - headline — primary headline text
 * - subheadline — secondary headline / supporting line
 * - cta — call-to-action text and/or CTA button fill group ({@code fillRole: cta_button})
 * - logo — brand mark (typically image)
 * - badge — corner / promo badge (image or fill)
 * - disclaimer — legal / fine print text
 */
final class StudioDocumentSyncRoleFinder
{
    /** @var list<string> */
    public const TEXT_CONTENT_ROLES = ['headline', 'subheadline', 'cta', 'disclaimer'];

    /** @var list<string> */
    public const VISIBILITY_ROLES = ['logo', 'badge', 'disclaimer', 'cta'];

    /** @var list<string> */
    public const TEXT_ALIGN_ROLES = ['headline', 'subheadline', 'cta'];

    /** @var list<string> */
    public const TRANSFORM_ROLES = ['logo', 'badge', 'cta', 'disclaimer', 'headline', 'subheadline'];

    /**
     * @param  array<string, mixed>  $document
     */
    public function findTextLayerIdForRole(array $document, string $role): ?string
    {
        if (! in_array($role, self::TEXT_CONTENT_ROLES, true)) {
            return null;
        }
        $layers = $document['layers'] ?? [];
        if (! is_array($layers)) {
            return null;
        }

        $bySync = $this->firstLayerIdMatchingSyncRole($layers, $role, ['text']);
        if ($bySync !== null) {
            return $bySync;
        }

        foreach ($layers as $layer) {
            if (! is_array($layer) || ($layer['type'] ?? '') !== 'text') {
                continue;
            }
            $inferred = $this->inferTextRole($layer);
            if ($inferred === $role) {
                return (string) ($layer['id'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * One or more layer ids (e.g. CTA button fill + CTA text share a group).
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public function findLayerIdsForVisibilityRole(array $document, string $role): array
    {
        if (! in_array($role, self::VISIBILITY_ROLES, true)) {
            return [];
        }
        $layers = $document['layers'] ?? [];
        if (! is_array($layers)) {
            return [];
        }

        if ($role === 'cta') {
            $anchor = $this->findCtaAnchorLayer($layers);
            if ($anchor === null) {
                return [];
            }
            $gid = isset($anchor['groupId']) && is_string($anchor['groupId']) && $anchor['groupId'] !== ''
                ? $anchor['groupId']
                : null;
            if ($gid !== null) {
                return $this->collectLayerIdsInGroup($layers, $gid);
            }

            return [(string) ($anchor['id'] ?? '')];
        }

        $bySync = $this->firstLayerIdMatchingSyncRole($layers, $role, ['text', 'image', 'fill', 'generative_image']);
        if ($bySync !== null) {
            return [$bySync];
        }

        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $type = (string) ($layer['type'] ?? '');
            if ($role === 'logo' && $type === 'image' && $this->nameMatches($layer, '/\blogo\b/i')) {
                return [(string) ($layer['id'] ?? '')];
            }
            if ($role === 'badge' && ($type === 'image' || $type === 'fill') && $this->nameMatches($layer, '/\bbadge\b/i')) {
                return [(string) ($layer['id'] ?? '')];
            }
            if ($role === 'disclaimer' && $type === 'text' && $this->nameMatches($layer, '/disclaimer|legal|fine\s*print/i')) {
                return [(string) ($layer['id'] ?? '')];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function findTextLayerIdForAlignmentRole(array $document, string $role): ?string
    {
        if (! in_array($role, self::TEXT_ALIGN_ROLES, true)) {
            return null;
        }

        return $this->findTextLayerIdForRole($document, $role);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function findLayerIdForTransformRole(array $document, string $role): ?string
    {
        if (! in_array($role, self::TRANSFORM_ROLES, true)) {
            return null;
        }
        $layers = $document['layers'] ?? [];
        if (! is_array($layers)) {
            return null;
        }

        $types = match ($role) {
            'logo', 'badge' => ['image', 'fill'],
            'cta', 'disclaimer', 'headline', 'subheadline' => ['text', 'fill', 'image'],
            default => ['text'],
        };

        $bySync = $this->firstLayerIdMatchingSyncRole($layers, $role, $types);
        if ($bySync !== null) {
            return $bySync;
        }

        if ($role === 'logo') {
            foreach ($layers as $layer) {
                if (! is_array($layer) || ($layer['type'] ?? '') !== 'image') {
                    continue;
                }
                if ($this->nameMatches($layer, '/\blogo\b/i')) {
                    return (string) ($layer['id'] ?? '') ?: null;
                }
            }
        }
        if ($role === 'badge') {
            foreach ($layers as $layer) {
                if (! is_array($layer)) {
                    continue;
                }
                $t = (string) ($layer['type'] ?? '');
                if (($t === 'image' || $t === 'fill') && $this->nameMatches($layer, '/\bbadge\b/i')) {
                    return (string) ($layer['id'] ?? '') ?: null;
                }
            }
        }

        $textId = $this->findTextLayerIdForRole($document, $role);

        return $textId;
    }

    /**
     * @param  array<int, mixed>  $layers
     * @param  list<string>  $types
     */
    private function firstLayerIdMatchingSyncRole(array $layers, string $role, array $types): ?string
    {
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (! in_array((string) ($layer['type'] ?? ''), $types, true)) {
                continue;
            }
            $sync = isset($layer['studioSyncRole']) ? trim((string) $layer['studioSyncRole']) : '';
            if ($sync !== '' && strtolower($sync) === strtolower($role)) {
                return (string) ($layer['id'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $layers
     * @return array<string, mixed>|null
     */
    private function findCtaAnchorLayer(array $layers): ?array
    {
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            $sync = isset($layer['studioSyncRole']) ? strtolower(trim((string) $layer['studioSyncRole'])) : '';
            if ($sync === 'cta') {
                return $layer;
            }
        }
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (($layer['type'] ?? '') === 'fill' && (($layer['fillRole'] ?? null) === 'cta_button')) {
                return $layer;
            }
        }
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (($layer['type'] ?? '') === 'text' && $this->nameMatches($layer, '/(^cta$|\bcta\b)/i')) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $layers
     * @return list<string>
     */
    private function collectLayerIdsInGroup(array $layers, string $groupId): array
    {
        $ids = [];
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (($layer['groupId'] ?? null) === $groupId) {
                $id = (string) ($layer['id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function inferTextRole(array $layer): ?string
    {
        $name = strtolower(trim((string) ($layer['name'] ?? '')));
        if (str_contains($name, 'headline')) {
            return 'headline';
        }
        if (str_contains($name, 'subhead') || str_contains($name, 'sub-head')) {
            return 'subheadline';
        }
        if (preg_match('/(^cta$|\bcta\b)/i', $name) === 1) {
            return 'cta';
        }
        if (preg_match('/disclaimer|legal|fine\s*print/i', $name) === 1) {
            return 'disclaimer';
        }
        $fs = 0;
        if (isset($layer['style']) && is_array($layer['style']) && isset($layer['style']['fontSize']) && is_numeric($layer['style']['fontSize'])) {
            $fs = (int) $layer['style']['fontSize'];
        }
        if ($fs > 32) {
            return 'headline';
        }
        if ($fs > 0 && $fs <= 18) {
            return 'disclaimer';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function nameMatches(array $layer, string $pattern): bool
    {
        $name = (string) ($layer['name'] ?? '');

        return preg_match($pattern, $name) === 1;
    }
}
