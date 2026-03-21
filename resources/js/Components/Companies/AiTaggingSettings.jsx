/**
 * AiTaggingSettings — Tags (keywords) vs Asset Fields (structured registry ideas).
 * API keys unchanged (disable_ai_tagging, ai_insights_enabled, etc.).
 */

import { useState, useEffect, useCallback, useRef } from 'react'
import { ExclamationTriangleIcon, CheckCircleIcon, InformationCircleIcon } from '@heroicons/react/24/outline'
import { debounce } from 'lodash-es'

/** @param {string | null | undefined} iso */
function formatRelativeTime(iso) {
    if (!iso) return null
    const then = new Date(iso).getTime()
    if (Number.isNaN(then)) return null
    const diffSec = Math.round((then - Date.now()) / 1000)
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
    const abs = Math.abs(diffSec)
    if (abs < 60) return rtf.format(diffSec, 'second')
    const diffMin = Math.round(diffSec / 60)
    if (Math.abs(diffMin) < 60) return rtf.format(diffMin, 'minute')
    const diffHr = Math.round(diffSec / 3600)
    if (Math.abs(diffHr) < 24) return rtf.format(diffHr, 'hour')
    const diffDay = Math.round(diffSec / 86400)
    if (Math.abs(diffDay) < 7) return rtf.format(diffDay, 'day')
    const diffWk = Math.round(diffSec / 604800)
    if (Math.abs(diffWk) < 4) return rtf.format(diffWk, 'week')
    const diffMo = Math.round(diffSec / 2629800)
    if (Math.abs(diffMo) < 12) return rtf.format(diffMo, 'month')
    return rtf.format(Math.round(diffSec / 31557600), 'year')
}

// Custom Toggle Switch component (replaces Headless UI Switch)
function Toggle({ checked, onChange, disabled = false, className = "" }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => !disabled && onChange(!checked)}
            disabled={disabled}
            className={`${
                checked ? 'bg-indigo-600' : 'bg-gray-200'
            } ${
                disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
            } relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${className}`}
        >
            <span
                aria-hidden="true"
                className={`${
                    checked ? 'translate-x-5' : 'translate-x-0'
                } pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transform ring-0 transition duration-200 ease-in-out`}
            />
        </button>
    )
}

export default function AiTaggingSettings({ 
    canEdit = false, 
    currentPlan = 'free',
    className = "" 
}) {
    const [settings, setSettings] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [saving, setSaving] = useState(false)
    const [lastSaved, setLastSaved] = useState(null)
    const [runInsightsLoading, setRunInsightsLoading] = useState(false)
    
    // Get plan tag limits
    const planLimits = {
        free: { max: 1, name: 'Free' },
        starter: { max: 5, name: 'Starter' },
        pro: { max: 10, name: 'Pro' },
        premium: { max: 15, name: 'Premium' },
        enterprise: { max: 50, name: 'Enterprise' }
    }
    const planLimit = planLimits[currentPlan] || planLimits.free
    const maxTagsPerAsset = planLimit.max

    // Local state for tag limit input (for immediate UI feedback)
    const [localTagLimit, setLocalTagLimit] = useState('')
    const tagLimitUpdateRef = useRef(null)

    // Load initial settings
    useEffect(() => {
        loadSettings()
    }, [])
    
    // Sync local tag limit with server settings
    useEffect(() => {
        if (settings) {
            // Default to plan max or server setting, whichever is lower
            const serverLimit = settings.ai_best_practices_limit || Math.min(5, maxTagsPerAsset)
            const effectiveLimit = Math.min(serverLimit, maxTagsPerAsset)
            setLocalTagLimit(String(effectiveLimit))
        }
    }, [settings, maxTagsPerAsset])

    const loadSettings = async () => {
        try {
            setLoading(true)
            setError(null)

            const response = await window.axios.get('/app/api/companies/ai-settings')
            
            if (response.data && response.data.settings) {
                setSettings(response.data.settings)
            } else {
                throw new Error('Invalid response format')
            }
        } catch (err) {
            console.error('[AiTaggingSettings] Failed to load settings:', err)
            setError(err.response?.data?.error || err.message || 'Failed to load AI settings')
        } finally {
            setLoading(false)
        }
    }

    // Debounced settings update
    const debouncedUpdateSettings = useCallback(
        debounce(async (newSettings) => {
            try {
                setSaving(true)

                const response = await window.axios.patch('/app/api/companies/ai-settings', newSettings)

                if (response.data && response.data.settings) {
                    // Update settings with server response (authoritative)
                    setSettings(response.data.settings)
                    setLastSaved(new Date())
                    setError(null)
                } else {
                    throw new Error('Invalid response format')
                }
            } catch (err) {
                console.error('[AiTaggingSettings] Failed to update settings:', err)
                
                // Revert optimistic update
                await loadSettings()
                
                setError(err.response?.data?.error || err.message || 'Failed to update AI settings')
            } finally {
                setSaving(false)
            }
        }, 500),
        []
    )

    // Update a setting with optimistic UI
    const updateSetting = (key, value) => {
        if (!canEdit || !settings) return

        // Optimistic update
        const newSettings = { ...settings, [key]: value }
        setSettings(newSettings)

        // Send to server (debounced)
        debouncedUpdateSettings(newSettings)
    }
    
    // Debounced update for tag limit specifically
    const debouncedUpdateTagLimit = useCallback(
        debounce((value) => {
            const numValue = parseInt(value)
            // Respect plan limit: 1 to maxTagsPerAsset
            if (!isNaN(numValue) && numValue >= 1 && numValue <= maxTagsPerAsset) {
                // Update both the limit value and ensure mode is set to best_practices
                const newSettings = { 
                    ...settings, 
                    ai_best_practices_limit: numValue,
                    ai_auto_tag_limit_mode: 'best_practices'
                }
                setSettings(newSettings)
                debouncedUpdateSettings(newSettings)
            }
        }, 800), // Slightly longer debounce for number input
        [settings, debouncedUpdateSettings, maxTagsPerAsset]
    )

    const runInsightsNow = async () => {
        if (!canEdit || !settings?.ai_insights_enabled) return
        try {
            setRunInsightsLoading(true)
            setError(null)
            const response = await window.axios.post('/app/api/companies/ai-settings/run-insights')
            if (response.data?.settings) {
                setSettings(response.data.settings)
            }
        } catch (err) {
            console.error('[AiTaggingSettings] Run insights failed:', err)
            setError(err.response?.data?.error || err.message || 'Could not queue insights sync')
        } finally {
            setRunInsightsLoading(false)
        }
    }
    
    // Handle tag limit input changes
    const handleTagLimitChange = (e) => {
        const value = e.target.value
        setLocalTagLimit(value) // Immediate UI update
        
        // Clear previous timer
        if (tagLimitUpdateRef.current) {
            clearTimeout(tagLimitUpdateRef.current)
        }
        
        // Debounce the actual update
        debouncedUpdateTagLimit(value)
    }
    
    // Cleanup on unmount
    useEffect(() => {
        return () => {
            if (tagLimitUpdateRef.current) {
                clearTimeout(tagLimitUpdateRef.current)
            }
        }
    }, [])

    // Render loading state
    if (loading) {
        return (
            <div className={`${className}`}>
                <div className="flex items-center justify-center py-8">
                    <div className="text-sm text-gray-500">Loading AI settings...</div>
                </div>
            </div>
        )
    }

    // Render error state
    if (error) {
        return (
            <div className={`${className}`}>
                <div className="rounded-md bg-red-50 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <ExclamationTriangleIcon className="h-5 w-5 text-red-400" />
                        </div>
                        <div className="ml-3">
                            <h3 className="text-sm font-medium text-red-800">
                                Error Loading AI Settings
                            </h3>
                            <div className="mt-2 text-sm text-red-700">
                                <p>{error}</p>
                            </div>
                            <div className="mt-4">
                                <button
                                    type="button"
                                    onClick={loadSettings}
                                    className="rounded-md bg-red-50 px-2 py-1.5 text-sm font-medium text-red-800 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 focus:ring-offset-red-50"
                                >
                                    Retry
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    if (!settings) {
        return null
    }

    const isAiDisabled = settings.disable_ai_tagging
    const isAutoApplyEnabled = settings.enable_ai_tag_auto_apply

    return (
        <div className={`space-y-6 ${className}`}>
            {/* Status Indicator */}
            {lastSaved && (
                <div className="rounded-md bg-green-50 p-3">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <CheckCircleIcon className="h-5 w-5 text-green-400" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-green-700">
                                Settings saved at {lastSaved.toLocaleTimeString()}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Saving Indicator */}
            {saving && (
                <div className="rounded-md bg-blue-50 p-3">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <div className="animate-spin rounded-full h-5 w-5 border-2 border-blue-300 border-t-blue-600" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-blue-700">Saving changes...</p>
                        </div>
                    </div>
                </div>
            )}

            <div className="rounded-xl border border-gray-200 bg-gray-50/60 p-5 shadow-sm">
                <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Tags</h3>
                <p className="mt-1 text-xs text-gray-500">Quick keywords — separate from asset fields below.</p>

                <div className="mt-5 space-y-6">
                    <div className="flex items-center justify-between">
                        <div className="flex-1 pr-4">
                            <label className="text-base font-medium text-gray-900">
                                AI tag suggestions
                            </label>
                            <p className="text-sm text-gray-500">
                                Let AI suggest tags for assets.
                            </p>
                        </div>
                        <Toggle
                            checked={!isAiDisabled}
                            onChange={(enabled) => updateSetting('disable_ai_tagging', !enabled)}
                            disabled={!canEdit}
                        />
                    </div>

                    {isAiDisabled && (
                        <div className="rounded-md bg-white/80 p-4 ring-1 ring-gray-100">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                                </div>
                                <div className="ml-3">
                                    <p className="text-sm text-gray-600">
                                        Turn on <span className="font-medium">AI tag suggestions</span> to use the options below.
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className={`space-y-6 ${isAiDisabled ? 'opacity-50 pointer-events-none' : ''}`}>
                        <div className="flex items-center justify-between">
                            <div className="flex-1 pr-4">
                                <label className="text-base font-medium text-gray-900">
                                    Show tag suggestions on assets
                                </label>
                                <p className="text-sm text-gray-500">
                                    Display suggestions when viewing an asset.
                                </p>
                            </div>
                            <Toggle
                                checked={settings.enable_ai_tag_suggestions}
                                onChange={(enabled) => updateSetting('enable_ai_tag_suggestions', enabled)}
                                disabled={!canEdit || isAiDisabled}
                            />
                        </div>

                        <div className="flex items-center justify-between">
                            <div className="flex-1 pr-4">
                                <label className="text-base font-medium text-gray-900">
                                    Auto-apply tags
                                </label>
                                <p className="text-sm text-gray-500">
                                    Automatically add tags when confidence is high.
                                </p>
                            </div>
                            <Toggle
                                checked={isAutoApplyEnabled}
                                onChange={(enabled) => updateSetting('enable_ai_tag_auto_apply', enabled)}
                                disabled={!canEdit || isAiDisabled}
                            />
                        </div>

                        {!isAiDisabled && (
                            <div className="rounded-lg bg-white p-4 ring-1 ring-gray-100">
                                <label className="text-base font-medium text-gray-900">
                                    Max tags per asset
                                </label>
                                <p className="text-sm text-gray-500 mb-4">
                                    Limit how many tags AI suggests or applies.
                                </p>

                                <div className="flex items-center space-x-3">
                                    <label className="text-sm font-medium text-gray-700">Limit:</label>
                                    <input
                                        type="number"
                                        min="1"
                                        max={maxTagsPerAsset}
                                        value={localTagLimit}
                                        onChange={handleTagLimitChange}
                                        disabled={!canEdit || isAiDisabled}
                                        className="block w-20 rounded-md border-gray-300 shadow-sm focus:border-indigo-600 focus:ring-indigo-600 text-sm disabled:bg-gray-50 disabled:text-gray-500"
                                        placeholder={String(Math.min(5, maxTagsPerAsset))}
                                    />
                                    <span className="text-sm text-gray-700">per asset</span>
                                </div>
                                <p className="mt-2 text-sm text-gray-500">
                                    1–{maxTagsPerAsset} ({planLimit.name}). <strong>{Math.min(5, maxTagsPerAsset)} recommended.</strong>
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="my-6 flex items-center gap-3" aria-hidden="true">
                <div className="h-px flex-1 bg-gradient-to-r from-transparent via-gray-300 to-transparent" />
            </div>

            <div className="rounded-xl border border-indigo-100 bg-indigo-50/30 p-5 shadow-sm">
                <div>
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-indigo-900/70">
                        Asset fields
                    </h3>
                    <p className="mt-1 text-xs text-gray-600">Structured data — not the Tags section above.</p>
                    <p className="mt-3 text-base font-medium text-gray-900">Suggest structured fields</p>
                    <p className="mt-1 text-sm text-gray-600">
                        AI analyzes your assets to suggest new fields and field values.
                    </p>
                </div>
                <div className="mt-5 flex items-center justify-between">
                    <div className="flex-1 pr-4">
                        <label className="text-base font-medium text-gray-900">Enable Asset Field Intelligence</label>
                    </div>
                    <Toggle
                        checked={!!settings.ai_insights_enabled}
                        onChange={(enabled) => updateSetting('ai_insights_enabled', enabled)}
                        disabled={!canEdit}
                    />
                </div>
                <div className="mt-4 rounded-lg border border-indigo-100/80 bg-white/80 px-4 py-3 shadow-sm">
                    <div className="flex flex-col gap-2 text-sm text-gray-700 sm:flex-row sm:items-center sm:justify-between">
                        <div className="space-y-1">
                            <p>
                                <span className="font-medium text-gray-900">Last run:</span>{' '}
                                {settings.last_insights_run_at
                                    ? formatRelativeTime(settings.last_insights_run_at) ?? '—'
                                    : 'Never'}
                            </p>
                            <p>
                                <span className="font-medium text-gray-900">New suggestions:</span>{' '}
                                {typeof settings.insights_pending_suggestions_count === 'number'
                                    ? settings.insights_pending_suggestions_count
                                    : '—'}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={runInsightsNow}
                            disabled={!canEdit || !settings.ai_insights_enabled || runInsightsLoading}
                            className="inline-flex shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {runInsightsLoading ? 'Queueing…' : 'Run now'}
                        </button>
                    </div>
                </div>
            </div>

            {/* Permission Notice */}
            {!canEdit && (
                <div className="rounded-md bg-gray-50 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-gray-600">
                                You don't have permission to edit AI settings. Contact a company admin to make changes.
                            </p>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}