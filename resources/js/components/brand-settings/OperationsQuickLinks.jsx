import { Link, usePage } from '@inertiajs/react'
import {
    ChartBarIcon,
    SparklesIcon,
    RectangleGroupIcon,
    ClockIcon,
    CloudArrowDownIcon,
    UserGroupIcon,
    BuildingOffice2Icon,
    CreditCardIcon,
    UsersIcon,
    BuildingStorefrontIcon,
    LinkIcon,
    ShieldCheckIcon,
} from '@heroicons/react/24/outline'
import { SECTION_INTRO } from './brandSettingsCopy'
import SettingsSectionIntro from './SettingsSectionIntro'

const LINK_ICON = 'h-5 w-5 text-[var(--jp-bs-primary)] shrink-0'

function CompanyJumpLink({ href, title, description, icon: Icon }) {
    return (
        <Link
            href={href}
            className="group flex w-full items-center gap-3 rounded-lg border border-slate-200/90 bg-white px-4 py-3 text-left shadow-sm transition hover:border-[var(--jp-bs-soft-border)] hover:bg-[var(--jp-bs-soft-bg)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--jp-bs-ring)] focus-visible:ring-offset-2"
        >
            {Icon && <Icon className={LINK_ICON} aria-hidden />}
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-900 group-hover:text-[var(--jp-bs-primary)]">{title}</p>
                <p className="mt-0.5 text-xs text-slate-500 leading-relaxed">{description}</p>
            </div>
            <span className="shrink-0 self-center text-slate-300 group-hover:text-[var(--jp-bs-primary)]" aria-hidden>
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
            </span>
        </Link>
    )
}

function OpCard({ href, title, description, icon: Icon }) {
    return (
        <Link
            href={href}
            className="group flex gap-4 rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:border-[var(--jp-bs-soft-border)] hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--jp-bs-ring)] focus-visible:ring-offset-2"
        >
            {Icon && <Icon className={LINK_ICON} aria-hidden />}
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-900 group-hover:text-[var(--jp-bs-primary)]">{title}</p>
                <p className="mt-1 text-xs text-slate-500 leading-relaxed">{description}</p>
            </div>
            <span className="self-center text-slate-300 group-hover:text-[var(--jp-bs-primary)]" aria-hidden>
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
            </span>
        </Link>
    )
}

/**
 * Company-scoped links use the same permission strings as the target routes (see CompanyController, TeamController, etc.).
 *
 * @param {{ brandId: number }} props
 */
export default function OperationsQuickLinks({ brandId }) {
    const { auth } = usePage().props
    const effectivePermissions = Array.isArray(auth?.effective_permissions) ? auth.effective_permissions : []
    const can = (p) => effectivePermissions.includes(p)
    const canViewCreatorsDashboard = auth?.permissions?.can_view_creators_dashboard === true
    const tenantRole = auth?.tenant_role != null ? String(auth.tenant_role).toLowerCase() : ''
    const isOwnerOrAdmin = tenantRole === 'owner' || tenantRole === 'admin'
    const activeCompany = auth?.activeCompany
    const isAgencyWorkspace = activeCompany?.is_agency === true

    const r = (name, params = {}) => (typeof route === 'function' ? route(name, params) : '#')

    const companyJumpItems = (() => {
        const out = []
        if (can('company_settings.view')) {
            out.push({
                key: 'company-admin',
                href: r('companies.settings'),
                title: 'Company admin',
                description: 'Workspace name, timezone, ownership, AI and download policy, and integrations.',
                icon: BuildingOffice2Icon,
            })
        }
        if (can('billing.view')) {
            out.push({
                key: 'billing',
                href: r('billing'),
                title: 'Billing & plan',
                description: 'Subscription, seats, invoices, payment method, and add-ons.',
                icon: CreditCardIcon,
            })
        }
        if (can('team.manage')) {
            out.push({
                key: 'team',
                href: r('companies.team'),
                title: 'Team & access',
                description: 'Invite people, company roles, and which brands they can open.',
                icon: UsersIcon,
            })
        }
        if (isAgencyWorkspace) {
            out.push({
                key: 'agency-program',
                href: r('agency.dashboard'),
                title: 'Agency program',
                description: 'Partner tier, client workspaces, and incubation tools.',
                icon: BuildingStorefrontIcon,
            })
        }
        if (can('team.manage') && isOwnerOrAdmin) {
            out.push({
                key: 'agency-links',
                href: `${r('companies.settings')}#agencies`,
                title: 'Partner & agency links',
                description: 'Connect partner agencies, assign roles, and brand access for linked accounts.',
                icon: LinkIcon,
            })
        }
        if (can('activity_logs.view')) {
            out.push({
                key: 'company-activity',
                href: r('companies.activity'),
                title: 'Company activity',
                description: 'Organization-wide audit log (separate from brand workspace activity).',
                icon: ClockIcon,
            })
        }
        if (isOwnerOrAdmin) {
            out.push({
                key: 'company-permissions',
                href: r('companies.permissions'),
                title: 'Roles & permissions',
                description: 'Reference for what company and brand roles can do in this workspace.',
                icon: ShieldCheckIcon,
            })
        }
        return out
    })()

    const items = [
        {
            href: r('insights.overview'),
            title: 'Insights',
            description: 'Analytics, metadata health, AI review, and creator performance.',
            icon: ChartBarIcon,
        },
        {
            href: r('insights.review'),
            title: 'Review queue',
            description: 'Approve AI suggestions and upload approvals.',
            icon: SparklesIcon,
        },
        {
            href: r('manage.categories'),
            title: 'Manage library',
            description: 'Folders, fields, tags, and values for this brand.',
            icon: RectangleGroupIcon,
        },
        {
            href: r('insights.activity'),
            title: 'Activity',
            description: 'Audit recent changes and processing activity.',
            icon: ClockIcon,
        },
        {
            href: r('insights.usage'),
            title: 'Usage',
            description: 'Downloads, views, storage, and AI credits.',
            icon: CloudArrowDownIcon,
        },
        ...(canViewCreatorsDashboard
            ? [
                  {
                      href: r('brands.creators', { brand: brandId }),
                      title: 'Creator dashboard',
                      description: 'Creator workflow, submissions, and performance.',
                      icon: UserGroupIcon,
                  },
              ]
            : []),
    ]

    const meta = SECTION_INTRO.operations

    return (
        <div className="space-y-8">
            <SettingsSectionIntro title={meta.title} description={meta.description} affects={meta.affects} />
            <div className="grid gap-3 sm:grid-cols-2">
                {items.map((item) => (
                    <OpCard key={item.title} {...item} />
                ))}
            </div>

            {companyJumpItems.length > 0 && (
                <div className="border-t border-slate-200/80 pt-8">
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Jump to company</h3>
                    <p className="mt-2 text-sm text-slate-600 leading-relaxed max-w-2xl">
                        These pages apply to your <span className="font-medium text-slate-800">entire organization</span>
                        (billing, members, and company policy), not only to this brand&apos;s creative settings.
                    </p>
                    <div className="mt-4 flex flex-col gap-2">
                        {companyJumpItems.map((row) => (
                            <CompanyJumpLink key={row.key} href={row.href} title={row.title} description={row.description} icon={row.icon} />
                        ))}
                    </div>
                </div>
            )}
        </div>
    )
}
