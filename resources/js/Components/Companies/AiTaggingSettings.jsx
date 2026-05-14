/**
 * AiTaggingSettings — Tags (keywords) vs Asset Fields (structured registry ideas).
 * API keys unchanged (disable_ai_tagging, ai_insights_enabled, etc.).
 */

import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { ExclamationTriangleIcon, CheckCircleIcon, InformationCircleIcon, SparklesIcon } from '@heroicons/react/24/outline'
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
                checked ? 'bg-violet-600' : 'bg-gray-200'
            } ${
                disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
            } relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-violet-600 focus:ring-offset-2 ${className}`}
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
    className = ""
}) {
    const [settings, setSettings] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [saving, setSaving] = useState(false)
    const [lastSaved, setLastSaved] = useState(null)
    const [runInsightsLoading, setRunInsightsLoading] = useState(false)
    const [insightsClock, setInsightsClock] = useState(() => Date.now())

    useEffect(() => {
        const id = window.setInterval(() => setInsightsClock(Date.now()), 15_000)
        return () => window.clearInterval(id)
    }, [])

    // Plan max total tags per asset (from config/plans.php via API); AI auto-apply UI is also capped at 10 server-side.
    const maxTagsPerAsset = useMemo(() => {
        const n = Number(settings?.max_tags_per_asset)
        return Number.isFinite(n) && n > 0 ? n : 4
    }, [settings?.max_tags_per_asset])

    const tagPolicyInputMax = useMemo(() => Math.min(maxTagsPerAsset, 10), [maxTagsPerAsset])

    const planDisplayName = settings?.plan_limits_display_name || 'Your plan'

    const recommendedAutoApply = useMemo(() => {
        const n = Number(settings?.recommended_ai_tag_auto_apply_limit)
        if (Number.isFinite(n) && n > 0) {
            return Math.min(n, tagPolicyInputMax)
        }
        return Math.min(5, tagPolicyInputMax)
    }, [settings?.recommended_ai_tag_auto_apply_limit, tagPolicyInputMax])

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
            const raw = settings.ai_best_practices_limit
            const parsed = typeof raw === 'number' ? raw : parseInt(String(raw), 10)
            const serverLimit = Number.isFinite(parsed)
                ? parsed
                : Math.min(recommendedAutoApply, tagPolicyInputMax)
            const effectiveLimit = Math.min(Math.max(1, serverLimit), tagPolicyInputMax)
            setLocalTagLimit(String(effectiveLimit))
        }
    }, [settings, tagPolicyInputMax, recommendedAutoApply, settings?.ai_best_practices_limit])

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
            if (!isNaN(numValue) && numValue >= 1 && numValue <= tagPolicyInputMax) {
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
        [settings, debouncedUpdateSettings, tagPolicyInputMax]
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
            const data = err.response?.data
            if (data?.settings) {
                setSettings(data.settings)
            }
            setError(data?.error || err.message || 'Could not queue insights sync')
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

    const manualInsightsInCooldown =
        Boolean(settings.insights_manual_run_available_at) &&
        new Date(settings.insights_manual_run_available_at).getTime() > insightsClock

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
                <div className="rounded-md bg-violet-50 p-3">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <div className="animate-spin rounded-full h-5 w-5 border-2 border-violet-300 border-t-violet-600" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-violet-800">Saving changes...</p>
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
                                Let AI suggest tags for assets. Auto-apply and per-asset limit below
                                are configured underneath this master.
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

                    {/*
                     * Nested sub-items of "AI tag suggestions" (visually indented under the parent
                     * master so the hierarchy is obvious). The `border-l` and `ml-3 pl-4` draw a
                     * parent-child tree feel; `opacity-50 pointer-events-none` cascades the
                     * disabled state from the parent toggle (and, transitively, the tenant-wide
                     * `ai_enabled` master via the outer wrapper in Settings.jsx).
                     *
                     * NOTE: the "Show tag suggestions on assets" toggle (previously the first sub-
                     * item) has been removed from the UI per product direction. The underlying
                     * setting is force-treated as always-on in AiTagPolicyService::getTenantSettings
                     * so existing tenants that stored `false` still see suggestions — no migration.
                     */}
                    <div
                        className={`ml-3 space-y-6 border-l-2 border-gray-200 pl-4 ${
                            isAiDisabled ? 'opacity-50 pointer-events-none' : ''
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <div className="flex-1 pr-4">
                                <label className="text-sm font-medium text-gray-900">
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
                                <label className="text-sm font-medium text-gray-900">
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
                                        max={tagPolicyInputMax}
                                        value={localTagLimit}
                                        onChange={handleTagLimitChange}
                                        disabled={!canEdit || isAiDisabled}
                                        className="block w-20 rounded-md border-gray-300 shadow-sm focus:border-violet-600 focus:ring-violet-600 text-sm disabled:bg-gray-50 disabled:text-gray-500"
                                        placeholder={String(recommendedAutoApply)}
                                    />
                                    <span className="text-sm text-gray-700">per asset</span>
                                </div>
                                <p className="mt-2 text-sm text-gray-500">
                                    1–{tagPolicyInputMax} ({planDisplayName}). <strong>{recommendedAutoApply} recommended.</strong>
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="my-6 flex items-center gap-3" aria-hidden="true">
                <div className="h-px flex-1 bg-gradient-to-r from-transparent via-gray-300 to-transparent" />
            </div>

            {/*
             * Asset Field Intelligence — branded violet treatment (parity with Studio's violet
             * BrandedAiCard and Brand Alignment's primary-color card over in Settings.jsx).
             * Pill badge + icon tile match the BulkActionsModal "Video AI" pattern so all AI
             * sub-features share one visual language.
             */}
            <div className="rounded-xl border border-violet-100 bg-violet-50/30 p-5 shadow-sm">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-violet-100/80 pb-2">
                    <span className="inline-flex items-center rounded bg-violet-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                        Asset fields
                    </span>
                    <h3 className="text-sm font-semibold text-gray-800">
                        Structured field intelligence
                    </h3>
                    <span className="ml-auto flex h-8 w-8 items-center justify-center rounded-md bg-violet-100">
                        <SparklesIcon className="h-4 w-4 text-violet-700" />
                    </span>
                </div>
                <p className="mt-3 text-sm leading-snug text-gray-600">
                    AI analyzes your assets to suggest new fields and field values. Structured data
                    — separate from the Tags section above.
                </p>
                <div className="mt-4 flex items-center justify-between rounded-lg border border-violet-200 bg-white p-3 shadow-sm">
                    <div className="flex-1 pr-4">
                        <label className="text-sm font-medium text-gray-900">Enable Asset Field Intelligence</label>
                        <p className="mt-0.5 text-xs text-gray-500">
                            Score and suggest structured fields for new and edited assets.
                        </p>
                    </div>
                    <Toggle
                        checked={!!settings.ai_insights_enabled}
                        onChange={(enabled) => updateSetting('ai_insights_enabled', enabled)}
                        disabled={!canEdit}
                    />
                </div>
                <div className="mt-4 rounded-lg border border-violet-100/80 bg-white/80 px-4 py-3 shadow-sm">
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
                            {manualInsightsInCooldown && settings.insights_manual_run_available_at ? (
                                <p className="text-amber-800">
                                    Manual runs are limited to once every{' '}
                                    {typeof settings.manual_insights_run_cooldown_minutes === 'number'
                                        ? `${settings.manual_insights_run_cooldown_minutes} min`
                                        : 'a short window'}
                                    . Next allowed {formatRelativeTime(settings.insights_manual_run_available_at) ?? 'soon'}.
                                </p>
                            ) : null}
                        </div>
                        <button
                            type="button"
                            onClick={runInsightsNow}
                            disabled={
                                !canEdit ||
                                !settings.ai_insights_enabled ||
                                runInsightsLoading ||
                                manualInsightsInCooldown
                            }
                            className="inline-flex shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-violet-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {runInsightsLoading
                                ? 'Queueing…'
                                : manualInsightsInCooldown
                                  ? 'Cooldown'
                                  : 'Run now'}
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