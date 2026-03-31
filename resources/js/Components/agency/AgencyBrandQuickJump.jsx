import { Link } from '@inertiajs/react'
import { BuildingOffice2Icon, ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'

function normalizeName(s) {
    return (s ?? '').trim().toLowerCase()
}

/**
 * Minimal company → brand list to open a client workspace (Overview).
 * Intentionally light vs Readiness / Clients tabs.
 */
export default function AgencyBrandQuickJump({ clients = [], brandColor = '#6366f1' }) {
    const openBrand = (companyId, brandId) => {
        switchCompanyWorkspace({ companyId, brandId, redirect: '/app/overview' })
    }

    const list = Array.isArray(clients) ? clients : []

    return (
        <section className="mt-2" aria-labelledby="agency-quick-brands-heading">
            <div className="mb-4">
                <h2 id="agency-quick-brands-heading" className="text-sm font-semibold text-white">
                    Jump to a brand
                </h2>
                <p className="mt-1 text-xs text-white/45">
                    Open a client workspace in one step—same companies as in Clients, without the extra detail.
                </p>
            </div>

            {list.length === 0 ? (
                <div className="rounded-xl border border-white/10 bg-white/[0.03] px-5 py-8 text-center">
                    <p className="text-sm text-white/55">No linked client companies yet.</p>
                    <p className="mt-2 text-xs text-white/35">
                        Link clients from{' '}
                        <Link
                            href="/app/companies/settings#agencies"
                            className="font-medium text-white/70 underline decoration-white/25 underline-offset-2 hover:text-white"
                        >
                            Company settings → Agencies
                        </Link>
                        .
                    </p>
                </div>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {list.map((company) => {
                        const brands = Array.isArray(company.brands) ? company.brands : []
                        const singleBrand = brands.length === 1 ? brands[0] : null
                        const singleSameName =
                            singleBrand &&
                            normalizeName(company.name) === normalizeName(singleBrand.name)

                        if (singleSameName) {
                            return (
                                <li
                                    key={company.id}
                                    className="rounded-xl border border-white/10 bg-gradient-to-br from-white/[0.06] to-white/[0.02] p-4 ring-1 ring-white/[0.06] backdrop-blur-sm"
                                    style={{ borderLeftWidth: 3, borderLeftColor: brandColor }}
                                >
                                    <div className="flex items-start gap-2.5">
                                        <BuildingOffice2Icon className="mt-0.5 h-4 w-4 shrink-0 text-white/35" aria-hidden />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-[10px] font-medium uppercase tracking-wider text-white/40">
                                                Client workspace
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => openBrand(company.id, singleBrand.id)}
                                                className="group mt-1 flex w-full min-w-0 items-center justify-between gap-2 rounded-lg px-0 py-1 text-left text-sm font-semibold text-white transition hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/30 sm:px-1"
                                            >
                                                <span className="min-w-0 truncate">{company.name}</span>
                                                <ArrowTopRightOnSquareIcon
                                                    className="h-4 w-4 shrink-0 text-white/35 opacity-70 transition group-hover:opacity-100"
                                                    aria-hidden
                                                />
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            )
                        }

                        return (
                            <li
                                key={company.id}
                                className="rounded-xl border border-white/10 bg-gradient-to-br from-white/[0.06] to-white/[0.02] p-4 ring-1 ring-white/[0.06] backdrop-blur-sm"
                                style={{ borderLeftWidth: 3, borderLeftColor: brandColor }}
                            >
                                <div className="flex items-start gap-2.5">
                                    <BuildingOffice2Icon className="mt-0.5 h-4 w-4 shrink-0 text-white/35" aria-hidden />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[10px] font-medium uppercase tracking-wider text-white/40">
                                            Company
                                        </p>
                                        <p className="truncate text-sm font-semibold text-white">{company.name}</p>
                                    </div>
                                </div>
                                {brands.length === 0 ? (
                                    <p className="mt-3 border-t border-white/10 pt-3 text-xs text-white/35">
                                        No brands available for your role.
                                    </p>
                                ) : (
                                    <ul className="mt-3 space-y-1 border-t border-white/10 pt-3">
                                        {brands.map((b) => (
                                            <li key={b.id}>
                                                <button
                                                    type="button"
                                                    onClick={() => openBrand(company.id, b.id)}
                                                    className="group flex w-full min-w-0 items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-white/85 transition hover:bg-white/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/30"
                                                >
                                                    <span className="min-w-0 truncate font-medium">{b.name}</span>
                                                    <ArrowTopRightOnSquareIcon
                                                        className="h-4 w-4 shrink-0 text-white/25 opacity-0 transition group-hover:opacity-100"
                                                        aria-hidden
                                                    />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        )
                    })}
                </ul>
            )}
        </section>
    )
}
