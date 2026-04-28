import ManageLayout from '../../layouts/ManageLayout'
import ManageCategoriesHub from '../../Components/Manage/ManageCategoriesHub'
import { WorkbenchPageIntro } from '../../components/brand-workspace/workbenchPatterns'

export default function ManageCategories(props) {
    return (
        <ManageLayout title="Categories — Manage" activeSection="categories">
            <div>
                <WorkbenchPageIntro
                    title="Categories"
                    description="Folders for this brand and which metadata fields apply in each. Select a folder on the left; configure fields on the right."
                />
                <div className="mt-5 sm:mt-6">
                    <ManageCategoriesHub {...props} />
                </div>
            </div>
        </ManageLayout>
    )
}
