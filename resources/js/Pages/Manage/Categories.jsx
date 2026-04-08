import ManageLayout from '../../layouts/ManageLayout'
import ManageCategoriesHub from '../../Components/Manage/ManageCategoriesHub'

export default function ManageCategories(props) {
    return (
        <ManageLayout title="Categories — Manage" activeSection="categories">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Categories</h2>
                <p className="mt-1 text-sm text-gray-500">
                    Folders for this brand and the metadata fields enabled on each. Select a folder to configure
                    fields; drag folders to reorder—they save automatically. Field switches (upload, filters, etc.)
                    also save as soon as you change them. Opening field definition or Edit uses Save inside that
                    dialog.
                </p>
                <div className="mt-6">
                    <ManageCategoriesHub {...props} />
                </div>
            </div>
        </ManageLayout>
    )
}
