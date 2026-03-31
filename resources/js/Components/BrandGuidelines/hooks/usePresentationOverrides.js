import { useState, useCallback, useRef, useEffect } from 'react'
import axios from 'axios'

function deepSet(obj, path, value) {
    const keys = path.split('.')
    const result = { ...obj }
    let current = result
    for (let i = 0; i < keys.length - 1; i++) {
        const key = keys[i]
        current[key] = current[key] != null && typeof current[key] === 'object' ? { ...current[key] } : {}
        current = current[key]
    }
    current[keys[keys.length - 1]] = value
    return result
}

export default function usePresentationOverrides({ modelPayload, brand, canCustomize }) {
    const [draftOverrides, setDraftOverrides] = useState(() => modelPayload?.presentation_overrides ?? { global: {}, sections: {} })
    const [draftContent, setDraftContent] = useState(() => modelPayload?.presentation_content ?? {})
    const [draftPresentation, setDraftPresentation] = useState(() => modelPayload?.presentation ?? { style: 'clean' })
    const [saving, setSaving] = useState(false)
    const [lastSaved, setLastSaved] = useState(null)
    const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false)
    const [saveError, setSaveError] = useState(null)

    const saveTimerRef = useRef(null)
    const pendingRef = useRef(null)

    const flush = useCallback(() => {
        if (!canCustomize || !pendingRef.current) return
        const { overrides, content, presentation } = pendingRef.current
        pendingRef.current = null

        setSaving(true)
        setSaveError(null)

        const url = typeof window.route === 'function'
            ? window.route('brands.guidelines.customize', { brand: brand.id })
            : `/app/brands/${brand.id}/guidelines/customize`

        axios.post(url, {
            payload: {
                presentation_overrides: overrides,
                presentation_content: content,
                presentation,
            },
        }).then(() => {
            setLastSaved(new Date())
            setHasUnsavedChanges(false)
        }).catch((err) => {
            setSaveError(err?.response?.data?.error || 'Save failed')
        }).finally(() => {
            setSaving(false)
        })
    }, [canCustomize, brand?.id])

    const scheduleSave = useCallback((overrides, content, presentation) => {
        pendingRef.current = { overrides, content, presentation }
        setHasUnsavedChanges(true)
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
        saveTimerRef.current = setTimeout(flush, 700)
    }, [flush])

    useEffect(() => {
        return () => {
            if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
        }
    }, [])

    const updateOverride = useCallback((sectionId, path, value) => {
        setDraftOverrides((prev) => {
            const next = sectionId === 'global'
                ? deepSet(prev, `global.${path}`, value)
                : deepSet(prev, `sections.${sectionId}.${path}`, value)
            scheduleSave(next, draftContent, draftPresentation)
            return next
        })
    }, [scheduleSave, draftContent, draftPresentation])

    const updateContent = useCallback((sectionId, field, html) => {
        setDraftContent((prev) => {
            const section = { ...(prev[sectionId] || {}) }
            section[field] = html
            const next = { ...prev, [sectionId]: section }
            scheduleSave(draftOverrides, next, draftPresentation)
            return next
        })
    }, [scheduleSave, draftOverrides, draftPresentation])

    const updatePresentationStyle = useCallback((style) => {
        setDraftPresentation((prev) => {
            const next = { ...prev, style }
            scheduleSave(draftOverrides, draftContent, next)
            return next
        })
    }, [scheduleSave, draftOverrides, draftContent])

    const resetSection = useCallback((sectionId) => {
        setDraftOverrides((prev) => {
            const sections = { ...prev.sections }
            delete sections[sectionId]
            const next = { ...prev, sections }
            scheduleSave(next, draftContent, draftPresentation)
            return next
        })
        setDraftContent((prev) => {
            const next = { ...prev }
            delete next[sectionId]
            scheduleSave(draftOverrides, next, draftPresentation)
            return next
        })
    }, [scheduleSave, draftOverrides, draftContent, draftPresentation])

    const resetAll = useCallback(() => {
        const emptyOverrides = { global: {}, sections: {} }
        const emptyContent = {}
        setDraftOverrides(emptyOverrides)
        setDraftContent(emptyContent)
        scheduleSave(emptyOverrides, emptyContent, draftPresentation)
    }, [scheduleSave, draftPresentation])

    const saveNow = useCallback(() => {
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
        pendingRef.current = { overrides: draftOverrides, content: draftContent, presentation: draftPresentation }
        flush()
    }, [flush, draftOverrides, draftContent, draftPresentation])

    const saveDnaPatch = useCallback((dnaPatches) => {
        if (!canCustomize) return Promise.reject('Not authorized')

        const url = typeof window.route === 'function'
            ? window.route('brands.guidelines.customize', { brand: brand.id })
            : `/app/brands/${brand.id}/guidelines/customize`

        return axios.post(url, {
            payload: {
                presentation_overrides: draftOverrides,
                presentation_content: draftContent,
                presentation: draftPresentation,
            },
            dna_patches: dnaPatches,
        })
    }, [canCustomize, brand?.id, draftOverrides, draftContent, draftPresentation])

    return {
        modelPayload,
        draftOverrides,
        draftContent,
        draftPresentation,
        updateOverride,
        updateContent,
        updatePresentationStyle,
        resetSection,
        resetAll,
        saveNow,
        saveDnaPatch,
        saving,
        lastSaved,
        hasUnsavedChanges,
        saveError,
    }
}
