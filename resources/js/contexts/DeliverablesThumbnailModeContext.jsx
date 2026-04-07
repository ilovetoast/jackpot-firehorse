import { createContext, useCallback, useContext, useMemo, useState } from 'react'

const DeliverablesThumbnailModeContext = createContext(null)

const STORAGE_KEY = 'jackpot_executions_grid_thumbnail_mode'

/**
 * @typedef {'standard' | 'enhanced' | 'presentation'} ExecutionThumbnailViewMode
 */

function readStoredMode() {
    if (typeof window === 'undefined') {
        return 'standard'
    }
    try {
        const raw = localStorage.getItem(STORAGE_KEY)
        if (raw === 'original') {
            localStorage.setItem(STORAGE_KEY, 'standard')
            return 'standard'
        }
        if (raw === 'standard' || raw === 'enhanced' || raw === 'presentation') {
            return raw
        }
    } catch {
        /* ignore */
    }
    return 'standard'
}

/**
 * @returns {{ thumbnailViewMode: ExecutionThumbnailViewMode, setThumbnailViewMode: (m: ExecutionThumbnailViewMode) => void } | null}
 */
export function useDeliverablesThumbnailMode() {
    return useContext(DeliverablesThumbnailModeContext)
}

export function DeliverablesThumbnailModeProvider({ children }) {
    const [thumbnailViewMode, setThumbnailViewModeState] = useState(
        /** @type {ExecutionThumbnailViewMode} */ () => readStoredMode(),
    )

    const setThumbnailViewMode = useCallback((mode) => {
        setThumbnailViewModeState(mode)
        if (typeof window !== 'undefined') {
            try {
                localStorage.setItem(STORAGE_KEY, mode)
            } catch {
                /* ignore */
            }
        }
    }, [])

    const value = useMemo(
        () => ({
            thumbnailViewMode,
            setThumbnailViewMode,
        }),
        [thumbnailViewMode, setThumbnailViewMode],
    )

    return (
        <DeliverablesThumbnailModeContext.Provider value={value}>
            {children}
        </DeliverablesThumbnailModeContext.Provider>
    )
}
