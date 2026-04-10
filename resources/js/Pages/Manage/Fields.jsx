import ManageLayout from '../../layouts/ManageLayout'
import ManageFieldsOverview from '../../Components/Manage/ManageFieldsOverview'

export default function ManageFields({ brand, categories, custom_fields, system_fields }) {
    return (
        <ManageLayout title="Fields — Manage" activeSection="fields">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Fields</h2>
                <p className="mt-1 text-sm text-gray-500">
                    Custom fields for {brand?.name ?? 'this brand'}, their allowed values (when defined), and which folders
                    they are visible in. System and automated fields are grouped below and collapsed by default. To change
                    visibility or edit definitions, use{' '}
                    <span className="font-medium text-gray-700">Categories</span>.
                </p>
                <ManageFieldsOverview
                    categories={categories ?? []}
                    customFields={custom_fields ?? []}
                    systemFields={system_fields ?? []}
                />
            </div>
        </ManageLayout>
    )
}
