import { useEffect, useCallback, useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import CompanySelector from './CompanySelector'
import BrandSelector from './BrandSelector'

export default function BrandSwitchModal({ onClose, context }) {
    const { theme } = usePage().props

    const hasMultipleCompanies = context?.is_multi_company
    const hasMultipleBrands = context?.is_multi_brand

    const currentTenantId = context?.tenant?.id

    const { currentBrands, otherCompanies } = useMemo(() => {
        if (!hasMultipleCompanies) {
            return { currentBrands: null, otherCompanies: null }
        }

        const companies = context?.available_companies || []
        const current = companies.find(c => c.id === currentTenantId)
        const others = companies.filter(c => c.id !== currentTenantId)

        return {
            currentBrands: current || null,
            otherCompanies: others.length > 0 ? others : null,
        }
    }, [hasMultipleCompanies, context?.available_companies, currentTenantId])

    const handleKeyDown = useCallback((e) => {
        if (e.key === 'Escape') onClose()
    }, [onClose])

    useEffect(() => {
        document.addEventListener('keydown', handleKeyDown)
        return () => document.removeEventListener('keydown', handleKeyDown)
    }, [handleKeyDown])

    const handleBackdropClick = (e) => {
        if (e.target === e.currentTarget) onClose()
    }

    const renderContent = () => {
        if (hasMultipleCompanies && currentBrands && otherCompanies) {
            return (
                <div className="space-y-8">
                    {/* Current company brands first */}
                    <div>
                        <p className="text-[10px] uppercase tracking-[0.2em] text-white/35 mb-4 px-1">
                            Current Workspace
                        </p>
                        <div className="flex items-center gap-3 p-4 rounded-xl bg-white/[0.04] border border-white/[0.08]">
                            <div
                                className="h-9 w-9 rounded-lg flex items-center justify-center text-sm font-semibold text-white"
                                style={{ background: `linear-gradient(135deg, ${theme?.colors?.primary || '#7c3aed'}CC, ${theme?.colors?.secondary || '#8b5cf6'}88)` }}
                            >
                                {currentBrands.name?.charAt(0)?.toUpperCase()}
                            </div>
                            <span className="text-sm font-medium text-white/80">{currentBrands.name}</span>
                        </div>
                    </div>

                    {/* Other companies */}
                    <div>
                        <p className="text-[10px] uppercase tracking-[0.2em] text-white/35 mb-4 px-1">
                            Other Workspaces
                        </p>
                        <CompanySelector companies={otherCompanies} variant="embedded" />
                    </div>
                </div>
            )
        }

        if (hasMultipleCompanies) {
            return <CompanySelector companies={context.available_companies} variant="modal" />
        }

        if (hasMultipleBrands) {
            return (
                <BrandSelector
                    brands={context.available_brands}
                    tenant={context.tenant}
                    brandPickerScope={context.brand_picker_scope}
                    tenantMemberWithoutBrands={Boolean(context.tenant_member_without_brands)}
                    variant="modal"
                />
            )
        }

        return (
            <div className="text-center py-8">
                <p className="text-white/50">No other workspaces available.</p>
            </div>
        )
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center animate-fade-in"
            style={{ animationDuration: '200ms' }}
            onClick={handleBackdropClick}
            role="dialog"
            aria-modal="true"
        >
            {/* Backdrop */}
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" />

            {/* Modal panel */}
            <div className="relative z-10 w-full max-w-2xl max-h-[80vh] overflow-y-auto mx-4">
                <div className="bg-white/[0.04] border border-white/[0.08] rounded-2xl p-8 backdrop-blur-xl">
                    {/* Close button */}
                    <button
                        type="button"
                        onClick={onClose}
                        className="absolute top-4 right-4 p-2 rounded-lg text-white/40 hover:text-white/70 hover:bg-white/[0.06] transition-all duration-300"
                        aria-label="Close"
                    >
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>

                    {renderContent()}
                </div>
            </div>
        </div>
    )
}
