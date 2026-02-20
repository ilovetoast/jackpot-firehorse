/**
 * Unified selection store for Assets, Executions, Collections, and Generative pages.
 * Phase 1: Foundational only. Does NOT replace DownloadBucket or SelectMultiple mode yet.
 * Persists selection across navigation within the SPA. In-memory only (no localStorage).
 */
import { createContext, useContext, useState, useCallback, useEffect } from 'react'

/** @typedef {'asset' | 'execution' | 'collection' | 'generative'} SelectionType */

/**
 * @typedef {Object} SelectedItem
 * @property {string} id
 * @property {SelectionType} type
 * @property {string} name
 * @property {string|null} thumbnail_url
 * @property {string|null} [category_id]
 */

const SelectionContext = createContext(null)

export function SelectionProvider({ children }) {
    const [itemsMap, setItemsMap] = useState(() => new Map())

    const selectedItems = Array.from(itemsMap.values())
    const selectedCount = itemsMap.size

    const isSelected = useCallback((id) => {
        return itemsMap.has(String(id))
    }, [itemsMap])

    const selectItem = useCallback((item) => {
        if (!item?.id || !item?.type) return
        const key = String(item.id)
        setItemsMap((prev) => {
            const next = new Map(prev)
            next.set(key, {
                id: key,
                type: item.type,
                name: item.name ?? '',
                thumbnail_url: item.thumbnail_url ?? null,
                category_id: item.category_id ?? null,
            })
            return next
        })
    }, [])

    const deselectItem = useCallback((id) => {
        const key = String(id)
        setItemsMap((prev) => {
            const next = new Map(prev)
            next.delete(key)
            return next
        })
    }, [])

    const toggleItem = useCallback((item) => {
        if (!item?.id) return
        const key = String(item.id)
        setItemsMap((prev) => {
            const next = new Map(prev)
            if (next.has(key)) {
                next.delete(key)
            } else {
                next.set(key, {
                    id: key,
                    type: item.type ?? 'asset',
                    name: item.name ?? '',
                    thumbnail_url: item.thumbnail_url ?? null,
                    category_id: item.category_id ?? null,
                })
            }
            return next
        })
    }, [])

    const clearSelection = useCallback(() => {
        setItemsMap(() => new Map())
    }, [])

    const selectMultiple = useCallback((items) => {
        if (!Array.isArray(items) || items.length === 0) return
        setItemsMap((prev) => {
            const next = new Map(prev)
            for (const item of items) {
                if (item?.id && item?.type) {
                    const key = String(item.id)
                    next.set(key, {
                        id: key,
                        type: item.type,
                        name: item.name ?? '',
                        thumbnail_url: item.thumbnail_url ?? null,
                        category_id: item.category_id ?? null,
                    })
                }
            }
            return next
        })
    }, [])

    const getSelectedByType = useCallback((type) => {
        return selectedItems.filter((item) => item.type === type)
    }, [selectedItems])

    const getSelectedIds = useCallback(() => {
        return Array.from(itemsMap.keys())
    }, [itemsMap])

    const getSelectedOnPage = useCallback((currentPageIds) => {
        if (!Array.isArray(currentPageIds)) return []
        const idSet = new Set(currentPageIds.map((id) => String(id)))
        return selectedItems.filter((item) => idSet.has(item.id))
    }, [selectedItems])

    const getSelectionTypeBreakdown = useCallback(() => {
        const breakdown = {}
        selectedItems.forEach((item) => {
            breakdown[item.type] = (breakdown[item.type] || 0) + 1
        })
        return breakdown
    }, [selectedItems])

    // Dev logging (temporary for verification)
    useEffect(() => {
        console.log('[Selection] count:', selectedCount)
    }, [selectedCount])

    const value = {
        selectedItems,
        selectedCount,
        isSelected,
        selectItem,
        deselectItem,
        toggleItem,
        clearSelection,
        selectMultiple,
        getSelectedByType,
        getSelectedIds,
        getSelectedOnPage,
        getSelectionTypeBreakdown,
    }

    return (
        <SelectionContext.Provider value={value}>
            {children}
        </SelectionContext.Provider>
    )
}

export function useSelection() {
    const ctx = useContext(SelectionContext)
    if (!ctx) {
        throw new Error('useSelection must be used within SelectionProvider')
    }
    return ctx
}

export function useSelectionOptional() {
    return useContext(SelectionContext)
}
