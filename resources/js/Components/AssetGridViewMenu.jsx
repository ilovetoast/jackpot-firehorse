/**
 * Desktop overflow: bundles sort + view controls in a single popover (Headless UI).
 */
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react'
import { ViewColumnsIcon } from '@heroicons/react/24/outline'

export default function AssetGridViewMenu({
    primaryColor = '#6366f1',
    className = '',
    children,
}) {
    return (
        <Popover className={`relative ${className}`}>
            <PopoverButton
                type="button"
                className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset motion-safe:transition-colors motion-safe:duration-200 motion-reduce:transition-none"
                style={{ '--tw-ring-color': primaryColor }}
                aria-label="View and sort options"
            >
                <ViewColumnsIcon className="h-4 w-4 text-gray-500" aria-hidden />
                <span>View</span>
            </PopoverButton>
            <PopoverPanel
                transition
                anchor="bottom end"
                className="z-[210] w-[min(calc(100vw-1.5rem),20rem)] [--anchor-gap:6px] rounded-xl border border-gray-200 bg-white/95 p-3 shadow-2xl ring-1 ring-black/5 backdrop-blur-md motion-safe:transition motion-safe:duration-200 motion-safe:ease-out data-[closed]:opacity-0 motion-reduce:transition-none motion-reduce:data-[closed]:opacity-100"
            >
                <div className="flex flex-col gap-3">{children}</div>
            </PopoverPanel>
        </Popover>
    )
}
