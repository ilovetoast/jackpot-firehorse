/**
 * VersionPanel — displays Brand DNA versions with lifecycle badges and contextual actions.
 * Replaces the version dropdown + draft callout + action buttons in Brand Settings.
 */

import { Link, router } from '@inertiajs/react'

function LifecycleBadge({ version }) {
    const stage = version.lifecycle_stage || 'research'
    const status = version.status

    if (status === 'active') {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                </svg>
                Published
            </span>
        )
    }

    if (status === 'archived') {
        return (
            <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">
                Archived
            </span>
        )
    }

    const stageConfig = {
        research: {
            label: version.research_status === 'running' ? 'Research running' : version.research_status === 'complete' ? 'Research complete' : 'Research',
            color: version.research_status === 'running' ? 'bg-amber-100 text-amber-800' : version.research_status === 'complete' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600',
        },
        review: { label: 'In review', color: 'bg-purple-100 text-purple-800' },
        build: { label: 'Building', color: 'bg-indigo-100 text-indigo-800' },
        published: { label: 'Published', color: 'bg-green-100 text-green-800' },
    }

    const config = stageConfig[stage] || stageConfig.research

    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${config.color}`}>
            {(version.research_status === 'running') && (
                <span className="mr-1 h-1.5 w-1.5 rounded-full bg-current animate-pulse" />
            )}
            {config.label}
        </span>
    )
}

function draftCtaProps(version, brandId) {
    const stage = version.lifecycle_stage || 'research'

    if (stage === 'research') {
        return {
            href: route('brands.research.show', { brand: brandId }),
            label: version.research_status === 'running' ? 'View Progress' : 'Continue Research',
        }
    }
    if (stage === 'review') {
        return {
            href: route('brands.review.show', { brand: brandId }),
            label: 'Continue Review',
        }
    }
    return {
        href: route('brands.brand-guidelines.builder', { brand: brandId }),
        label: 'Continue Building',
    }
}

export default function VersionPanel({
    versions = [],
    activeVersionId = null,
    brandId,
    onSelect,
    selectedVersionId,
}) {
    if (!versions.length) return null

    const drafts = versions.filter(v => v.status === 'draft')
    const nonDrafts = versions.filter(v => v.status !== 'draft')

    return (
        <div className="space-y-1.5">
            {/* Draft versions first — these are the actionable ones */}
            {drafts.map((v) => {
                const cta = draftCtaProps(v, brandId)
                const isSelected = v.id === selectedVersionId

                return (
                    <div
                        key={v.id}
                        className={`rounded-lg border p-3 transition-colors ${
                            isSelected ? 'border-indigo-300 bg-indigo-50' : 'border-amber-200 bg-amber-50/50 hover:bg-amber-50'
                        }`}
                    >
                        <div className="flex items-center justify-between gap-3">
                            <div
                                className="flex items-center gap-3 min-w-0 flex-1 cursor-pointer"
                                onClick={() => onSelect?.(v.id)}
                            >
                                <span className="text-sm font-semibold text-gray-900">v{v.version_number}</span>
                                <LifecycleBadge version={v} />
                                <span className="text-xs text-gray-400">Draft</span>
                            </div>
                            <Link
                                href={cta.href}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors flex-shrink-0"
                            >
                                {cta.label}
                                <svg className="ml-1.5 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                )
            })}

            {/* Published / archived versions */}
            {nonDrafts.map((v) => {
                const isSelected = v.id === selectedVersionId
                const isActive = v.id === activeVersionId

                return (
                    <div
                        key={v.id}
                        className={`flex items-center justify-between gap-3 rounded-lg border p-3 cursor-pointer transition-colors ${
                            isSelected ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200 hover:bg-gray-50'
                        }`}
                        onClick={() => onSelect?.(v.id)}
                    >
                        <div className="flex items-center gap-3 min-w-0">
                            <span className="text-sm font-medium text-gray-900">v{v.version_number}</span>
                            <LifecycleBadge version={v} />
                            {v.created_at && (
                                <span className="text-xs text-gray-400 hidden sm:inline">
                                    {new Date(v.created_at).toLocaleDateString()}
                                </span>
                            )}
                        </div>
                        {isActive && (
                            <Link
                                href={route('brands.guidelines.index', { brand: brandId })}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors flex-shrink-0"
                            >
                                View Guidelines
                                <svg className="ml-1.5 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                            </Link>
                        )}
                    </div>
                )
            })}
        </div>
    )
}
