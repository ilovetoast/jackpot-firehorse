<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Brand-scoped listing of approved metadata values (excluding tags) and purge from all assets.
 */
class BrandMetadataValueManagementController extends Controller
{
    private const SUMMARY_ROW_CAP = 2500;

    public function summary(Request $request, Brand $brand): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if ((int) $brand->tenant_id !== (int) $tenant->id) {
            return response()->json(['message' => 'Brand not found'], 404);
        }
        $this->authorize('view', $brand);

        $rows = DB::table('asset_metadata')
            ->join('assets', 'assets.id', '=', 'asset_metadata.asset_id')
            ->join('metadata_fields', 'metadata_fields.id', '=', 'asset_metadata.metadata_field_id')
            ->where('assets.brand_id', $brand->id)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('asset_metadata.approved_at')
            ->where('metadata_fields.key', '!=', 'tags')
            ->where('metadata_fields.scope', 'tenant')
            ->where('metadata_fields.tenant_id', $tenant->id)
            ->whereNotNull('asset_metadata.value_json')
            ->select([
                'metadata_fields.id as field_id',
                'metadata_fields.key as field_key',
                'metadata_fields.system_label as field_label',
                'metadata_fields.type as field_type',
                'asset_metadata.value_json',
                DB::raw('COUNT(DISTINCT asset_metadata.asset_id) as asset_count'),
            ])
            ->groupBy(
                'metadata_fields.id',
                'metadata_fields.key',
                'metadata_fields.system_label',
                'metadata_fields.type',
                'asset_metadata.value_json'
            )
            ->orderBy('metadata_fields.key')
            ->orderByDesc('asset_count')
            ->limit(self::SUMMARY_ROW_CAP)
            ->get();

        $byField = [];
        foreach ($rows as $r) {
            $key = $r->field_key;
            if (! isset($byField[$key])) {
                $byField[$key] = [
                    'field_id' => (int) $r->field_id,
                    'field_key' => $r->field_key,
                    'field_label' => $r->field_label ?? $r->field_key,
                    'field_type' => $r->field_type ?? 'text',
                    'values' => [],
                ];
            }
            $byField[$key]['values'][] = [
                'value_json' => $r->value_json,
                'display_value' => self::displayValueFromJson($r->value_json),
                'asset_count' => (int) $r->asset_count,
            ];
        }

        return response()->json([
            'fields' => array_values($byField),
            'meta' => [
                'row_cap' => self::SUMMARY_ROW_CAP,
                'truncated' => $rows->count() >= self::SUMMARY_ROW_CAP,
            ],
        ]);
    }

    public function purge(Request $request, Brand $brand): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if ((int) $brand->tenant_id !== (int) $tenant->id) {
            return response()->json(['message' => 'Brand not found'], 404);
        }
        $this->authorize('update', $brand);

        if (! $this->userCanPurgeMetadataValues($user, $tenant)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'field_key' => 'required|string|max:128',
            'value_json' => 'required|string|max:8192',
        ]);

        if ($validated['field_key'] === 'tags') {
            return response()->json(['message' => 'Use tag management to remove tags'], 422);
        }

        $field = $this->resolveMetadataField($validated['field_key'], $tenant->id);
        if (! $field) {
            return response()->json(['message' => 'Unknown metadata field'], 404);
        }

        if (($field->scope ?? '') !== 'tenant' || (int) ($field->tenant_id ?? 0) !== (int) $tenant->id) {
            return response()->json(['message' => 'System fields are not managed from this screen'], 422);
        }

        $canonical = self::canonicalJsonForMatch($validated['value_json']);
        if ($canonical === null) {
            return response()->json(['message' => 'Invalid value_json'], 422);
        }

        $idsQuery = DB::table('asset_metadata')
            ->join('assets', 'assets.id', '=', 'asset_metadata.asset_id')
            ->where('assets.brand_id', $brand->id)
            ->whereNull('assets.deleted_at')
            ->where('asset_metadata.metadata_field_id', $field->id)
            ->whereNotNull('asset_metadata.approved_at');

        // JSON column: compare as JSON so binding matches MySQL’s stored form (avoids string vs JSON mismatches).
        if (DB::connection()->getDriverName() === 'mysql') {
            $idsQuery->whereRaw('asset_metadata.value_json = CAST(? AS JSON)', [$canonical]);
        } else {
            $idsQuery->where('asset_metadata.value_json', $canonical);
        }

        $ids = $idsQuery->pluck('asset_metadata.id');

        $deleted = 0;
        foreach ($ids->chunk(500) as $chunk) {
            $deleted += DB::table('asset_metadata')->whereIn('id', $chunk->all())->delete();
        }

        return response()->json([
            'message' => 'Value removed from assets',
            'field_key' => $validated['field_key'],
            'rows_deleted' => $deleted,
        ]);
    }

    private function userCanPurgeMetadataValues($user, $tenant): bool
    {
        return $user->hasPermissionForTenant($tenant, 'metadata.bulk_edit')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage')
            || $user->hasPermissionForTenant($tenant, 'metadata.fields.values.manage');
    }

    /**
     * @return object{id: int}|null
     */
    private function resolveMetadataField(string $fieldKey, int $tenantId): ?object
    {
        $tenantField = DB::table('metadata_fields')
            ->where('key', $fieldKey)
            ->where('tenant_id', $tenantId)
            ->where('scope', 'tenant')
            ->first();

        if ($tenantField) {
            return $tenantField;
        }

        return DB::table('metadata_fields')
            ->where('key', $fieldKey)
            ->whereNull('tenant_id')
            ->where('scope', 'system')
            ->first();
    }

    /**
     * Normalize JSON text so it matches values written via json_encode (and MySQL JSON storage).
     */
    private static function canonicalJsonForMatch(string $valueJson): ?string
    {
        $decoded = json_decode($valueJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    private static function displayValueFromJson(?string $valueJson): string
    {
        if ($valueJson === null || $valueJson === '') {
            return '';
        }
        $decoded = json_decode($valueJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $valueJson;
        }
        if (is_string($decoded)) {
            return $decoded;
        }
        if (is_numeric($decoded)) {
            return (string) $decoded;
        }
        if (is_bool($decoded)) {
            return $decoded ? 'true' : 'false';
        }
        if ($decoded === null) {
            return '';
        }
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        return '';
    }
}
