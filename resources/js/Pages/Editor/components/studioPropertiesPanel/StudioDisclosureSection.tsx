import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline'
import type { ReactNode } from 'react'

export function StudioDisclosureSection({
    id,
    title,
    subtitle,
    open,
    onOpenChange,
    children,
    className = '',
    headerClassName = '',
    variant = 'default',
}: {
    id: string
    title: string
    subtitle?: string
    open: boolean
    onOpenChange: (next: boolean) => void
    children: ReactNode
    className?: string
    headerClassName?: string
    /** default / muted / utility — flat technical rows (quieter than hero cards) */
    variant?: 'default' | 'muted' | 'utility'
}) {
    const density = variant === 'utility' ? 'py-0.5' : 'py-1'
    const headerTone = `rounded-md border-y-0 border-x-0 border-b border-gray-800/80 bg-transparent px-0 ${density} text-left text-[10px] font-medium text-gray-400 hover:bg-gray-900/35 hover:text-gray-300`
    const expandLabel = open ? `Collapse ${title}` : `Expand ${title}`
    return (
        <div className={className}>
            <button
                type="button"
                onClick={() => onOpenChange(!open)}
                className={`flex w-full items-center justify-between gap-1.5 ${headerTone} ${headerClassName}`}
                aria-expanded={open}
                aria-controls={id}
                aria-label={expandLabel}
                title={expandLabel}
            >
                <span className="min-w-0 flex-1 text-left">
                    <span className="block truncate tracking-tight text-gray-300">{title}</span>
                    {subtitle ? (
                        <span className="mt-0.5 block truncate text-[10px] font-normal leading-snug text-gray-500">
                            {subtitle}
                        </span>
                    ) : null}
                </span>
                <span
                    className="flex h-6 w-6 shrink-0 items-center justify-center rounded border border-gray-800/70 bg-gray-900/30 text-gray-500"
                    aria-hidden
                >
                    {open ? (
                        <ChevronDownIcon className="h-3 w-3" aria-hidden />
                    ) : (
                        <ChevronRightIcon className="h-3 w-3" aria-hidden />
                    )}
                </span>
            </button>
            {open ? (
                <div id={id} className="mt-1.5 space-y-2 border-t border-gray-800/70 pt-2">
                    {children}
                </div>
            ) : null}
        </div>
    )
}
