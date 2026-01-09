import { useState, useEffect, useCallback, useRef } from 'react'
import uploadManager from '../utils/UploadManager'

/**
 * React hook for managing file uploads
 * 
 * Provides:
 * - Upload state management
 * - Resume/recovery on page load
 * - Progress tracking
 * - Error handling
 * 
 * @returns {Object} Upload manager interface
 */
export function useUploadManager() {
    const [uploads, setUploads] = useState(() => uploadManager.getUploads())
    const fileInputRefs = useRef(new Map())

    // Subscribe to upload state changes
    useEffect(() => {
        const unsubscribe = uploadManager.subscribe((newUploads) => {
            setUploads([...newUploads])
        })

        // Rehydrate on mount - restore persisted uploads
        rehydrateUploads()

        return unsubscribe
    }, [])

    /**
     * Rehydrate uploads from localStorage and reattach File objects
     */
    const rehydrateUploads = useCallback(async () => {
        const persistedUploads = uploadManager.getUploads()
        
        // For each persisted upload with uploadSessionId, check backend state
        for (const upload of persistedUploads) {
            if (!upload.uploadSessionId || upload.status === 'completed' || upload.status === 'cancelled') {
                continue
            }

            // Reattach file if we have a reference
            const fileInput = fileInputRefs.current.get(upload.clientReference)
            if (fileInput && fileInput.files && fileInput.files.length > 0) {
                uploadManager.reattachFile(upload.clientReference, fileInput.files[0])
            }

            // Query backend for resume metadata (never guess state)
            try {
                const resumeData = await uploadManager.fetchResumeMetadata(upload.uploadSessionId)

                if (resumeData.can_resume && upload.file) {
                    // Update upload state from backend truth
                    const currentUpload = uploadManager.getUpload(upload.clientReference)
                    if (currentUpload) {
                        currentUpload.uploadType = resumeData.upload_type
                        currentUpload.chunkSize = resumeData.chunk_size || currentUpload.chunkSize
                        currentUpload.multipartUploadId = resumeData.multipart_upload_id || currentUpload.multipartUploadId
                        currentUpload.status = resumeData.upload_session_status === 'initiating' ? 'initiating' : 
                                             resumeData.upload_session_status === 'uploading' ? 'uploading' :
                                             resumeData.upload_session_status === 'completed' ? 'completed' :
                                             resumeData.upload_session_status === 'failed' ? 'failed' :
                                             resumeData.upload_session_status === 'cancelled' ? 'cancelled' :
                                             'pending'
                        
                        if (resumeData.is_expired) {
                            currentUpload.status = 'failed'
                            currentUpload.error = 'Upload session has expired'
                        }

                        uploadManager.persistToStorage()
                        uploadManager.notifyListeners()
                    }
                } else if (resumeData.upload_session_status === 'completed') {
                    // Upload was completed - update frontend state
                    const currentUpload = uploadManager.getUpload(upload.clientReference)
                    if (currentUpload) {
                        currentUpload.status = 'completed'
                        currentUpload.progress = 100
                        uploadManager.persistToStorage()
                        uploadManager.notifyListeners()
                    }
                } else if (!resumeData.can_resume) {
                    // Cannot resume - mark as failed
                    const currentUpload = uploadManager.getUpload(upload.clientReference)
                    if (currentUpload) {
                        currentUpload.status = 'failed'
                        currentUpload.error = resumeData.error || 'Cannot resume upload'
                        uploadManager.persistToStorage()
                        uploadManager.notifyListeners()
                    }
                }
            } catch (error) {
                console.error('Failed to rehydrate upload:', error)
                // Don't fail silently - mark as failed
                const currentUpload = uploadManager.getUpload(upload.clientReference)
                if (currentUpload) {
                    currentUpload.status = 'failed'
                    currentUpload.error = error.message || 'Failed to check upload status'
                    uploadManager.persistToStorage()
                    uploadManager.notifyListeners()
                }
            }
        }
    }, [])

    /**
     * Add files to upload queue
     * @param {File[]} files
     * @param {Object} options
     * @param {string} [options.brandId]
     * @param {string} [options.batchReference]
     * @param {HTMLInputElement} [options.fileInput] - File input element for rehydration
     * @returns {string[]} Array of client references
     */
    const addFiles = useCallback((files, options = {}) => {
        const clientReferences = uploadManager.addFiles(files, options)
        
        // Store file input reference for rehydration
        if (options.fileInput) {
            clientReferences.forEach(ref => {
                fileInputRefs.current.set(ref, options.fileInput)
            })
        }
        
        return clientReferences
    }, [])

    /**
     * Start upload for a single file
     * @param {string} clientReference
     */
    const startUpload = useCallback((clientReference) => {
        return uploadManager.startUpload(clientReference)
    }, [])

    /**
     * Resume upload
     * @param {string} clientReference
     */
    const resumeUpload = useCallback((clientReference) => {
        return uploadManager.resumeUpload(clientReference)
    }, [])

    /**
     * Cancel upload
     * @param {string} clientReference
     */
    const cancelUpload = useCallback((clientReference) => {
        return uploadManager.cancelUpload(clientReference)
    }, [])

    /**
     * Retry failed upload
     * @param {string} clientReference
     */
    const retryUpload = useCallback((clientReference) => {
        return uploadManager.retryUpload(clientReference)
    }, [])

    /**
     * Remove upload from manager
     * @param {string} clientReference
     */
    const removeUpload = useCallback((clientReference) => {
        uploadManager.removeUpload(clientReference)
        fileInputRefs.current.delete(clientReference)
    }, [])

    /**
     * Get upload by client reference
     * @param {string} clientReference
     * @returns {Object|null}
     */
    const getUpload = useCallback((clientReference) => {
        return uploadManager.getUpload(clientReference)
    }, [])

    /**
     * Get aggregate progress across all active uploads
     * @returns {number} Progress percentage (0-100)
     */
    const getAggregateProgress = useCallback(() => {
        const activeUploads = uploads.filter(u => 
            u.status === 'uploading' || u.status === 'initiating'
        )
        
        if (activeUploads.length === 0) {
            return 0
        }

        const totalProgress = activeUploads.reduce((sum, u) => sum + u.progress, 0)
        return Math.round(totalProgress / activeUploads.length)
    }, [uploads])

    /**
     * Get uploads by status
     * @param {string|string[]} status
     * @returns {Object[]}
     */
    const getUploadsByStatus = useCallback((status) => {
        const statuses = Array.isArray(status) ? status : [status]
        return uploads.filter(u => statuses.includes(u.status))
    }, [uploads])

    return {
        // State
        uploads,
        activeUploads: uploads.filter(u => u.status === 'uploading' || u.status === 'initiating'),
        completedUploads: uploads.filter(u => u.status === 'completed'),
        failedUploads: uploads.filter(u => u.status === 'failed'),
        cancelledUploads: uploads.filter(u => u.status === 'cancelled'),
        
        // Methods
        addFiles,
        startUpload,
        resumeUpload,
        cancelUpload,
        retryUpload,
        removeUpload,
        getUpload,
        getAggregateProgress,
        getUploadsByStatus,
        
        // Utilities
        rehydrateUploads,
    }
}

export default useUploadManager
