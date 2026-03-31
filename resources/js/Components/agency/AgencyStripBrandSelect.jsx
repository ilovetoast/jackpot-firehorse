import { useMemo, useRef, useEffect, useState } from 'react'
import { ChevronDownIcon } from '@heroicons/react/24/outline'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'

/**
 * Compact brand-only switcher for the agency workspace strip (agency tenant + linked client brands).
 */
export default function AgencyStripBrandSelect({
    brands = [],
    brandColor = '#6366f1',
    isTransparentVariant = false,
    currentTenantId = null,
    currentBrandId = null,
}) {
    const [open, setOpen] = useState(false)
    const ref = useRef(null)

    useEffect(() => {
        const onDoc = (e) => {
            if (!ref.current?.contains(e.target)) {
                setOpen(false)
            }
        }
        document.addEventListener('mousedown', onDoc)
        return () => document.removeEventListener('mousedown', onDoc)
    }, [])

    const list = useMemo(() => (Array.isArray(brands) ? brands : []), [brands])

    const currentLabel = useMemo(() => {
        const row = list.find((b) => b.tenant_id === currentTenantId && b.id === currentBrandId)
        return row?.name ?? 'Brand'
    }, [list, currentTenantId, currentBrandId])

    if (list.length < 2) {
        return null
    }

    const openBrand = (tenantId, brandId) => {
        if (tenantId === currentTenantId && brandId === currentBrandId) {
            setOpen(false)
            return
        }
        switchCompanyWorkspace({
            companyId: tenantId,
            brandId,
            redirect: '/app/overview',
        })
    }

    return (
        <div className="relative shrink-0" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-label={`Switch brand, current ${currentLabel}`}
                className={`inline-flex max-w-[10rem] items-center gap-1 rounded-lg px-2 py-1.5 text-left text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 sm:max-w-[14rem] sm:text-sm ${
                    isTransparentVariant
                        ? 'bg-white/10 text-white hover:bg-white/15 focus-visible:ring-white/40 focus-visible:ring-offset-transparent'
                        : 'bg-slate-50 text-slate-800 shadow-sm ring-1 ring-slate-200/90 hover:bg-slate-100 focus-visible:ring-indigo-500 focus-visible:ring-offset-white'
                }`}
                style={!isTransparentVariant ? { color: brandColor } : undefined}
            >
                <span className="min-w-0 flex-1 truncate" title={currentLabel}>
                    {currentLabel}
                </span>
                <ChevronDownIcon className="h-3.5 w-3.5 shrink-0 opacity-70 sm:h-4 sm:w-4" aria-hidden />
            </button>
            {open && (
                <ul
                    role="listbox"
                    className={`absolute right-0 z-[60] mt-1 max-h-60 min-w-[12rem] overflow-y-auto rounded-lg py-1 shadow-lg ring-1 ring-black/5 ${
                        isTransparentVariant ? 'bg-slate-900 text-white' : 'bg-white text-slate-900'
                    }`}
                >
                    {list.map((b) => {
                        const isActive = b.tenant_id === currentTenantId && b.id === currentBrandId
                        return (
                            <li key={`${b.tenant_id}-${b.id}`} role="option" aria-selected={isActive}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        openBrand(b.tenant_id, b.id)
                                        setOpen(false)
                                    }}
                                    className={`flex w-full items-center px-3 py-2 text-left text-sm transition ${
                                        isTransparentVariant
                                            ? isActive
                                                ? 'bg-white/15 text-white'
                                                : 'text-white/90 hover:bg-white/10'
                                            : isActive
                                              ? 'bg-indigo-50 font-medium text-indigo-900'
                                              : 'text-slate-800 hover:bg-slate-50'
                                    }`}
                                    title={b.name}
                                >
                                    <span className="min-w-0 flex-1 truncate">{b.name}</span>
                                </button>
                            </li>
                        )
                    })}
                </ul>
            )}
        </div>
    )
}
