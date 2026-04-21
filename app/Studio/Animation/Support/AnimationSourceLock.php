<?php

namespace App\Studio\Animation\Support;

use App\Models\CompositionVersion;

final class AnimationSourceLock
{
    public const SETTINGS_KEY = 'source_lock';

    public const LOCKED_DOCUMENT_MAX_BYTES = 512_000;

    /**
     * @param  array<string, mixed>|null  $clientDocumentJson
     * @return array{
     *   source_composition_version_id: int|null,
     *   source_document_revision_hash: string|null,
     *   settings_fragment: array<string, mixed>
     * }
     */
    public static function resolveForSubmission(
        int $compositionId,
        ?int $sourceCompositionVersionId,
        ?array $clientDocumentJson,
    ): array {
        $lockedDocument = null;
        $hash = null;
        $versionId = null;

        if ($sourceCompositionVersionId !== null) {
            $version = CompositionVersion::query()
                ->whereKey($sourceCompositionVersionId)
                ->where('composition_id', $compositionId)
                ->first();
            if (! $version) {
                throw new \InvalidArgumentException('Unknown composition version for this composition.');
            }
            $lockedDocument = is_array($version->document_json) ? $version->document_json : [];
            $hash = self::hashDocument($lockedDocument);

            if ($clientDocumentJson !== null) {
                $clientHash = self::hashDocument($clientDocumentJson);
                if ($clientHash !== $hash) {
                    throw new \InvalidArgumentException('Submitted document_json does not match the selected composition version.');
                }
            }
            $versionId = (int) $version->id;
        } elseif ($clientDocumentJson !== null) {
            $lockedDocument = $clientDocumentJson;
            $hash = self::hashDocument($lockedDocument);
        }

        $serialized = $lockedDocument !== null
            ? json_encode($lockedDocument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        $omitDocument = $serialized !== null && strlen($serialized) > self::LOCKED_DOCUMENT_MAX_BYTES;

        $settingsFragment = [
            self::SETTINGS_KEY => array_filter([
                'source_composition_version_id' => $versionId,
                'source_document_revision_hash' => $hash,
                'locked_at' => now()->toIso8601String(),
                'locked_document_json' => $omitDocument ? null : $lockedDocument,
                'locked_document_omitted' => $omitDocument,
                'locked_document_byte_length' => $serialized !== null ? strlen($serialized) : null,
            ], static fn ($v) => $v !== null),
        ];

        return [
            'source_composition_version_id' => $versionId,
            'source_document_revision_hash' => $hash,
            'settings_fragment' => $settingsFragment,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    public static function hashDocument(?array $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $canonical = self::ksortDeep($document);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function ksortDeep(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                /** @var array<string, mixed> $v */
                $data[$k] = self::ksortDeep($v);
            }
        }

        return $data;
    }
}
