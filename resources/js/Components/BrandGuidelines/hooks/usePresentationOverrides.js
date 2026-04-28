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

function deepMerge(a, b) {
    if (b == null) return a
    if (a == null || typeof a !== 'object' || Array.isArray(a)) return b
    if (typeof b !== 'object' || Array.isArray(b)) return b
    const out = { ...a }
    for (const k of Object.keys(b)) {
        const bv = b[k]
        const av = a[k]
        if (bv != null && typeof bv === 'object' && !Array.isArray(bv) && av != null && typeof av === 'object' && !Array.isArray(av)) {
            out[k] = deepMerge(av, bv)
        } else {
            out[k] = bv
        }
    }
    return out
}

function clonePayload(o) {
    if (o == null) return o
    try {
        return JSON.parse(JSON.stringify(o))
    } catch {
        return o
    }
}

/**
 * Explicit save / discard: local draft updates the preview immediately; the server is only
 * updated when the user clicks Save. Discard restores the last “clean” snapshot
 * (session start, or last successful save while the panel was open).
 */
export default function usePresentationOverrides({ modelPayload, brand, canCustomize }) {
    const initialO = modelPayload?.presentation_overrides ?? { global: {}, sections: {} }
    const initialC = modelPayload?.presentation_content ?? {}
    const initialP = modelPayload?.presentation ?? { style: 'clean' }

    const [draftOverrides, setDraftOverrides] = useState(() => clonePayload(initialO))
    const [draftContent, setDraftContent] = useState(() => clonePayload(initialC))
    const [draftPresentation, setDraftPresentation] = useState(() => ({ ...initialP }))
    const [saving, setSaving] = useState(false)
    const [lastSaved, setLastSaved] = useState(null)
    const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false)
    const [saveError, setSaveError] = useState(null)

    const draftOverridesRef = useRef(draftOverrides)
    const draftContentRef = useRef(draftContent)
    const draftPresRef = useRef(draftPresentation)
    draftOverridesRef.current = draftOverrides
    draftContentRef.current = draftContent
    draftPresRef.current = draftPresentation

    /** Baseline for Discard: set when customize opens, and after each successful Save. */
    const sessionBaselineRef = useRef({
        overrides: clonePayload(initialO),
        content: clonePayload(initialC),
        presentation: { ...initialP },
    })

    const markDirty = useCallback(() => {
        setHasUnsavedChanges(true)
    }, [])

    const persistToServer = useCallback(
        (overrides, content, presentation) => {
            if (!canCustomize) return

            const snapshotBundle = {
                overrides: clonePayload(overrides),
                content: clonePayload(content),
                presentation: { ...presentation },
            }

            setSaving(true)
            setSaveError(null)

            const url = typeof window.route === 'function'
                ? window.route('brands.guidelines.customize', { brand: brand.id })
                : `/app/brands/${brand.id}/guidelines/customize`

            axios
                .post(url, {
                    payload: {
                        presentation_overrides: overrides,
                        presentation_content: content,
                        presentation,
                    },
                })
                .then(() => {
                    setLastSaved(new Date())
                    setHasUnsavedChanges(false)
                    sessionBaselineRef.current = {
                        overrides: clonePayload(snapshotBundle.overrides),
                        content: clonePayload(snapshotBundle.content),
                        presentation: { ...snapshotBundle.presentation },
                    }
                })
                .catch((err) => {
                    setSaveError(err?.response?.data?.error || 'Save failed')
                })
                .finally(() => {
                    setSaving(false)
                })
        },
        [canCustomize, brand?.id],
    )

    /** Call when the user opens the customizer; snapshots current draft as the discard target. */
    const beginEditSession = useCallback(() => {
        sessionBaselineRef.current = {
            overrides: clonePayload(draftOverridesRef.current),
            content: clonePayload(draftContentRef.current),
            presentation: { ...draftPresRef.current },
        }
        setHasUnsavedChanges(false)
        setSaveError(null)
    }, [])

    useEffect(() => {
        if (!canCustomize) return
        const o = clonePayload(modelPayload?.presentation_overrides ?? { global: {}, sections: {} })
        const c = clonePayload(modelPayload?.presentation_content ?? {})
        const p = { ...modelPayload?.presentation ?? { style: 'clean' } }
        setDraftOverrides(o)
        setDraftContent(c)
        setDraftPresentation(p)
        sessionBaselineRef.current = { overrides: clonePayload(o), content: clonePayload(c), presentation: { ...p } }
        setHasUnsavedChanges(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [brand?.id, canCustomize])

    const discardChanges = useCallback(() => {
        const s = sessionBaselineRef.current
        setDraftOverrides(clonePayload(s.overrides))
        setDraftContent(clonePayload(s.content))
        setDraftPresentation({ ...s.presentation })
        setHasUnsavedChanges(false)
        setSaveError(null)
    }, [])

    const updateOverride = useCallback(
        (sectionId, path, value) => {
            setDraftOverrides((prev) => {
                const next = sectionId === 'global' ? deepSet(prev, `global.${path}`, value) : deepSet(prev, `sections.${sectionId}.${path}`, value)
                return next
            })
            markDirty()
        },
        [markDirty],
    )

    const updateContent = useCallback(
        (sectionId, field, html) => {
            setDraftContent((prev) => {
                const section = { ...(prev[sectionId] || {}) }
                section[field] = html
                return { ...prev, [sectionId]: section }
            })
            markDirty()
        },
        [markDirty],
    )

    const updatePresentationStyle = useCallback(
        (style) => {
            setDraftPresentation((prev) => ({ ...prev, style }))
            markDirty()
        },
        [markDirty],
    )

    const resetSection = useCallback(
        (sectionId) => {
            setDraftOverrides((prev) => {
                const sections = { ...prev.sections }
                delete sections[sectionId]
                return { ...prev, sections }
            })
            setDraftContent((prev) => {
                const nextC = { ...prev }
                delete nextC[sectionId]
                return nextC
            })
            markDirty()
        },
        [markDirty],
    )

    const resetAll = useCallback(() => {
        setDraftOverrides({ global: {}, sections: {} })
        setDraftContent({})
        markDirty()
    }, [markDirty])

    const mergeLogoBlock = useCallback(
        (sectionId, blockId, patch) => {
            setDraftOverrides((prev) => {
                const section = prev.sections[sectionId] || {}
                const content = { ...(section.content || {}) }
                const blocks = { ...(content.logo_blocks || {}) }
                const cur = blocks[blockId] || {}
                blocks[blockId] = deepMerge(cur, patch)
                const nextContent = { ...content, logo_blocks: blocks }
                return deepSet(prev, `sections.${sectionId}.content`, nextContent)
            })
            markDirty()
        },
        [markDirty],
    )

    const resetLogoBlock = useCallback(
        (sectionId, blockId) => {
            setDraftOverrides((prev) => {
                const section = prev.sections[sectionId] || {}
                const content = { ...(section.content || {}) }
                const blocks = { ...(content.logo_blocks || {}) }
                delete blocks[blockId]
                if (Object.keys(blocks).length === 0) {
                    const { logo_blocks, ...rest } = content
                    return deepSet(prev, `sections.${sectionId}.content`, rest)
                }
                const nextContent = { ...content, logo_blocks: blocks }
                return deepSet(prev, `sections.${sectionId}.content`, nextContent)
            })
            markDirty()
        },
        [markDirty],
    )

    const updatePageTheme = useCallback(
        (path, value) => {
            setDraftOverrides((prev) => deepSet(prev, `global.page_theme.${path}`, value))
            markDirty()
        },
        [markDirty],
    )

    const clearPageTheme = useCallback(() => {
        setDraftOverrides((prev) => {
            const global = { ...prev.global }
            delete global.page_theme
            return { ...prev, global }
        })
        markDirty()
    }, [markDirty])

    const saveNow = useCallback(() => {
        persistToServer(
            draftOverridesRef.current,
            draftContentRef.current,
            { ...draftPresRef.current },
        )
    }, [persistToServer])

    const saveDnaPatch = useCallback(
        (dnaPatches) => {
            if (!canCustomize) return Promise.reject('Not authorized')

            const url = typeof window.route === 'function'
                ? window.route('brands.guidelines.customize', { brand: brand.id })
                : `/app/brands/${brand.id}/guidelines/customize`

            return axios.post(url, {
                payload: {
                    presentation_overrides: draftOverridesRef.current,
                    presentation_content: draftContentRef.current,
                    presentation: draftPresRef.current,
                },
                dna_patches: dnaPatches,
            })
        },
        [canCustomize, brand?.id],
    )

    return {
        modelPayload,
        draftOverrides,
        draftContent,
        draftPresentation,
        updateOverride,
        updateContent,
        updatePresentationStyle,
        updatePageTheme,
        clearPageTheme,
        mergeLogoBlock,
        resetLogoBlock,
        resetSection,
        resetAll,
        saveNow,
        saveDnaPatch,
        saving,
        lastSaved,
        hasUnsavedChanges,
        saveError,
        discardChanges,
        beginEditSession,
    }
}
