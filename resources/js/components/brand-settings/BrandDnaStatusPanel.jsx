import { Link, router } from '@inertiajs/react'
import { useState } from 'react'
import VersionPanel from '../../Components/BrandGuidelines/VersionPanel'
import ConfirmDialog from '../../Components/ConfirmDialog'

/**
 * Compact status module for Brand DNA: enable toggle, active version, version list, research CTA, draft discard.
 * Does not own form state — all handlers come from the parent.
 */
export default function BrandDnaStatusPanel({
    brandId,
    brandModel,
    activeVersion,
    allVersions = [],
    selectedVersionId,
    onVersionSelect,
    onToggleEnabled,
    isFreePlan,
    canManage,
}) {
    const [showDiscardDraftConfirm, setShowDiscardDraftConfirm] = useState(false)
    const draftVersion = (allVersions || []).find((v) => v.status === 'draft')
    const hasActiveVersion = !!activeVersion && activeVersion.status === 'active'

    const researchHref =
        typeof route === 'function' ? route('brands.research.show', { brand: brandId }) : `/app/brands/${brandId}/research`

    return (
        <section
            className="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm sm:p-5"
            aria-label="Brand DNA status"
        >
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-slate-900">Brand DNA status</h3>
                    <p className="mt-0.5 text-xs text-slate-500">
                        Rules and intelligence for guidelines, scoring, and AI — distinct from visual Identity.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2 sm:gap-x-4 sm:justify-end">
                    <span className="text-xs font-medium text-slate-600" id="brand-dna-enabled-label">
                        Enabled
                    </span>
                    <button
                        type="button"
                        onClick={onToggleEnabled}
                        className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2 ${
                            brandModel?.is_enabled ? 'bg-violet-600' : 'bg-slate-200'
                        }`}
                        role="switch"
                        aria-checked={brandModel?.is_enabled === true}
                        aria-labelledby="brand-dna-enabled-label"
                        title={brandModel?.is_enabled ? 'Turn off Brand DNA' : 'Turn on Brand DNA'}
                    >
                        <span
                            className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                brandModel?.is_enabled ? 'translate-x-5' : 'translate-x-0.5'
                            }`}
                        />
                    </button>
                    {hasActiveVersion && (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-violet-200 bg-violet-50 px-2.5 py-0.5 text-xs font-medium text-violet-900">
                            Active v{activeVersion.version_number}
                        </span>
                    )}
                </div>
            </div>

            {allVersions.length > 0 ? (
                <div className="mt-4 border-t border-slate-100 pt-4">
                    <VersionPanel
                        versions={allVersions}
                        activeVersionId={activeVersion?.id}
                        brandId={brandId}
                        selectedVersionId={selectedVersionId}
                        onSelect={onVersionSelect}
                        isFreePlan={isFreePlan}
                    />
                </div>
            ) : (
                <p className="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-500">No versions yet.</p>
            )}

            {!draftVersion && (
                <div className="mt-4 border-t border-slate-100 pt-4">
                    {isFreePlan ? (
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="text-sm text-slate-500">AI-powered builder requires a paid plan.</span>
                            <Link
                                href={typeof route === 'function' ? route('billing.index') : '/app/billing'}
                                className="inline-flex items-center rounded-md bg-violet-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-violet-500"
                            >
                                Upgrade
                            </Link>
                        </div>
                    ) : (
                        <Link
                            href={researchHref}
                            className="inline-flex items-center rounded-md bg-violet-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-violet-500"
                        >
                            {hasActiveVersion ? 'Start new version' : 'Start Brand Builder'}
                        </Link>
                    )}
                </div>
            )}

            {draftVersion && !isFreePlan && canManage && (
                <div className="mt-4 border-t border-amber-100/80 pt-4">
                    <p className="text-sm text-slate-600">
                        In-progress draft: research and builder work. Published guidelines are unchanged.
                    </p>
                    <button
                        type="button"
                        onClick={() => setShowDiscardDraftConfirm(true)}
                        className="mt-2 text-sm font-medium text-red-600/90 hover:text-red-700"
                    >
                        Remove in-progress draft…
                    </button>
                    <ConfirmDialog
                        open={showDiscardDraftConfirm}
                        onClose={() => setShowDiscardDraftConfirm(false)}
                        onConfirm={() => {
                            setShowDiscardDraftConfirm(false)
                            router.post(
                                typeof route === 'function'
                                    ? route('brands.brand-dna.builder.discard-draft', { brand: brandId })
                                    : `/app/brands/${brandId}/brand-dna/builder/discard-draft`,
                                {},
                                { preserveScroll: true }
                            )
                        }}
                        title="Delete in-progress guidelines?"
                        message="This removes the draft version and any unsaved research or builder progress. Your published brand guidelines (if any) stay live."
                        confirmText="Delete draft"
                        cancelText="Cancel"
                        variant="danger"
                    />
                </div>
            )}
        </section>
    )
}
