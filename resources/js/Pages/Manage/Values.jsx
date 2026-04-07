import ManageLayout from '../../layouts/ManageLayout'
import ManageValuesWorkspace from '../../Components/Manage/ManageValuesWorkspace'

export default function ManageValues({ brand, can_purge_metadata_values }) {
    return (
        <ManageLayout title="Values — Manage" activeSection="values">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Values</h2>
                <p className="mt-1 text-sm text-gray-500">
                    Custom (tenant) field values in use across {brand?.name ?? 'this brand'}, grouped by field. System and
                    automated fields are hidden for now. Removing a value deletes it from every asset that has it (for
                    example cleaning a bad product option everywhere). Tags are on the Tags page.
                </p>
                {brand?.id != null && (
                    <ManageValuesWorkspace
                        brandId={brand.id}
                        brandName={brand.name}
                        canPurgeMetadataValues={Boolean(can_purge_metadata_values)}
                    />
                )}
            </div>
        </ManageLayout>
    )
}
