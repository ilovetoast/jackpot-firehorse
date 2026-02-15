/**
 * BatchNamingBar - Optional batch naming for multiple uploads
 *
 * When 2+ files are selected, shows an option to apply a naming convention:
 * - Base name input (e.g., "Photo Shoot XY")
 * - Live preview of resulting filenames
 * - Apply button to update all files
 *
 * Single file: no change. Multiple files: optional, non-destructive.
 */

import { useState, useMemo } from 'react'
import { DocumentDuplicateIcon } from '@heroicons/react/24/outline'

function slugify(str) {
    if (!str || typeof str !== 'string') return 'untitled'
    return str
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 100) || 'untitled'
}

function getExtension(filename) {
    if (!filename) return ''
    const lastDot = filename.lastIndexOf('.')
    if (lastDot === -1 || lastDot === filename.length - 1) return ''
    return filename.substring(lastDot + 1).toLowerCase()
}

function getIndexPadding(count) {
    if (count <= 9) return 1
    if (count <= 99) return 2
    return 3
}

export default function BatchNamingBar({ items, onApply, disabled = false }) {
    const [enabled, setEnabled] = useState(false)
    const [baseName, setBaseName] = useState('')
    const [previewExpanded, setPreviewExpanded] = useState(false)

    const previewNames = useMemo(() => {
        if (!baseName.trim()) return []
        const slug = slugify(baseName.trim())
        const padLen = getIndexPadding(items.length)
        return items.map((item, i) => {
            const ext = getExtension(item.originalFilename || item.file?.name || '')
            const indexStr = String(i + 1).padStart(padLen, '0')
            const name = ext ? `${slug}-${indexStr}.${ext}` : `${slug}-${indexStr}`
            return { clientId: item.clientId, name, index: i + 1 }
        })
    }, [items, baseName])

    const handleApply = () => {
        if (!baseName.trim() || previewNames.length === 0) return
        const slug = slugify(baseName.trim())
        const padLen = getIndexPadding(items.length)
        const updates = items.map((item, i) => {
            const ext = getExtension(item.originalFilename || item.file?.name || '')
            const indexStr = String(i + 1).padStart(padLen, '0')
            const resolvedFilename = ext ? `${slug}-${indexStr}.${ext}` : `${slug}-${indexStr}`
            const title = `${baseName.trim()} ${i + 1}`
            return { clientId: item.clientId, resolvedFilename, title }
        })
        onApply(updates)
        setEnabled(false)
        setBaseName('')
    }

    if (items.length < 2) return null

    return (
        <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
            <div className="flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        checked={enabled}
                        onChange={(e) => setEnabled(e.target.checked)}
                        disabled={disabled}
                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <span className="text-sm font-medium text-gray-700">
                        Batch naming
                    </span>
                </label>
                {enabled && (
                    <>
                        <input
                            type="text"
                            value={baseName}
                            onChange={(e) => setBaseName(e.target.value)}
                            placeholder="e.g. Photo Shoot XY"
                            disabled={disabled}
                            className="flex-1 min-w-[160px] rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        {baseName.trim() && (
                            <>
                                <button
                                    type="button"
                                    onClick={handleApply}
                                    disabled={disabled}
                                    className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                                >
                                    Apply to all
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setPreviewExpanded(!previewExpanded)}
                                    className="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700"
                                >
                                    <DocumentDuplicateIcon className="h-4 w-4" />
                                    {previewExpanded ? 'Hide preview' : 'Preview'}
                                </button>
                            </>
                        )}
                    </>
                )}
            </div>
            {enabled && baseName.trim() && previewExpanded && previewNames.length > 0 && (
                <div className="mt-3 pt-3 border-t border-gray-200">
                    <p className="text-xs font-medium text-gray-500 mb-2">
                        Files will be named:
                    </p>
                    <ul className="space-y-1 max-h-32 overflow-y-auto">
                        {previewNames.map((p) => (
                            <li
                                key={p.clientId}
                                className="text-xs font-mono text-gray-600 truncate"
                            >
                                {p.name}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    )
}
