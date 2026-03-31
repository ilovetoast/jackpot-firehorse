import { CheckIcon } from '@heroicons/react/24/solid'
import { BuildingOffice2Icon } from '@heroicons/react/24/outline'

/**
 * Popover body for switching company workspace (direct memberships only).
 *
 * @param {object} props
 * @param {string} props.currentName
 * @param {number|string} props.currentId
 * @param {Array<{ id: number|string, name: string, is_active?: boolean }>} props.otherWorkspaces
 * @param {(id: number|string) => void} props.onSelect
 * @param {string} [props.accentColor]
 * @param {boolean} [props.switching]
 */
export default function WorkspacePopover({
    currentName,
    currentId,
    otherWorkspaces = [],
    onSelect,
    accentColor = '#6366f1',
    switching = false,
}) {
    const others = (otherWorkspaces || []).filter((w) => String(w.id) !== String(currentId))

    return (
        <div className="w-[min(20rem,calc(100vw-2rem))] rounded-lg border border-gray-200 bg-white py-2 shadow-lg ring-1 ring-black/5">
            <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Switch Workspace</p>
            <div className="border-b border-gray-100 px-3 pb-2">
                <p className="text-[10px] font-medium uppercase tracking-wide text-gray-400">Current</p>
                <div className="mt-1 flex items-start gap-2 rounded-md bg-gray-50 px-2 py-1.5">
                    <CheckIcon className="mt-0.5 h-4 w-4 shrink-0" style={{ color: accentColor }} aria-hidden />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-gray-900">{currentName}</p>
                    </div>
                </div>
            </div>
            {others.length > 0 && (
                <div className="pt-2">
                    <p className="px-3 pb-1 text-[10px] font-medium uppercase tracking-wide text-gray-400">Other Workspaces</p>
                    <ul className="max-h-[min(40vh,280px)] overflow-y-auto py-0.5">
                        {others.map((w) => (
                            <li key={w.id}>
                                <button
                                    type="button"
                                    disabled={switching}
                                    onClick={() => onSelect(w.id)}
                                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-800 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <BuildingOffice2Icon className="h-4 w-4 shrink-0 text-gray-400" aria-hidden />
                                    <span className="min-w-0 flex-1 truncate font-medium">{w.name}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    )
}
