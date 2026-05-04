import { PencilIcon, RectangleStackIcon, GlobeAltIcon } from '@heroicons/react/24/outline'

/**
 * Collection row for asset metadata (drawer dl layout or stacked drawer column).
 */
export default function AssetMetadataCollectionField({
    collectionDisplay,
    readOnly = false,
    workspaceMode = false,
    brandPrimary = '#6366f1',
    /** 'metadataRow' = dt/dd row inside <dl>; 'drawerColumn' = stacked block in drawer (e.g. above tags) */
    variant = 'metadataRow',
}) {
    if (
        !collectionDisplay ||
        !(collectionDisplay.inlineContent || Array.isArray(collectionDisplay.collections))
    ) {
        return null
    }

    const badgeBg = brandPrimary.startsWith('#') ? `${brandPrimary}18` : `#${brandPrimary}18`

    const valueBlock = collectionDisplay.inlineContent ? (
        collectionDisplay.inlineContent
    ) : collectionDisplay.loading ? (
        <span className="text-gray-400">Loading…</span>
    ) : collectionDisplay.collections.length > 0 ? (
        <div className="flex flex-wrap items-center gap-2">
            {collectionDisplay.collections.map((c) => (
                <span
                    key={c.id}
                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium"
                    style={{ backgroundColor: badgeBg, color: brandPrimary }}
                    title={c.is_public ? 'Public collection' : undefined}
                >
                    <RectangleStackIcon className="h-3 w-3" aria-hidden="true" />
                    {c.name}
                    {c.is_public && (
                        <GlobeAltIcon className="h-3 w-3 opacity-80" aria-hidden="true" title="Public" />
                    )}
                </span>
            ))}
        </div>
    ) : (
        <span className="text-gray-400">No collections</span>
    )

    if (variant === 'drawerColumn') {
        const editBtn =
            !readOnly &&
            !collectionDisplay.inlineContent &&
            collectionDisplay.showEditButton !== false &&
            typeof collectionDisplay.onEdit === 'function' &&
            !workspaceMode ? (
                <button
                    type="button"
                    onClick={collectionDisplay.onEdit}
                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
                    style={{ color: brandPrimary, ['--tw-ring-color']: brandPrimary }}
                >
                    <PencilIcon className="h-3 w-3" />
                    {collectionDisplay.collections.length > 0 ? 'Edit' : 'Add'}
                </button>
            ) : null

        return (
            <div className="min-w-0 space-y-2">
                <div className="text-sm font-medium text-gray-700">Collection</div>
                <div className="min-w-0 text-sm font-semibold text-gray-900">{valueBlock}</div>
                {editBtn ? <div>{editBtn}</div> : null}
            </div>
        )
    }

    return (
        <div
            key="collection-field"
            className={`flex flex-col md:flex-row ${
                collectionDisplay.inlineContent ? 'md:items-center' : 'md:items-start'
            } md:justify-between gap-1 md:gap-4 md:flex-nowrap ${
                workspaceMode &&
                typeof collectionDisplay.onEdit === 'function' &&
                !collectionDisplay.inlineContent
                    ? 'cursor-pointer rounded-lg -mx-2 px-2 py-1.5 transition-colors hover:bg-gray-50'
                    : ''
            }`}
            onClick={
                workspaceMode &&
                typeof collectionDisplay.onEdit === 'function' &&
                !collectionDisplay.inlineContent
                    ? (e) => {
                          if (e.target.closest('button, a, input, select, [role="checkbox"]')) return
                          collectionDisplay.onEdit()
                      }
                    : undefined
            }
            role={
                workspaceMode &&
                typeof collectionDisplay.onEdit === 'function' &&
                !collectionDisplay.inlineContent
                    ? 'button'
                    : undefined
            }
            tabIndex={
                workspaceMode &&
                typeof collectionDisplay.onEdit === 'function' &&
                !collectionDisplay.inlineContent
                    ? 0
                    : undefined
            }
            onKeyDown={
                workspaceMode &&
                typeof collectionDisplay.onEdit === 'function' &&
                !collectionDisplay.inlineContent
                    ? (e) => {
                          if (e.key === 'Enter' || e.key === ' ') {
                              e.preventDefault()
                              collectionDisplay.onEdit()
                          }
                      }
                    : undefined
            }
        >
            <div className="flex flex-col md:flex-row md:min-w-0 md:flex-1 md:flex-wrap md:items-start md:gap-4">
                <dt className="mb-1 flex items-center text-sm text-gray-500 md:mb-0 md:w-32 md:flex-shrink-0 md:items-start">
                    <span className="flex flex-wrap items-center gap-1 md:gap-1.5">Collection</span>
                </dt>
                <dd className="w-full min-w-0 break-words text-sm font-semibold text-gray-900 md:flex-1">
                    {valueBlock}
                </dd>
            </div>
            {!readOnly &&
                !collectionDisplay.inlineContent &&
                collectionDisplay.showEditButton !== false &&
                typeof collectionDisplay.onEdit === 'function' &&
                !workspaceMode && (
                    <div className="ml-auto flex flex-shrink-0 items-center gap-2 self-start md:ml-0 md:self-auto">
                        <button
                            type="button"
                            onClick={collectionDisplay.onEdit}
                            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
                            style={{ color: brandPrimary, ['--tw-ring-color']: brandPrimary }}
                        >
                            <PencilIcon className="h-3 w-3" />
                            {collectionDisplay.collections.length > 0 ? 'Edit' : 'Add'}
                        </button>
                    </div>
                )}
        </div>
    )
}
