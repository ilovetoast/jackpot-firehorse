import { createContext, useCallback, useContext, useMemo, useState } from 'react'

const DownloadsPanelContext = createContext(undefined)

/**
 * Optional: AppNav and SelectionActionBar work when provider is absent (logged-out shell).
 */
export function useDownloadsPanelOptional() {
  return useContext(DownloadsPanelContext)
}

export function DownloadsPanelProvider({ children }) {
  const [open, setOpen] = useState(false)
  const [highlightDownloadId, setHighlightDownloadId] = useState(null)

  const openPanel = useCallback((opts = {}) => {
    const id = opts.openDownloadId ?? opts.focusDownloadId ?? null
    setHighlightDownloadId(id || null)
    setOpen(true)
  }, [])

  const closePanel = useCallback(() => {
    setOpen(false)
    setHighlightDownloadId(null)
  }, [])

  const consumeHighlight = useCallback(() => {
    setHighlightDownloadId(null)
  }, [])

  const value = useMemo(
    () => ({
      open,
      openPanel,
      closePanel,
      highlightDownloadId,
      consumeHighlight,
    }),
    [open, highlightDownloadId, openPanel, closePanel, consumeHighlight]
  )

  return (
    <DownloadsPanelContext.Provider value={value}>
      {children}
    </DownloadsPanelContext.Provider>
  )
}
