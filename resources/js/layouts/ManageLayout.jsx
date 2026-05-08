import { usePage } from '@inertiajs/react'
import AppHead from '../Components/AppHead'
import AppNav from '../Components/AppNav'
import AppFooter from '../Components/AppFooter'
import BrandWorkbenchMasthead from '../components/brand-workspace/BrandWorkbenchMasthead'
import { BrandWorkbenchChrome } from '../contexts/BrandWorkbenchChromeContext'
import WorkbenchLocalNav from '../components/brand-workspace/WorkbenchLocalNav'
import { BRAND_WORKBENCH_CONTENT, WORKBENCH_ASIDE_WIDTH, workbenchPageColumnsClass } from '../components/brand-workspace/brandWorkspaceTokens'
import WorkbenchSegmentedNav from '../components/brand-workspace/WorkbenchSegmentedNav'
import { Squares2X2Icon, TagIcon, ListBulletIcon, RectangleStackIcon } from '@heroicons/react/24/outline'

const MANAGE_CATEGORIES_HREF =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

const MANAGE_FIELDS_HREF =
    typeof route === 'function' ? route('manage.fields') : '/app/manage/fields'

const SIDEBAR_ITEMS = [
    { id: 'categories', label: 'Folders & fields', href: MANAGE_CATEGORIES_HREF, icon: Squares2X2Icon },
    { id: 'fields', label: 'Fields', href: MANAGE_FIELDS_HREF, icon: RectangleStackIcon },
    { id: 'tags', label: 'Tags', href: '/app/manage/tags', icon: TagIcon },
    { id: 'values', label: 'Values', href: '/app/manage/values', icon: ListBulletIcon },
]

export default function ManageLayout({
    children,
    title = 'Manage',
    activeSection = 'categories',
    /** Override default `BRAND_WORKBENCH_CONTENT` when a page needs more horizontal room (e.g. Folders & fields). */
    workbenchChromeClassName = BRAND_WORKBENCH_CONTENT,
}) {
    const { auth, tenant } = usePage().props
    const brand = auth?.activeBrand
    const company = auth?.activeCompany
    const brandColor = brand?.primary_color || company?.primary_color
    const canLinkCompany =
        Array.isArray(auth?.effective_permissions) && auth.effective_permissions.includes('company_settings.view')

    return (
        <div className="flex min-h-screen flex-col bg-slate-50">
            <AppHead title={title} />
            <AppNav brand={auth?.activeBrand} tenant={tenant} />

            <div className="flex-1">
                <BrandWorkbenchChrome brand={auth?.activeBrand} company={company} className={workbenchChromeClassName}>
                    <BrandWorkbenchMasthead
                        companyName={company?.name}
                        brandName={brand?.name}
                        canLinkCompany={canLinkCompany}
                        companyHref={typeof route === 'function' ? route('companies.settings') : '/app/companies/settings'}
                        title="Manage"
                        description="Configure folders, fields, tags, and controlled values for this brand’s library."
                        brandColor={brandColor}
                    />

                    <div className={workbenchPageColumnsClass}>
                        <WorkbenchSegmentedNav
                            items={SIDEBAR_ITEMS}
                            activeId={activeSection}
                            ariaLabel="Manage sections"
                        />
                        <aside className={`hidden shrink-0 ${WORKBENCH_ASIDE_WIDTH} lg:block`}>
                            <WorkbenchLocalNav items={SIDEBAR_ITEMS} activeId={activeSection} ariaLabel="Manage sections" />
                        </aside>

                        <main className="min-w-0 flex-1" data-help="manage-metadata-structure">
                            {children}
                        </main>
                    </div>
                </BrandWorkbenchChrome>
            </div>

            <AppFooter variant="settings" />
        </div>
    )
}
