<?php

namespace App\Services\Prostaff;

use App\Models\Brand;
use App\Models\ProstaffMembership;
use App\Models\Tenant;
use DomainException;

class GetProstaffDamFilterOptions
{
    /**
     * Active prostaff members for DAM prostaff_user_id dropdown / options API.
     *
     * @return list<array{user_id: int, name: string}>
     */
    public function activeMemberOptionsForBrand(Brand $brand): array
    {
        $tenant = Tenant::query()->find($brand->tenant_id);
        if ($tenant === null) {
            return [];
        }

        try {
            app(EnsureCreatorModuleEnabled::class)->assertEnabled($tenant);
        } catch (DomainException) {
            return [];
        }

        $rows = [];
        $memberships = ProstaffMembership::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->with(['user' => static function ($query): void {
                $query->select(['id', 'first_name', 'last_name', 'email']);
            }])
            ->orderBy('user_id')
            ->get();

        foreach ($memberships as $membership) {
            $user = $membership->user;
            if ($user === null) {
                continue;
            }
            $name = trim((string) $user->name);
            if ($name === '') {
                $name = (string) ($user->email ?? '');
            }
            $rows[] = [
                'user_id' => (int) $user->id,
                'name' => $name,
            ];
        }

        return $rows;
    }

    /**
     * Additive DAM filter definitions (does not mutate metadata {@see $filterableSchema}).
     *
     * @return array{filters: list<array<string, mixed>>}
     */
    public static function damProstaffFilterConfig(): array
    {
        return [
            'filters' => [
                [
                    'key' => 'submitted_by_prostaff',
                    'type' => 'boolean',
                    'query_param' => 'submitted_by_prostaff',
                    'label' => 'Prostaff uploads',
                ],
                [
                    'key' => 'prostaff_user_id',
                    'type' => 'select',
                    'query_param' => 'prostaff_user_id',
                    'label' => 'Prostaff member',
                    'options_prop' => 'prostaff_filter_options',
                ],
            ],
        ];
    }
}
