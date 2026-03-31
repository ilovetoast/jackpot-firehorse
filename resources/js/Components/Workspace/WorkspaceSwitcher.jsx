import { useState, useMemo, useRef, useEffect } from 'react'
import { ChevronDownIcon } from '@heroicons/react/24/solid'
import { Link } from '@inertiajs/react'
import WorkspacePopover from './WorkspacePopover'
import { switchCompanyWorkspace } from '../../utils/workspaceCompanySwitch'

/**
 * Persistent workspace (company) context control — opens a popover to switch direct company memberships.
 *
 * @param {object} props
 * @param {{ id?: number|string, name?: string, type?: string } | null} props.currentWorkspace — id, name, type ('agency_workspace' | 'company')
 * @param {Array<{ id: number|string, name: string, is_active?: boolean }>} props.availableWorkspaces — direct workspaces only (caller filters)
 * @param {string} [props.accentColor]
 * @param {'bar'|'strip'} [props.variant] — bar = standalone row; strip = inside agency strip
 * @param {boolean} [props.isTransparentVariant]
 */
export default function WorkspaceSwitcher({
    currentWorkspace = null,
    availableWorkspaces = [],
    accentColor = '#6366f1',
    variant = 'bar',
    isTransparentVariant = false,
}) {
    const [switching, setSwitching] = useState(false)
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

    const list = Array.isArray(availableWorkspaces) ? availableWorkspaces : []
    const hasMultiple = list.length > 1

    const typeLabel = useMemo(() => {
        if (!currentWorkspace?.type) return 'Company'
        if (currentWorkspace.type === 'agency_workspace' || currentWorkspace.type === 'agency') {
            return 'Agency Workspace'
        }
        return 'Company'
    }, [currentWorkspace?.type])

    const displayName = currentWorkspace?.name?.trim() || ''
    const showPlaceholder = !displayName

    const handleSelect = (companyId) => {
        if (switching || String(companyId) === String(currentWorkspace?.id)) {
            return
        }
        setOpen(false)
        setSwitching(true)
        switchCompanyWorkspace({ companyId, redirect: '/app/overview' })
    }

    const textMuted = isTransparentVariant ? 'text-white/50' : 'text-slate-500'
    const textMain = isTransparentVariant ? 'text-white' : 'text-slate-900'
    const buttonIdle = isTransparentVariant
        ? 'hover:bg-white/10 focus-visible:ring-white/30'
        : 'hover:bg-slate-100/90 focus-visible:ring-slate-300'

    const inner = (
        <>
            <span className={`block max-w-[200px] truncate text-left text-sm font-semibold leading-tight sm:max-w-[240px] sm:text-[15px] ${textMain}`}>
                {showPlaceholder ? 'Select workspace' : displayName}
            </span>
            <span className={`mt-0.5 block text-[10px] font-medium leading-none ${textMuted}`}>{typeLabel}</span>
        </>
    )

    if (showPlaceholder) {
        return (
            <div
                className={`flex min-w-0 flex-col ${variant === 'bar' ? 'justify-center' : ''}`}
                data-workspace-switcher="true"
            >
                <Link
                    href="/app/companies"
                    className={`inline-flex max-w-full flex-col rounded-md px-1 py-0.5 text-left ${buttonIdle} focus:outline-none focus-visible:ring-2`}
                >
                    {inner}
                </Link>
            </div>
        )
    }

    if (!hasMultiple) {
        return (
            <div className="flex min-w-0 flex-col justify-center" data-workspace-switcher="true">
                <div className="max-w-[220px] sm:max-w-[260px]">{inner}</div>
            </div>
        )
    }

    return (
        <div className="relative flex min-w-0 items-center" ref={ref} data-workspace-switcher="true">
            <button
                type="button"
                disabled={switching}
                onClick={() => setOpen((o) => !o)}
                className={`group inline-flex max-w-full min-w-0 items-center gap-1.5 rounded-md px-1.5 py-1 text-left transition focus:outline-none focus-visible:ring-2 ${buttonIdle} disabled:cursor-wait disabled:opacity-70`}
                aria-expanded={open}
                aria-haspopup="dialog"
                aria-label={`Workspace: ${displayName}. Open to switch company.`}
            >
                <span className="min-w-0 flex-1">{inner}</span>
                {switching ? (
                    <span
                        className="inline-flex h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent opacity-70"
                        aria-hidden
                    />
                ) : (
                    <ChevronDownIcon
                        className={`h-4 w-4 shrink-0 opacity-60 transition group-hover:opacity-90 ${textMain}`}
                        aria-hidden
                    />
                )}
            </button>
            {open && (
                <div className="absolute left-0 top-full z-[80] mt-1">
                    <WorkspacePopover
                        currentName={displayName}
                        currentId={currentWorkspace.id}
                        otherWorkspaces={list}
                        accentColor={accentColor}
                        switching={switching}
                        onSelect={handleSelect}
                    />
                </div>
            )}
        </div>
    )
}
