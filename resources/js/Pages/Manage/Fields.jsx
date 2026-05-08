import ManageLayout from '../../layouts/ManageLayout'
import ManageFieldsOverview from '../../Components/Manage/ManageFieldsOverview'
import { WorkbenchPageIntro } from '../../components/brand-workspace/workbenchPatterns'

export default function ManageFields({ brand, categories, custom_fields, system_fields }) {
    return (
        <ManageLayout title="Fields — Manage" activeSection="fields">
            <div>
                <WorkbenchPageIntro
                    title="Fields"
                    description={`Custom fields for ${brand?.name ?? 'this brand'}, their values, and which folders they apply to. System and default fields stay collapsed by default. Change per-folder visibility on Folders & fields.`}
                />
                <ManageFieldsOverview
                    categories={categories ?? []}
                    customFields={custom_fields ?? []}
                    systemFields={system_fields ?? []}
                />
            </div>
        </ManageLayout>
    )
}
