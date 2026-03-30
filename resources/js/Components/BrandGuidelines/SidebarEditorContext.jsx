import { createContext, useContext, useState, useCallback } from 'react'
import usePresentationOverrides from './hooks/usePresentationOverrides'

const SidebarEditorContext = createContext(null)

export function useSidebarEditor() {
    return useContext(SidebarEditorContext)
}

export function SidebarEditorProvider({ children, modelPayload, brand, canCustomize }) {
    const [isEditing, setIsEditing] = useState(false)
    const [editMode, setEditMode] = useState('layout')
    const [showDnaConfirm, setShowDnaConfirm] = useState(false)

    const overridesApi = usePresentationOverrides({ modelPayload, brand, canCustomize })

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
        ...overridesApi,
    }

    return (
        <SidebarEditorContext.Provider value={value}>
            {children}
        </SidebarEditorContext.Provider>
    )
}
