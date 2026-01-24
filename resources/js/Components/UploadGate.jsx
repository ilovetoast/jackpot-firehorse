import { useState, useEffect } from 'react'
import { 
    ExclamationTriangleIcon, 
    ShieldExclamationIcon,
    CloudArrowUpIcon,
    XMarkIcon
} from '@heroicons/react/24/outline'
import { Link } from '@inertiajs/react'
import StorageWarning from './StorageWarning'
import { useStorageLimits } from '../hooks/useStorageLimits'

/**
 * UploadGate Component
 * 
 * Validates files against plan limits and shows warnings/errors before upload.
 * Prevents upload initiation when limits would be exceeded.
 * 
 * @param {Object} props
 * @param {Array} props.selectedFiles - Array of File objects selected for upload
 * @param {Function} props.onValidationChange - Callback with validation results
 * @param {boolean} props.autoValidate - Whether to auto-validate on file changes
 * @param {string} props.className - Additional CSS classes
 * @param {boolean} props.showStorageDetails - Whether to show detailed storage info
 */
export default function UploadGate({ 
    selectedFiles = [], 
    onValidationChange,
    autoValidate = true,
    className = '',
    showStorageDetails = true 
}) {
    const {
        storageInfo,
        isLoading,
        error: storageError,
        validateFiles,
        canUploadFiles,
        isAtStorageLimit,
    } = useStorageLimits()

    const [validationResults, setValidationResults] = useState(null)
    const [isValidating, setIsValidating] = useState(false)
    const [validationError, setValidationError] = useState(null)

    // Auto-validate when files change
    useEffect(() => {
        if (autoValidate && selectedFiles.length > 0) {
            handleValidation()
        } else if (selectedFiles.length === 0) {
            // Clear validation when no files selected
            setValidationResults(null)
            setValidationError(null)
            onValidationChange?.({
                canProceed: true,
                hasErrors: false,
                hasWarnings: false,
                files: [],
            })
        }
    }, [selectedFiles, autoValidate])

    const handleValidation = async () => {
        if (selectedFiles.length === 0) return

        setIsValidating(true)
        setValidationError(null)

        try {
            const results = await validateFiles(selectedFiles)
            
            if (results) {
                setValidationResults(results)
                
                // Prepare validation summary for parent
                const hasErrors = !results.batch_summary.can_upload_batch || 
                                 results.files.some(f => !f.can_upload)
                
                const hasWarnings = results.storage_info?.storage?.is_near_limit || 
                                   results.batch_summary.storage_exceeded

                const canProceed = results.batch_summary.can_upload_batch && 
                                  results.files.every(f => f.can_upload)

                onValidationChange?.({
                    canProceed,
                    hasErrors,
                    hasWarnings,
                    files: results.files,
                    batchSummary: results.batch_summary,
                    storageInfo: results.storage_info,
                })
            } else {
                throw new Error('Validation failed')
            }
        } catch (err) {
            console.error('Validation error:', err)
            setValidationError(err.message || 'Failed to validate files')
            onValidationChange?.({
                canProceed: false,
                hasErrors: true,
                hasWarnings: false,
                error: err.message,
            })
        } finally {
            setIsValidating(false)
        }
    }

    // Don't render anything if no files are selected
    if (selectedFiles.length === 0) {
        return null
    }

    const hasValidationResults = validationResults && validationResults.files
    const canProceed = validationResults?.batch_summary?.can_upload_batch && 
                      validationResults?.files?.every(f => f.can_upload)
    
    const hasFileErrors = hasValidationResults && 
                         validationResults.files.some(f => !f.can_upload)
    
    const storageExceeded = validationResults?.batch_summary?.storage_exceeded

    return (
        <div className={`space-y-4 ${className}`}>
            {/* Loading state */}
            {(isLoading || isValidating) && (
                <div className="rounded-lg bg-gray-50 p-4">
                    <div className="flex items-center gap-3">
                        <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-indigo-600"></div>
                        <span className="text-sm text-gray-600">
                            {isValidating ? 'Validating files...' : 'Checking storage limits...'}
                        </span>
                    </div>
                </div>
            )}

            {/* General storage error */}
            {(storageError || validationError) && (
                <div className="rounded-lg bg-red-50 border border-red-200 p-4">
                    <div className="flex items-start gap-3">
                        <ExclamationTriangleIcon className="h-5 w-5 text-red-500 flex-shrink-0" />
                        <div className="flex-1">
                            <h4 className="text-sm font-medium text-red-800 mb-1">
                                Validation Error
                            </h4>
                            <p className="text-sm text-red-700">
                                {storageError || validationError}
                            </p>
                            <button
                                onClick={handleValidation}
                                className="mt-2 text-sm text-red-600 hover:text-red-900 underline"
                            >
                                Try again
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Storage warning (always show if there are storage concerns) */}
            {storageInfo?.storage && !storageInfo.storage.is_unlimited && (
                <StorageWarning
                    storageInfo={storageInfo.storage}
                    selectedFiles={selectedFiles}
                    showDetails={showStorageDetails}
                />
            )}

            {/* File-specific validation results */}
            {hasValidationResults && hasFileErrors && (
                <div className="rounded-lg bg-red-50 border border-red-200 p-4">
                    <div className="flex items-start gap-3">
                        <ShieldExclamationIcon className="h-5 w-5 text-red-500 flex-shrink-0" />
                        <div className="flex-1">
                            <h4 className="text-sm font-medium text-red-800 mb-3">
                                Upload Blocked
                            </h4>
                            
                            <div className="space-y-2">
                                {validationResults.files
                                    .filter(f => !f.can_upload)
                                    .map((file, index) => (
                                        <div key={index} className="text-sm">
                                            <div className="font-medium text-red-800 mb-1">
                                                {file.file_name}
                                            </div>
                                            <ul className="text-red-700 space-y-1 ml-4">
                                                {file.errors.map((error, errorIndex) => (
                                                    <li key={errorIndex} className="list-disc">
                                                        {error.message}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ))}
                            </div>

                            <div className="mt-4 pt-3 border-t border-red-200">
                                <Link
                                    href="/app/billing"
                                    className="inline-flex items-center gap-2 text-sm font-medium text-red-600 hover:text-red-900"
                                >
                                    <CloudArrowUpIcon className="h-4 w-4" />
                                    Upgrade to upload larger files
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Batch summary (if storage would be exceeded) */}
            {hasValidationResults && storageExceeded && (
                <div className="rounded-lg bg-red-50 border border-red-200 p-4">
                    <div className="flex items-start gap-3">
                        <ExclamationTriangleIcon className="h-5 w-5 text-red-500 flex-shrink-0" />
                        <div className="flex-1">
                            <h4 className="text-sm font-medium text-red-800 mb-2">
                                Batch Upload Exceeds Storage Limit
                            </h4>
                            <div className="text-sm text-red-700 space-y-1">
                                <div>
                                    Total files: {validationResults.batch_summary.total_files}
                                </div>
                                <div>
                                    Total size: {validationResults.batch_summary.total_size_mb.toFixed(1)} MB
                                </div>
                            </div>
                            <p className="text-sm text-red-700 mt-2">
                                Try uploading fewer files at once or upgrade your plan.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Success state (files are valid) */}
            {hasValidationResults && canProceed && !storageExceeded && (
                <div className="rounded-lg bg-green-50 border border-green-200 p-3">
                    <div className="flex items-center gap-3">
                        <CloudArrowUpIcon className="h-5 w-5 text-green-500" />
                        <div className="text-sm text-green-800">
                            {validationResults.batch_summary.total_files === 1 ? (
                                <>Ready to upload 1 file ({validationResults.batch_summary.total_size_mb.toFixed(1)} MB)</>
                            ) : (
                                <>Ready to upload {validationResults.batch_summary.total_files} files ({validationResults.batch_summary.total_size_mb.toFixed(1)} MB)</>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}