/**
 * Compact floating tray shown when the upload dialog is minimized.
 * Read-only progress list; finalize / close actions are composed by the parent.
 */

export function FloatingUploadProgressTray({ uploads, onExpand, children }) {
    if (!uploads?.length) return null

    return (
        <div className="w-80 rounded-xl border border-gray-200 bg-white p-4 shadow-xl">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium text-gray-900">
                    Uploading {uploads.length} asset{uploads.length > 1 ? 's' : ''}
                </p>
                <button
                    type="button"
                    onClick={onExpand}
                    className="text-xs font-medium text-primary hover:underline"
                >
                    Expand
                </button>
            </div>

            <div className="mt-3 space-y-2">
                {uploads.map((file) => (
                    <div key={file.id}>
                        <p className="truncate text-xs text-gray-700">{file.name}</p>
                        <div className="mt-0.5 h-1 w-full overflow-hidden rounded-full bg-gray-200">
                            <div
                                className="h-1 rounded-full bg-primary transition-[width] duration-300 ease-out"
                                style={{ width: `${file.progress}%` }}
                            />
                        </div>
                    </div>
                ))}
            </div>

            {children ? <div className="mt-3 border-t border-gray-100 pt-3">{children}</div> : null}
        </div>
    )
}
