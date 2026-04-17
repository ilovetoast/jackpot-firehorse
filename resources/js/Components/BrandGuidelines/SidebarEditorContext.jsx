import { createContext, useContext, useState, useCallback, useMemo } from 'react'
import usePresentationOverrides from './hooks/usePresentationOverrides'

/** Visual reference URLs used by the Textured style (`texBg` on the guidelines page). */
function buildBackgroundImagePresets(visualReferences) {
    const photography = visualReferences?.photography || []
    const graphics = visualReferences?.graphics || []
    const all = [...photography, ...graphics]
    return all
        .map((ref, i) => {
            const url = ref?.url || ref?.thumbnail_url
            if (!url) return null
            return {
                url: String(url),
                label: (ref?.title && String(ref.title)) || `Reference ${i + 1}`,
            }
        })
        .filter(Boolean)
}

const SidebarEditorContext = createContext(null)

export function useSidebarEditor() {
    return useContext(SidebarEditorContext)
}

export function SidebarEditorProvider({ children, modelPayload, brand, canCustomize, visualReferences }) {
    const [isEditing, setIsEditing] = useState(false)
    const [editMode, setEditMode] = useState('layout')
    const [showDnaConfirm, setShowDnaConfirm] = useState(false)

    const overridesApi = usePresentationOverrides({ modelPayload, brand, canCustomize })
    const backgroundImagePresets = useMemo(() => buildBackgroundImagePresets(visualReferences), [visualReferences])

    const openEditor = useCallback(() => setIsEditing(true), [])
    const closeEditor = useCallback(() => {
        if (overridesApi.hasUnsavedChanges) {
            overridesApi.saveNow()
        }
        setIsEditing(false)
    }, [overridesApi])

    const requestContentMode = useCallback(() => {
        setShowDnaConfirm(true)
    }, [])

    const confirmContentMode = useCallback(() => {
        setEditMode('content')
        setShowDnaConfirm(false)
    }, [])

    const cancelContentMode = useCallback(() => {
        setShowDnaConfirm(false)
    }, [])

    const switchToLayoutMode = useCallback(() => {
        setEditMode('layout')
    }, [])

    const value = {
        isEditing,
        editMode,
        openEditor,
        closeEditor,
        requestContentMode,
        confirmContentMode,
        cancelContentMode,
        switchToLayoutMode,
        showDnaConfirm,
        canCustomize,
        backgroundImagePresets,
        brandId: brand?.id,
        brand,
        ...overridesApi,
    }

    return (
        <SidebarEditorContext.Provider value={value}>
            {children}
        </SidebarEditorContext.Provider>
    )
}
