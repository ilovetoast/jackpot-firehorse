/**
 * Collections Sidebar (C4 read-only; C5 add Create button).
 * Lists collections for the current brand; selection is URL-driven (?collection=id).
 */
import { router } from '@inertiajs/react'
import { RectangleStackIcon, PlusIcon } from '@heroicons/react/24/outline'

export default function CollectionsSidebar({
    collections = [],
    selectedCollectionId = null,
    sidebarColor = '#1f2937',
    textColor = '#ffffff',
    canCreateCollection = false,
    onCreateCollection = null,
}) {
    const isLight = textColor === '#000000'
    const mutedStyle = { color: isLight ? 'rgba(0, 0, 0, 0.6)' : 'rgba(255, 255, 255, 0.6)' }
    const buttonBg = isLight ? 'bg-black/10 hover:bg-black/15' : 'bg-white/20 hover:bg-white/30'
    const buttonText = isLight ? 'text-gray-900' : 'text-white'

    const handleSelectCollection = (id) => {
        router.get('/app/collections', { collection: id }, { preserveState: true })
    }

    return (
        <div className="flex flex-col w-72 h-full flex-shrink-0" style={{ backgroundColor: sidebarColor }}>
            <div className="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
                <nav className="mt-5 flex-1 px-2 space-y-1">
                    <div className="px-3 py-2">
                        <div className="flex items-center justify-between px-3">
                            <h3 className="text-xs font-semibold uppercase tracking-wider" style={mutedStyle}>
                                Collections
                            </h3>
                            {canCreateCollection && onCreateCollection && (
                                <button
                                    type="button"
                                    onClick={onCreateCollection}
                                    className={`rounded p-1.5 ${buttonBg} ${buttonText} focus:outline-none focus:ring-2 focus:ring-white/50`}
                                    title="Create collection"
                                >
                                    <PlusIcon className="h-4 w-4" />
                                </button>
                            )}
                        </div>
                        <div className="mt-2 space-y-1">
                            {collections.length === 0 ? (
                                <div className="px-3 py-2 text-sm" style={mutedStyle}>
                                    No collections yet
                                </div>
                            ) : (
                                collections.map((c) => {
                                    const isActive = selectedCollectionId != null && c.id === selectedCollectionId
                                    return (
                                        <button
                                            key={c.id}
                                            type="button"
                                            onClick={() => handleSelectCollection(c.id)}
                                            className={`w-full text-left px-3 py-2 rounded-md text-sm font-medium flex items-center gap-2 ${
                                                isActive
                                                    ? isLight
                                                        ? 'bg-black/10 text-black'
                                                        : 'bg-white/20 text-white'
                                                    : isLight
                                                        ? 'text-gray-800 hover:bg-black/5'
                                                        : 'text-white/90 hover:bg-white/10'
                                            }`}
                                        >
                                            <RectangleStackIcon className="h-4 w-4 flex-shrink-0" />
                                            <span className="truncate">{c.name}</span>
                                        </button>
                                    )
                                })
                            )}
                        </div>
                    </div>
                </nav>
            </div>
        </div>
    )
}
