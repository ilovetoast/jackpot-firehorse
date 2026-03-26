/**
 * Read-only embedded file metadata (EXIF/IPTC/etc.) for the details panel.
 * Data comes from GET .../metadata/all (or editable) via the parent.
 */

/** IPTC Keywords field from registry: normalized_key iptc_keyword, type keyword */
function isIptcKeywordsRow(row) {
    const nk = (row.normalized_key || '').toLowerCase()
    if (nk === 'iptc_keyword') return true
    const ns = (row.namespace || '').toLowerCase()
    return ns === 'iptc' && nk.includes('keyword')
}

/**
 * Split stored keyword strings (comma/semicolon/newline separated) into distinct terms for display as labels.
 */
/** Present snake_case registry keys as readable labels (e.g. `copyright_notice` → "copyright notice"). */
function formatEmbeddedFieldLabel(row) {
    if (row.normalized_key === 'iptc_keyword') return 'Keywords'
    const raw = (row.normalized_key || row.key || '').trim()
    if (!raw) return '—'
    return raw.replace(/_/g, ' ').replace(/\s+/g, ' ').trim()
}

function splitEmbeddedKeywordDisplay(display) {
    if (display == null || display === '') return []
    const str = String(display).trim()
    if (!str) return []
    const parts = str
        .split(/[,;\n\r]+/)
        .map((s) => s.trim())
        .filter(Boolean)
    const seen = new Set()
    const out = []
    for (const p of parts) {
        const key = p.toLowerCase()
        if (!seen.has(key)) {
            seen.add(key)
            out.push(p)
        }
    }
    return out
}

export default function AssetEmbeddedMetadataPanel({ embeddedMetadata, variant = 'default' }) {
    const dark = variant === 'dark'
    return (
        <div className="space-y-4 text-sm">
            {!embeddedMetadata?.has_embedded_metadata && (
                <p className={`italic ${dark ? 'text-neutral-500' : 'text-gray-500'}`}>
                    No embedded file metadata extracted yet, or none available for this file type.
                </p>
            )}
            {embeddedMetadata?.has_embedded_metadata && (
                <>
                    {(embeddedMetadata.extracted_at || embeddedMetadata.schema_version) && (
                        <div className={`text-xs space-y-1 ${dark ? 'text-neutral-500' : 'text-gray-500'}`}>
                            {embeddedMetadata.extracted_at && (
                                <p>
                                    <span className={`font-medium ${dark ? 'text-neutral-400' : 'text-gray-600'}`}>Extracted:</span>{' '}
                                    {new Date(embeddedMetadata.extracted_at).toLocaleString()}
                                </p>
                            )}
                            {embeddedMetadata.schema_version && (
                                <p>
                                    <span className={`font-medium ${dark ? 'text-neutral-400' : 'text-gray-600'}`}>Schema:</span>{' '}
                                    {embeddedMetadata.schema_version}
                                </p>
                            )}
                        </div>
                    )}
                    {Array.isArray(embeddedMetadata.namespaces_present) && embeddedMetadata.namespaces_present.length > 0 && (
                        <div>
                            <p className={`text-xs font-medium mb-1 ${dark ? 'text-neutral-400' : 'text-gray-600'}`}>Namespaces</p>
                            <div className="flex flex-wrap gap-1">
                                {embeddedMetadata.namespaces_present.map((ns) => (
                                    <span
                                        key={ns}
                                        className={`inline-flex px-2 py-0.5 rounded-md text-xs ${dark ? 'bg-neutral-800 text-neutral-200' : 'bg-gray-100 text-gray-800'}`}
                                    >
                                        {ns}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                    {Array.isArray(embeddedMetadata.visible_indexed_metadata) &&
                        embeddedMetadata.visible_indexed_metadata.length > 0 && (
                            <div>
                                <p className={`text-xs font-medium mb-2 ${dark ? 'text-neutral-400' : 'text-gray-600'}`}>Indexed (visible)</p>
                                <dl className="space-y-3">
                                    {embeddedMetadata.visible_indexed_metadata.map((row, idx) => {
                                        const keywords = isIptcKeywordsRow(row)
                                            ? splitEmbeddedKeywordDisplay(row.display)
                                            : null
                                        return (
                                            <div
                                                key={`${row.namespace}-${row.normalized_key}-${row.key}-${idx}`}
                                                className={`flex flex-col md:flex-row md:gap-4 md:items-start border-b pb-3 last:border-0 ${dark ? 'border-neutral-700' : 'border-gray-100'}`}
                                            >
                                                <dt className={dark ? 'sr-only' : `md:w-44 flex-shrink-0 text-gray-500`}>
                                                    <span className="text-xs uppercase tracking-wide text-gray-400">{row.namespace}</span>
                                                    <br />
                                                    <span className="text-gray-600">{formatEmbeddedFieldLabel(row)}</span>
                                                </dt>
                                                <dd
                                                    className={`break-words md:flex-1 min-w-0 ${dark ? 'text-neutral-100' : 'text-gray-900'}`}
                                                    title={dark ? `${row.namespace} · ${formatEmbeddedFieldLabel(row)}` : undefined}
                                                >
                                                    {keywords && keywords.length > 0 ? (
                                                        <ul
                                                            className="flex flex-wrap gap-1.5 list-none m-0 p-0"
                                                            aria-label="IPTC keywords"
                                                        >
                                                            {keywords.map((kw) => (
                                                                <li key={kw}>
                                                                    <span
                                                                        className={`inline-flex max-w-full items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                                            dark
                                                                                ? 'border border-indigo-800 bg-indigo-950/50 text-indigo-100'
                                                                                : 'border border-indigo-100 bg-indigo-50 text-indigo-900'
                                                                        }`}
                                                                        title={kw}
                                                                    >
                                                                        <span className="truncate">{kw}</span>
                                                                    </span>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    ) : keywords && keywords.length === 0 ? (
                                                        <span className={dark ? 'text-neutral-500' : 'text-gray-400'}>—</span>
                                                    ) : (
                                                        <span className="font-medium">{row.display ?? '—'}</span>
                                                    )}
                                                </dd>
                                            </div>
                                        )
                                    })}
                                </dl>
                            </div>
                        )}
                    {Array.isArray(embeddedMetadata.visible_indexed_metadata) &&
                        embeddedMetadata.visible_indexed_metadata.length === 0 &&
                        embeddedMetadata.has_embedded_metadata && (
                            <p className={`text-xs ${dark ? 'text-neutral-500' : 'text-gray-500'}`}>
                                Allowlisted visible index rows will appear here when configured.
                            </p>
                        )}
                </>
            )}
        </div>
    )
}
