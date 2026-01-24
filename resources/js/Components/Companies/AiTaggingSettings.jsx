/**
 * AiTaggingSettings Component
 * 
 * Phase J.2.5: AI Tagging settings panel for Company Settings
 * 
 * Features:
 * - Master toggle for AI tagging
 * - AI tag suggestions toggle
 * - Auto-apply toggle (OFF by default)
 * - Quantity control with mode selector
 * - Debounced API updates
 * - Optimistic UI with error recovery
 * - Permission-based editing
 */

import { useState, useEffect, useCallback, useRef } from 'react'
import { ExclamationTriangleIcon, CheckCircleIcon, InformationCircleIcon } from '@heroicons/react/24/outline'
import { debounce } from 'lodash-es'

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
    
    // Get plan tag limits
    const planLimits = {
        free: { max: 1, name: 'Free' },
        starter: { max: 5, name: 'Starter' },
        pro: { max: 10, name: 'Pro' },
        enterprise: { max: 15, name: 'Enterprise' }
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

            {/* Master Toggle - Disable AI Tagging */}
            <div className="flex items-center justify-between">
                <div className="flex-1">
                    <label className="text-base font-medium text-gray-900">
                        AI Tagging
                    </label>
                    <p className="text-sm text-gray-500">
                        {isAiDisabled 
                            ? 'AI tagging is completely disabled. No AI calls will be made, no costs incurred.'
                            : 'Allow AI to generate tag suggestions for assets.'
                        }
                    </p>
                </div>
                <Toggle
                    checked={!isAiDisabled}
                    onChange={(enabled) => updateSetting('disable_ai_tagging', !enabled)}
                    disabled={!canEdit}
                />
            </div>

            {/* AI Disabled Notice */}
            {isAiDisabled && (
                <div className="rounded-md bg-gray-50 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-gray-600">
                                All AI tagging features are disabled. Enable AI Tagging above to configure individual features.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Child Settings (disabled when AI is off) */}
            <div className={`space-y-6 ${isAiDisabled ? 'opacity-50 pointer-events-none' : ''}`}>
                
                {/* AI Tag Suggestions Toggle */}
                <div className="flex items-center justify-between">
                    <div className="flex-1">
                        <label className="text-base font-medium text-gray-900">
                            AI Tag Suggestions
                        </label>
                        <p className="text-sm text-gray-500">
                            Show AI-generated tag suggestions to users for manual acceptance.
                        </p>
                    </div>
                    <Toggle
                        checked={settings.enable_ai_tag_suggestions}
                        onChange={(enabled) => updateSetting('enable_ai_tag_suggestions', enabled)}
                        disabled={!canEdit || isAiDisabled}
                    />
                </div>

                {/* Auto-Apply Toggle */}
                <div className="flex items-center justify-between">
                    <div className="flex-1">
                        <label className="text-base font-medium text-gray-900">
                            Auto-Apply AI Tags
                        </label>
                        <p className="text-sm text-gray-500">
                            Automatically apply high-confidence AI tags without user intervention. 
                            <span className="font-medium text-orange-600"> (Off by default - use carefully)</span>
                        </p>
                    </div>
                    <Toggle
                        checked={isAutoApplyEnabled}
                        onChange={(enabled) => updateSetting('enable_ai_tag_auto_apply', enabled)}
                        disabled={!canEdit || isAiDisabled}
                    />
                </div>

                {/* Quantity Control (shown when AI is enabled) */}
                {!isAiDisabled && (
                    <div className="bg-gray-50 rounded-lg p-4">
                        <label className="text-base font-medium text-gray-900">
                            Auto-Apply Tag Limit
                        </label>
                        <p className="text-sm text-gray-500 mb-4">
                            Configure how many tags AI can suggest or auto-apply per asset.
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
                            <span className="text-sm text-gray-700">tags per asset</span>
                        </div>
                        <p className="mt-2 text-sm text-gray-500">
                            Range: 1-{maxTagsPerAsset} tags ({planLimit.name} plan limit). <strong>{Math.min(5, maxTagsPerAsset)} is recommended</strong> for balanced quality and coverage. Higher limits may reduce tag quality.
                        </p>
                    </div>
                )}

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