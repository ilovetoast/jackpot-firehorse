import ManageLayout from '../../layouts/ManageLayout'
import ManageValuesWorkspace from '../../Components/Manage/ManageValuesWorkspace'
import { WorkbenchPageIntro } from '../../components/brand-workspace/workbenchPatterns'
import { Link } from '@inertiajs/react'

const MANAGE_CATEGORIES_HREF =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

export default function ManageValues({ brand, can_purge_metadata_values }) {
    return (
        <ManageLayout title="Values — Manage" activeSection="values">
            <div>
                <WorkbenchPageIntro title="Values">
                    <p className="max-w-3xl text-sm text-slate-500">
                        Use this page to review <span className="font-medium text-slate-700">picklist-style answers</span>{' '}
                        already stored on assets for <span className="font-medium text-slate-700">custom</span> fields
                        (single choice, multiple choice, etc.). Each section is one field; under it are the exact option
                        strings in use. This is{' '}
                        <span className="font-medium text-slate-700">not</span> where you configure the asset grid’s
                        filters or field definitions—those live under{' '}
                        <Link href={MANAGE_CATEGORIES_HREF} className="font-medium text-violet-700 hover:text-violet-800">
                            Folders &amp; filters
                        </Link>
                        . Tags are managed on the Tags page. Purging here removes that value from every asset in this
                        brand.
                    </p>
                </WorkbenchPageIntro>
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
