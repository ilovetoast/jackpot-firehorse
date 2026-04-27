import { useMemo } from 'react'
import { Listbox, Transition } from '@headlessui/react'
import { CheckIcon, ChevronDownIcon } from '@heroicons/react/20/solid'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'

function optionKey(b) {
    if (!b || b.tenant_id == null || b.id == null) {
        return ''
    }

    return `${b.tenant_id}-${b.id}`
}

/**
 * Compact brand-only switcher rendered INSIDE the agency nav (top strip above main nav).
 * Headless UI Listbox — not a native <select> — with Tailwind-styled panel and scrollbar.
 *
 * LOCK — PARENTAGE:
 *   - Must only be mounted from `AppNav` when `agencyStripVisible` is true (see `AppNav.jsx` AGENCY NAV contract).
 *   - Do not import this into basic-company layouts or generic headers — those users are not agency-context.
 *
 * LOCK — DATA:
 *   - `brands` is `auth.agency_flat_brands` (agency portfolio). Backend should only populate for agency workflows.
 *
 * LOCK — SEMANTICS:
 *   - Switching tenant/brand here is agency portfolio navigation (agency tenant + clients tied to the agency).
 *   - Agency-managed client companies are part of that portfolio; this is not a “basic company” switcher.
 */
export default function AgencyStripBrandSelect({
    brands = [],
    brandColor = '#6366f1',
    isTransparentVariant = false,
    currentTenantId = null,
    currentBrandId = null,
}) {
    const list = useMemo(() => (Array.isArray(brands) ? brands : []), [brands])

    const currentLabel = useMemo(() => {
        const row = list.find((b) => b.tenant_id === currentTenantId && b.id === currentBrandId)
        return row?.name ?? 'Brand'
    }, [list, currentTenantId, currentBrandId])

    const selectedValue = useMemo(() => {
        const row = list.find((b) => b.tenant_id === currentTenantId && b.id === currentBrandId)
        if (row) {
            return optionKey(row)
        }

        return list[0] ? optionKey(list[0]) : ''
    }, [list, currentTenantId, currentBrandId])

    if (list.length < 2) {
        return null
    }

    const openBrand = (tenantId, brandId) => {
        if (tenantId === currentTenantId && brandId === currentBrandId) {
            return
        }
        switchCompanyWorkspace({
            companyId: tenantId,
            brandId,
            redirect: '/app/overview',
        })
    }

    const handleListboxChange = (key) => {
        if (!key) {
            return
        }
        const b = list.find((row) => optionKey(row) === key)
        if (b) {
            openBrand(b.tenant_id, b.id)
        }
    }

    const buttonBase = isTransparentVariant
        ? 'bg-white/10 text-white hover:bg-white/15 focus:ring-white/40 focus:ring-offset-transparent'
        : 'bg-slate-50 text-slate-800 shadow-sm ring-1 ring-slate-200/90 hover:bg-slate-100 focus:ring-indigo-500 focus:ring-offset-white'

    const panelBase = isTransparentVariant
        ? 'border border-white/10 bg-slate-900/98 text-white shadow-xl ring-1 ring-black/20 backdrop-blur-sm'
        : 'border border-slate-200/90 bg-white text-slate-900 shadow-xl ring-1 ring-black/5'

    const scrollBar = isTransparentVariant ? 'scrollbar-cinematic' : 'scrollbar-thin'

    return (
        <div className="flex shrink-0 items-center gap-2 sm:gap-2.5">
            <Listbox value={selectedValue} onChange={handleListboxChange}>
                <div className="relative">
                    <Listbox.Button
                        className={`group inline-flex max-w-[10rem] min-w-0 items-center gap-1 rounded-lg px-2 py-1.5 text-left text-xs font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-1 sm:max-w-[14rem] sm:text-sm ${buttonBase}`}
                        style={!isTransparentVariant ? { color: brandColor } : undefined}
                        aria-label={`Agency brand: ${currentLabel}. Open to switch client brand.`}
                    >
                        <span className="min-w-0 flex-1 truncate" title={currentLabel}>
                            {currentLabel}
                        </span>
                        <ChevronDownIcon
                            className="h-3.5 w-3.5 shrink-0 opacity-70 group-hover:opacity-100 sm:h-4 sm:w-4"
                            aria-hidden
                        />
                    </Listbox.Button>
                    <Transition
                        enter="transition ease-out duration-100"
                        enterFrom="opacity-0 scale-95"
                        enterTo="opacity-100 scale-100"
                        leave="transition ease-in duration-75"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                    >
                        <Listbox.Options
                            className={`absolute right-0 z-[160] mt-1 max-h-60 min-w-[12rem] max-w-[min(100vw-2rem,20rem)] overflow-y-auto rounded-lg py-1 focus:outline-none ${scrollBar} ${panelBase}`}
                        >
                            {list.map((b) => {
                                const v = optionKey(b)
                                return (
                                    <Listbox.Option
                                        key={v}
                                        value={v}
                                        className="relative cursor-pointer select-none text-sm data-[focus]:outline-none"
                                    >
                                        {({ active, selected }) => (
                                            <div
                                                className={`flex w-full items-center py-2 pl-2.5 pr-8 ${
                                                    isTransparentVariant
                                                        ? active || selected
                                                            ? 'bg-white/10 text-white'
                                                            : 'text-white/90'
                                                        : active || selected
                                                          ? 'bg-indigo-50 text-indigo-900'
                                                          : 'text-slate-800'
                                                } ${selected && !isTransparentVariant ? 'font-medium' : ''} ${selected && isTransparentVariant ? 'font-medium text-white' : ''}`}
                                            >
                                                {selected && (
                                                    <span
                                                        className={`absolute right-2 top-1/2 -translate-y-1/2 ${
                                                            isTransparentVariant ? 'text-white/90' : 'text-indigo-500'
                                                        }`}
                                                    >
                                                        <CheckIcon className="h-4 w-4" aria-hidden />
                                                    </span>
                                                )}
                                                <span className="min-w-0 flex-1 truncate" title={b.name}>
                                                    {b.name}
                                                </span>
                                            </div>
                                        )}
                                    </Listbox.Option>
                                )
                            })}
                        </Listbox.Options>
                    </Transition>
                </div>
            </Listbox>
            <span
                className={`hidden max-w-[4.5rem] select-none sm:inline sm:max-w-none ${
                    isTransparentVariant ? 'text-[10px] font-semibold uppercase tracking-wider text-white/50' : 'text-[10px] font-semibold uppercase tracking-wider text-slate-500'
                }`}
            >
                Agency
            </span>
        </div>
    )
}
