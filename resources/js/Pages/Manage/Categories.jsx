import ManageLayout from '../../layouts/ManageLayout'
import ManageCategoriesHub from '../../Components/Manage/ManageCategoriesHub'
import { WorkbenchPageIntro } from '../../components/brand-workspace/workbenchPatterns'
import { BRAND_WORKBENCH_PAD_X, BRAND_WORKBENCH_PAD_Y } from '../../components/brand-workspace/brandWorkspaceTokens'

/** Wider than default `max-w-7xl` so the fields workspace has more room without going full-bleed. */
const MANAGE_CATEGORIES_WORKBENCH =
    `mx-auto w-full max-w-[min(100%,92rem)] ${BRAND_WORKBENCH_PAD_X} ${BRAND_WORKBENCH_PAD_Y}`

export default function ManageCategories(props) {
    return (
        <ManageLayout
            title="Folders & fields — Manage"
            activeSection="categories"
            workbenchChromeClassName={MANAGE_CATEGORIES_WORKBENCH}
        >
            <div>
                <WorkbenchPageIntro
                    title="Folders & fields"
                    description="Choose a folder. Manage the fields used for assets in that folder."
                />

                <div className="mt-4 sm:mt-5">
                    <ManageCategoriesHub {...props} />
                </div>
            </div>
        </ManageLayout>
    )
}
