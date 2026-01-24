import { useState, useEffect } from 'react'
import { 
    ExclamationTriangleIcon, 
    InformationCircleIcon,
    ServerIcon,
    ArrowUpIcon
} from '@heroicons/react/24/outline'
import { Link } from '@inertiajs/react'

/**
 * StorageWarning Component
 * 
 * Shows storage usage warnings and limits for upload operations.
 * 
 * @param {Object} props
 * @param {Object} props.storageInfo - Storage information from PlanService
 * @param {Array} props.selectedFiles - Array of selected files to upload (optional)
 * @param {string} props.className - Additional CSS classes
 * @param {boolean} props.showDetails - Whether to show detailed storage info
 */
export default function StorageWarning({ 
    storageInfo, 
    selectedFiles = [], 
    className = '', 
    showDetails = false 
}) {
    const [additionalSize, setAdditionalSize] = useState(0)

    useEffect(() => {
        if (selectedFiles && selectedFiles.length > 0) {
            const totalSize = selectedFiles.reduce((sum, file) => sum + (file.size || 0), 0)
            setAdditionalSize(totalSize)
        } else {
            setAdditionalSize(0)
        }
    }, [selectedFiles])

    if (!storageInfo || storageInfo.is_unlimited) {
        return null // No warning needed for unlimited plans
    }

    const currentUsageMB = storageInfo.current_usage_mb || 0
    const maxStorageMB = storageInfo.max_storage_mb || 0
    const usagePercentage = storageInfo.usage_percentage || 0
    const remainingMB = storageInfo.remaining_mb || 0
    
    // Calculate usage after adding selected files
    const additionalMB = additionalSize / 1024 / 1024
    const projectedUsageMB = currentUsageMB + additionalMB
    const projectedPercentage = (projectedUsageMB / maxStorageMB) * 100

    // Determine warning level
    const isAtLimit = usagePercentage >= 95
    const isNearLimit = usagePercentage >= 80
    const wouldExceedLimit = projectedPercentage > 100
    const wouldBeNearLimit = projectedPercentage >= 80

    // Don't show anything if usage is low and no files selected
    if (usagePercentage < 80 && additionalSize === 0) {
        return null
    }

    let warningLevel = 'info'
    let icon = InformationCircleIcon
    let iconColor = 'text-blue-500'
    let bgColor = 'bg-blue-50'
    let borderColor = 'border-blue-200'
    let textColor = 'text-blue-800'

    if (wouldExceedLimit || isAtLimit) {
        warningLevel = 'error'
        icon = ExclamationTriangleIcon
        iconColor = 'text-red-500'
        bgColor = 'bg-red-50'
        borderColor = 'border-red-200'
        textColor = 'text-red-800'
    } else if (wouldBeNearLimit || isNearLimit) {
        warningLevel = 'warning'
        icon = ExclamationTriangleIcon
        iconColor = 'text-yellow-500'
        bgColor = 'bg-yellow-50'
        borderColor = 'border-yellow-200'
        textColor = 'text-yellow-800'
    }

    const IconComponent = icon

    const formatSize = (mb) => {
        if (mb >= 1024) {
            return `${(mb / 1024).toFixed(1)} GB`
        }
        return `${mb.toFixed(1)} MB`
    }

    const getWarningMessage = () => {
        if (wouldExceedLimit) {
            return "These files would exceed your storage limit and cannot be uploaded."
        } else if (isAtLimit) {
            return "Your storage is full. Remove some assets or upgrade your plan to continue uploading."
        } else if (wouldBeNearLimit && additionalSize > 0) {
            return "These files would bring you close to your storage limit."
        } else if (isNearLimit) {
            return "You're approaching your storage limit."
        } else {
            return "Storage usage information"
        }
    }

    return (
        <div className={`rounded-lg border ${borderColor} ${bgColor} p-4 ${className}`}>
            <div className="flex items-start gap-3">
                <div className="flex-shrink-0">
                    <IconComponent className={`h-5 w-5 ${iconColor}`} />
                </div>
                
                <div className="flex-1 min-w-0">
                    <h4 className={`text-sm font-medium ${textColor} mb-2`}>
                        {getWarningMessage()}
                    </h4>
                    
                    {/* Storage Usage Bar */}
                    <div className="mb-3">
                        <div className="flex items-center justify-between text-xs mb-1">
                            <span className={textColor}>Storage Usage</span>
                            <span className={`font-medium ${textColor}`}>
                                {additionalSize > 0 ? (
                                    <>
                                        {formatSize(currentUsageMB)} 
                                        <span className="mx-1">→</span>
                                        {formatSize(projectedUsageMB)} / {formatSize(maxStorageMB)}
                                    </>
                                ) : (
                                    <>
                                        {formatSize(currentUsageMB)} / {formatSize(maxStorageMB)}
                                    </>
                                )}
                            </span>
                        </div>
                        
                        <div className="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                            {/* Current usage */}
                            <div
                                className="h-2 bg-gray-400 transition-all duration-300"
                                style={{ width: `${Math.min(usagePercentage, 100)}%` }}
                            />
                            
                            {/* Additional usage (if files selected) */}
                            {additionalSize > 0 && !wouldExceedLimit && (
                                <div
                                    className="h-2 bg-blue-400 transition-all duration-300 relative"
                                    style={{ 
                                        width: `${Math.min(projectedPercentage - usagePercentage, 100 - usagePercentage)}%`,
                                        marginTop: '-0.5rem'
                                    }}
                                />
                            )}
                            
                            {/* Overflow indicator */}
                            {wouldExceedLimit && (
                                <div
                                    className="h-2 bg-red-500 transition-all duration-300 relative"
                                    style={{ 
                                        width: '100%',
                                        marginTop: '-0.5rem'
                                    }}
                                />
                            )}
                        </div>
                    </div>

                    {/* Details */}
                    {showDetails && (
                        <div className={`text-xs ${textColor} space-y-1`}>
                            <div className="flex justify-between">
                                <span>Current usage:</span>
                                <span className="font-medium">{formatSize(currentUsageMB)}</span>
                            </div>
                            {additionalSize > 0 && (
                                <div className="flex justify-between">
                                    <span>Selected files:</span>
                                    <span className="font-medium">+{formatSize(additionalMB)}</span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span>Plan limit:</span>
                                <span className="font-medium">{formatSize(maxStorageMB)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span>Remaining:</span>
                                <span className="font-medium">
                                    {formatSize(Math.max(0, remainingMB - additionalMB))}
                                </span>
                            </div>
                        </div>
                    )}

                    {/* Upgrade prompt */}
                    {(isAtLimit || wouldExceedLimit) && (
                        <div className="mt-3 pt-3 border-t border-current border-opacity-20">
                            <Link
                                href="/app/billing"
                                className={`inline-flex items-center gap-2 text-sm font-medium ${textColor} hover:underline`}
                            >
                                <ArrowUpIcon className="h-4 w-4" />
                                Upgrade for more storage
                            </Link>
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}