import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { refreshCsrfTokenFromServer } from '../../utils/csrf'

export default function CompanySelector({ companies }) {
    const { theme } = usePage().props
    const [processing, setProcessing] = useState(false)

    const handleSelect = async (company) => {
        if (processing) return
        setProcessing(true)
        try {
            await refreshCsrfTokenFromServer()
        } catch {
            /* still attempt */
        }
        router.post('/gateway/select-company', { tenant_id: company.id }, {
            onFinish: () => setProcessing(false),
        })
    }

    return (
        <div className="w-full max-w-md animate-fade-in" style={{ animationDuration: '500ms' }}>
            <div className="text-center mb-12">
                <h1 className="font-display text-4xl md:text-5xl font-semibold tracking-tight leading-tight text-white/95 mb-3">
                    Choose Workspace
                </h1>
                <p className="text-sm text-white/60 mt-2 max-w-md mx-auto">
                    Select a company to continue
                </p>
            </div>

            <div className="flex flex-col gap-4">
                {companies.length === 0 && (
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.03] p-6 text-center">
                        <p className="text-sm text-white/70 leading-relaxed">
                            You don&apos;t have access to any workspace right now. You can manage your account, start a
                            company, or sign out and use a different login.
                        </p>
                        <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
                            <a
                                href="/app/profile"
                                className="inline-flex justify-center rounded-lg border border-white/15 bg-white/[0.06] px-4 py-2.5 text-sm font-medium text-white/90 hover:bg-white/10"
                            >
                                Account settings
                            </a>
                            <a
                                href="/app/companies"
                                className="inline-flex justify-center rounded-lg border border-white/15 bg-white/[0.06] px-4 py-2.5 text-sm font-medium text-white/90 hover:bg-white/10"
                            >
                                Companies
                            </a>
                            <button
                                type="button"
                                onClick={() => router.post('/app/logout')}
                                className="w-full rounded-lg border border-white/10 px-4 py-2.5 text-sm font-medium text-white/50 hover:text-white/80 hover:border-white/20 sm:w-auto"
                            >
                                Sign out
                            </button>
                        </div>
                    </div>
                )}
                {companies.map((company) => {
                    const color = company.primary_color || theme?.colors?.primary || '#6366f1'
                    return (
                        <button
                            key={company.id}
                            type="button"
                            onClick={() => handleSelect(company)}
                            disabled={processing}
                            className="group relative overflow-hidden rounded-xl border border-white/[0.08] bg-white/[0.03] p-6 text-left transition-all duration-200 hover:scale-[1.02] hover:border-white/[0.16] hover:bg-white/10 disabled:opacity-50"
                        >
                            <div className="flex items-center gap-5">
                                <CompanyIcon company={company} color={color} />
                                <div className="flex-1 min-w-0">
                                    <h2 className="text-lg font-medium text-white truncate">
                                        {company.name}
                                    </h2>
                                    <p className="text-sm text-white/40 mt-0.5">
                                        {company.slug}
                                    </p>
                                </div>
                                <svg
                                    className="w-5 h-5 text-white/20 group-hover:text-white/50 transition-colors duration-300"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    strokeWidth="1.5"
                                    stroke="currentColor"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </div>

                            <div
                                className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"
                                style={{
                                    background: `radial-gradient(circle at 50% 50%, ${color}12 0%, transparent 70%)`,
                                }}
                            />
                        </button>
                    )
                })}
            </div>
        </div>
    )
}

function CompanyIcon({ company, color }) {
    const [imgError, setImgError] = useState(false)

    if (company.logo_url && !imgError) {
        return (
            <img
                src={company.logo_url}
                alt={company.name}
                className="h-11 w-11 rounded-lg object-contain"
                onError={() => setImgError(true)}
            />
        )
    }

    if (company.icon) {
        return (
            <div
                className="h-11 w-11 rounded-lg flex items-center justify-center text-sm font-semibold text-white"
                style={{ backgroundColor: company.icon_bg_color || color }}
            >
                {company.icon}
            </div>
        )
    }

    return (
        <div
            className="h-11 w-11 rounded-lg flex items-center justify-center text-lg font-semibold text-white/70"
            style={{ backgroundColor: `${color}25` }}
        >
            {company.name?.charAt(0)?.toUpperCase()}
        </div>
    )
}
