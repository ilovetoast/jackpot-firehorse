/**
 * "To" field for download share-email: debounced suggestions from server.
 * History (own sends) for everyone; tenant directory only when API sets directory_available.
 */
import { useCallback, useEffect, useRef, useState } from 'react'

const DEBOUNCE_MS = 280

function dedupeHistory(history) {
  const seen = new Set()
  const out = []
  for (const h of history || []) {
    const email = (h.email || '').toLowerCase()
    if (!email || seen.has(email)) continue
    seen.add(email)
    out.push({ email: h.email, name: null })
  }
  return out
}

function dedupeDirectory(directory, excludeEmails) {
  const exclude = new Set((excludeEmails || []).map((e) => e.toLowerCase()))
  const seen = new Set()
  const out = []
  for (const d of directory || []) {
    const email = (d.email || '').toLowerCase()
    if (!email || seen.has(email) || exclude.has(email)) continue
    seen.add(email)
    out.push({ email: d.email, name: d.name || null })
  }
  return out
}

export default function ShareEmailToField({
  id,
  value,
  onChange,
  disabled = false,
  error,
  label = 'To',
  labelClassName = 'block text-xs font-medium text-slate-700',
  inputClassName = 'mt-1 block w-full rounded-md border border-slate-200 px-2.5 py-1.5 text-sm shadow-sm focus:border-[color:var(--primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--primary)]/30',
}) {
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [history, setHistory] = useState([])
  const [directory, setDirectory] = useState([])
  const [directoryAvailable, setDirectoryAvailable] = useState(false)
  const debounceRef = useRef(null)
  const wrapRef = useRef(null)
  const lastFetchKeyRef = useRef('')

  const historyRows = dedupeHistory(history)
  const historyEmails = historyRows.map((r) => r.email)
  const directoryRows = directoryAvailable ? dedupeDirectory(directory, historyEmails) : []

  const fetchSuggestions = useCallback((q) => {
    const key = `${q}`
    lastFetchKeyRef.current = key
    setLoading(true)
    window.axios
      .get(route('downloads.share-email-recipient-suggestions'), {
        params: { q: q || undefined },
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
      .then((res) => {
        if (lastFetchKeyRef.current !== key) return
        setHistory(res.data?.history ?? [])
        setDirectory(res.data?.directory ?? [])
        setDirectoryAvailable(res.data?.directory_available === true)
      })
      .catch(() => {
        if (lastFetchKeyRef.current !== key) return
        setHistory([])
        setDirectory([])
        setDirectoryAvailable(false)
      })
      .finally(() => {
        if (lastFetchKeyRef.current === key) setLoading(false)
      })
  }, [])

  useEffect(() => {
    if (!open || disabled) return undefined
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = window.setTimeout(() => {
      fetchSuggestions((value || '').trim())
    }, DEBOUNCE_MS)
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
  }, [value, open, disabled, fetchSuggestions])

  useEffect(() => {
    if (!open) return undefined
    const onDoc = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [open])

  const pick = useCallback(
    (email) => {
      onChange(email)
      setOpen(false)
    },
    [onChange]
  )

  const showPanel = open && !disabled
  const hasRows = historyRows.length > 0 || directoryRows.length > 0

  return (
    <div ref={wrapRef} className="relative">
      <label htmlFor={id} className={labelClassName}>
        {label}
      </label>
      <input
        id={id}
        type="email"
        required
        autoComplete="email"
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        onFocus={() => setOpen(true)}
        onKeyDown={(e) => {
          if (e.key === 'Escape') setOpen(false)
        }}
        className={inputClassName}
        placeholder="email@example.com"
        aria-autocomplete="list"
        aria-expanded={showPanel}
        aria-controls={`${id}-suggestions`}
      />
      {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
      {showPanel && (
        <div
          id={`${id}-suggestions`}
          role="listbox"
          className="absolute z-[230] mt-1 max-h-56 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 text-left text-sm shadow-lg ring-1 ring-black/5"
        >
          {loading && !hasRows && (
            <div className="px-3 py-2 text-xs text-slate-500">Loading suggestions…</div>
          )}
          {!loading && !hasRows && (
            <div className="px-3 py-2 text-xs text-slate-500">
              {directoryAvailable ? 'Type at least 2 characters to search your team.' : 'No recent recipients yet.'}
            </div>
          )}
          {historyRows.length > 0 && (
            <div>
              <div className="bg-slate-50 px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                Recent
              </div>
              <ul>
                {historyRows.map((row) => (
                  <li key={`h-${row.email}`}>
                    <button
                      type="button"
                      role="option"
                      className="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left hover:bg-slate-50"
                      onMouseDown={(e) => e.preventDefault()}
                      onClick={() => pick(row.email)}
                    >
                      <span className="font-medium text-slate-900">{row.email}</span>
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {directoryRows.length > 0 && (
            <div>
              <div className="bg-slate-50 px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                Team
              </div>
              <ul>
                {directoryRows.map((row) => (
                  <li key={`d-${row.email}`}>
                    <button
                      type="button"
                      role="option"
                      className="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left hover:bg-slate-50"
                      onMouseDown={(e) => e.preventDefault()}
                      onClick={() => pick(row.email)}
                    >
                      <span className="font-medium text-slate-900">{row.email}</span>
                      {row.name && <span className="text-xs text-slate-500">{row.name}</span>}
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
