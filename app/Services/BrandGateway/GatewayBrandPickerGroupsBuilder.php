<?php

namespace App\Services\BrandGateway;

use App\Models\User;
use App\Services\AgencyBrandAccessService;

/**
 * Agency-aware sections for the gateway all-workspaces brand picker.
 * Mirrors {@see \App\Services\Navigation\AgencyContextPickerOptionsBuilder} grouping.
 */
class GatewayBrandPickerGroupsBuilder
{
    public function __construct(
        protected AgencyBrandAccessService $agencyBrandAccessService
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $availableBrands
     * @return array<int, array<string, mixed>>|null
     */
    public function build(User $user, array $availableBrands): ?array
    {
        $grouped = $this->agencyBrandAccessService->groupedAgencyPortfolioBrands($user);
        if ($grouped === null || $availableBrands === []) {
            return null;
        }

        $brandById = [];
        foreach ($availableBrands as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $brandById[$id] = $row;
            }
        }

        if ($brandById === []) {
            return null;
        }

        $groups = [];
        $usedIds = [];

        $agencyItems = $this->pickAvailableBrands($grouped['agency_brands'], $brandById, $usedIds);
        if ($agencyItems !== []) {
            $groups[] = [
                'type' => 'agency',
                'section_label' => 'Agency workspace',
                'tenant_id' => (int) $grouped['agency_tenant_id'],
                'tenant_name' => (string) $grouped['agency_tenant_name'],
                'brands' => $agencyItems,
            ];
        }

        $managedSectionPlaced = false;
        foreach ($grouped['client_workspaces'] as $workspace) {
            $items = $this->pickAvailableBrands($workspace['brands'], $brandById, $usedIds);
            if ($items === []) {
                continue;
            }

            $groups[] = [
                'type' => 'client',
                'section_label' => $managedSectionPlaced ? null : 'Managed clients',
                'tenant_id' => (int) $workspace['tenant_id'],
                'tenant_name' => (string) $workspace['tenant_name'],
                'brands' => $items,
            ];
            $managedSectionPlaced = true;
        }

        $remaining = [];
        foreach ($availableBrands as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && ! isset($usedIds[$id])) {
                $remaining[] = $row;
            }
        }

        if ($remaining !== []) {
            $byTenant = [];
            foreach ($remaining as $row) {
                $tid = (int) ($row['tenant_id'] ?? 0);
                if (! isset($byTenant[$tid])) {
                    $byTenant[$tid] = [
                        'tenant_id' => $tid,
                        'tenant_name' => (string) ($row['tenant_name'] ?? 'Company'),
                        'brands' => [],
                    ];
                }
                $byTenant[$tid]['brands'][] = $row;
            }

            $otherSorted = array_values($byTenant);
            usort($otherSorted, fn (array $a, array $b) => strcasecmp($a['tenant_name'], $b['tenant_name']));

            $otherSectionPlaced = false;
            foreach ($otherSorted as $workspace) {
                $brands = $this->sortBrandsByName($workspace['brands']);
                if ($brands === []) {
                    continue;
                }

                $groups[] = [
                    'type' => 'company',
                    'section_label' => $otherSectionPlaced ? null : 'Other workspaces',
                    'tenant_id' => (int) $workspace['tenant_id'],
                    'tenant_name' => (string) $workspace['tenant_name'],
                    'brands' => $brands,
                ];
                $otherSectionPlaced = true;
            }
        }

        return $groups === [] ? null : $groups;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceRows
     * @param  array<int, array<string, mixed>>  $brandById
     * @param  array<int, true>  $usedIds
     * @return array<int, array<string, mixed>>
     */
    protected function pickAvailableBrands(array $sourceRows, array $brandById, array &$usedIds): array
    {
        $items = [];
        foreach ($sourceRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($brandById[$id])) {
                $items[] = $brandById[$id];
                $usedIds[$id] = true;
            }
        }

        return $this->sortBrandsByName($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $brands
     * @return array<int, array<string, mixed>>
     */
    protected function sortBrandsByName(array $brands): array
    {
        usort($brands, fn (array $a, array $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return array_values($brands);
    }
}
