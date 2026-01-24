import { useState, useEffect, useCallback } from 'react'
import { router } from '@inertiajs/react'

/**
 * Hook for managing storage limits and validation
 */
export function useStorageLimits() {
    const [storageInfo, setStorageInfo] = useState(null)
    const [isLoading, setIsLoading] = useState(false)
    const [error, setError] = useState(null)

    /**
     * Fetch current storage information
     */
    const fetchStorageInfo = useCallback(async () => {
        setIsLoading(true)
        setError(null)

        try {
            const response = await fetch('/app/uploads/storage-check', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`)
            }

            const data = await response.json()
            setStorageInfo(data)
            return data
        } catch (err) {
            console.error('Failed to fetch storage info:', err)
            setError('Failed to check storage limits')
            return null
        } finally {
            setIsLoading(false)
        }
    }, [])

    /**
     * Validate files before upload
     * @param {Array} files - Array of files to validate
     * @returns {Promise<Object>} Validation results
     */
    const validateFiles = useCallback(async (files) => {
        if (!files || files.length === 0) {
            return { 
                files: [], 
                batch_summary: { can_upload_batch: true },
                storage_info: storageInfo 
            }
        }

        setIsLoading(true)
        setError(null)

        try {
            // Prepare file data for validation
            const fileData = files.map(file => ({
                file_name: file.name,
                file_size: file.size,
            }))

            const response = await fetch('/app/uploads/validate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    files: fileData,
                }),
            })

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}))
                throw new Error(errorData.message || `HTTP ${response.status}`)
            }

            const data = await response.json()
            
            // Update storage info from validation response
            if (data.storage_info) {
                setStorageInfo(data)
            }

            return data
        } catch (err) {
            console.error('Failed to validate files:', err)
            setError(err.message || 'Failed to validate files')
            return null
        } finally {
            setIsLoading(false)
        }
    }, [storageInfo])

    /**
     * Check if a file can be uploaded
     * @param {File} file - File to check
     * @returns {boolean} Whether the file can be uploaded
     */
    const canUploadFile = useCallback((file) => {
        if (!storageInfo || !file) return true

        const storage = storageInfo.storage
        if (!storage || storage.is_unlimited) return true

        // Check if adding this file would exceed storage
        const fileSizeBytes = file.size
        const remainingBytes = storage.remaining_bytes || 0

        return fileSizeBytes <= remainingBytes
    }, [storageInfo])

    /**
     * Check if multiple files can be uploaded
     * @param {Array} files - Files to check
     * @returns {boolean} Whether all files can be uploaded
     */
    const canUploadFiles = useCallback((files) => {
        if (!storageInfo || !files || files.length === 0) return true

        const storage = storageInfo.storage
        if (!storage || storage.is_unlimited) return true

        // Check if adding these files would exceed storage
        const totalSize = files.reduce((sum, file) => sum + file.size, 0)
        const remainingBytes = storage.remaining_bytes || 0

        return totalSize <= remainingBytes
    }, [storageInfo])

    /**
     * Get storage usage percentage
     * @returns {number} Usage percentage (0-100)
     */
    const getStorageUsagePercentage = useCallback(() => {
        if (!storageInfo?.storage || storageInfo.storage.is_unlimited) {
            return 0
        }
        return storageInfo.storage.usage_percentage || 0
    }, [storageInfo])

    /**
     * Check if storage is near limit (80%+)
     * @returns {boolean} Whether storage is near limit
     */
    const isNearStorageLimit = useCallback(() => {
        return getStorageUsagePercentage() >= 80
    }, [getStorageUsagePercentage])

    /**
     * Check if storage is at limit (95%+)
     * @returns {boolean} Whether storage is at limit
     */
    const isAtStorageLimit = useCallback(() => {
        return getStorageUsagePercentage() >= 95
    }, [getStorageUsagePercentage])

    /**
     * Format file size for display
     * @param {number} bytes - Size in bytes
     * @returns {string} Formatted size string
     */
    const formatFileSize = useCallback((bytes) => {
        if (!bytes) return '0 B'

        const units = ['B', 'KB', 'MB', 'GB', 'TB']
        let size = bytes
        let unitIndex = 0

        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024
            unitIndex++
        }

        return `${size.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
    }, [])

    // Auto-fetch storage info on mount
    useEffect(() => {
        fetchStorageInfo()
    }, [fetchStorageInfo])

    return {
        // Data
        storageInfo,
        isLoading,
        error,

        // Actions
        fetchStorageInfo,
        validateFiles,
        
        // Validation helpers
        canUploadFile,
        canUploadFiles,
        
        // Status helpers
        getStorageUsagePercentage,
        isNearStorageLimit,
        isAtStorageLimit,
        
        // Utils
        formatFileSize,
    }
}