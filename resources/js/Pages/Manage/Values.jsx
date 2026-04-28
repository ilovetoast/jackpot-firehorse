import ManageLayout from '../../layouts/ManageLayout'
import ManageValuesWorkspace from '../../Components/Manage/ManageValuesWorkspace'
import { WorkbenchPageIntro } from '../../components/brand-workspace/workbenchPatterns'

export default function ManageValues({ brand, can_purge_metadata_values }) {
    return (
        <ManageLayout title="Values — Manage" activeSection="values">
            <div>
                <WorkbenchPageIntro
                    title="Values"
                    description={`Custom field values in use across ${brand?.name ?? 'this brand'}, grouped by field. System and automated values are not listed here. Purging removes a value from every asset. Tags live on the Tags page.`}
                />
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
