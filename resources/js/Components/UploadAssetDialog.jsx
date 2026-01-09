// Phase 2 verification UI — NOT final UX
// Safe to refactor or remove in Phase 3
// See docs/PHASE_2_UPLOAD_SYSTEM.md for Phase 2 status

import { useState, useCallback, useRef, useEffect } from 'react'
import { usePage, router } from '@inertiajs/react'
import { XMarkIcon, CloudArrowUpIcon, ExclamationCircleIcon } from '@heroicons/react/24/outline'
import UploadManager from '../utils/UploadManager'

/**
 * UploadAssetDialog - Minimal drag-and-drop upload dialog for Phase 2 verification
 * 
 * ⚠️ This is a temporary verification harness, NOT final UX.
 * Safe to refactor or remove in Phase 3.
 * 
 * Phase 2 is locked. See docs/PHASE_2_UPLOAD_SYSTEM.md for status.
 * 
 * @param {boolean} open - Whether dialog is open
 * @param {function} onClose - Callback when dialog closes
 * @param {string} defaultAssetType - Default asset type ('basic' or 'marketing')
 * @param {Array} categories - Categories array from page props
 */
export default function UploadAssetDialog({ open, onClose, defaultAssetType = 'basic', categories = [] }) {
    const { auth } = usePage().props
    const [selectedFiles, setSelectedFiles] = useState([])
    const [categoryId, setCategoryId] = useState('')
    const [categoryError, setCategoryError] = useState('')
    const [isDragging, setIsDragging] = useState(false)
    const [isUploading, setIsUploading] = useState(false)
    const [uploadProgress, setUploadProgress] = useState({})
    const [uploadErrors, setUploadErrors] = useState({})
    const fileInputRef = useRef(null)
    const dropZoneRef = useRef(null)
    const fileIdToClientRefMap = useRef(new Map())
    
    // UploadManager is exported as a singleton instance, use it directly
    const uploadManager = UploadManager

    // Set up upload listener
    useEffect(() => {
        // Listen for upload updates
        const handleUpdate = () => {
            const uploads = uploadManager.uploads
            const progress = {}
            const errors = {}
            
            uploads.forEach((upload, clientRef) => {
                // Find file ID from client ref map
                const fileId = Array.from(fileIdToClientRefMap.current.entries()).find(
                    ([, ref]) => ref === clientRef
                )?.[0]
                
                if (fileId) {
                    progress[fileId] = upload.progress || 0
                    if (upload.error) {
                        errors[fileId] = upload.error
                    }
                }
            })
            
            setUploadProgress(progress)
            setUploadErrors(errors)
            
            // Check if all uploads are complete or failed
            const allUploads = Array.from(uploads.values())
            if (allUploads.length > 0 && isUploading) {
                const allDone = allUploads.every(
                    u => u.status === 'completed' || u.status === 'failed'
                )
                if (allDone) {
                    // Check if we need to complete asset creation
                    checkAndCompleteAssets()
                }
            }
        }
        
        // Subscribe to upload updates (subscribe returns unsubscribe function)
        const unsubscribe = uploadManager.subscribe(handleUpdate)
        
        // Cleanup on unmount
        return () => {
            unsubscribe()
        }
    }, [isUploading])

    // Reset form when dialog closes
    useEffect(() => {
        if (!open) {
            setSelectedFiles([])
            setCategoryId('')
            setCategoryError('')
            setIsUploading(false)
            setUploadProgress({})
            setUploadErrors({})
            fileIdToClientRefMap.current.clear()
        }
    }, [open])

    /**
     * Handle file selection
     */
    const handleFileSelect = useCallback((files) => {
        const fileArray = Array.from(files).map(file => ({
            file,
            id: `${Date.now()}_${Math.random().toString(36).substring(7)}`,
            name: file.name,
            size: file.size,
            type: file.type,
        }))
        setSelectedFiles(prev => [...prev, ...fileArray])
    }, [])

    /**
     * Handle drag events
     */
    const handleDragEnter = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        setIsDragging(true)
    }, [])

    const handleDragLeave = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        if (!dropZoneRef.current?.contains(e.relatedTarget)) {
            setIsDragging(false)
        }
    }, [])

    const handleDragOver = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
    }, [])

    const handleDrop = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        setIsDragging(false)
        
        const files = e.dataTransfer.files
        if (files.length > 0) {
            handleFileSelect(files)
        }
    }, [handleFileSelect])

    /**
     * Remove file from selection
     */
    const removeFile = (fileId) => {
        setSelectedFiles(prev => prev.filter(f => f.id !== fileId))
    }

    /**
     * Validate form
     */
    const validateForm = () => {
        if (!categoryId) {
            setCategoryError('Category is required')
            return false
        }
        if (selectedFiles.length === 0) {
            return false
        }
        setCategoryError('')
        return true
    }

    /**
     * Check and complete asset creation for all successful uploads
     * 
     * NOTE: Category assignment is validated in UI but not yet passed to backend.
     * The upload completion endpoint doesn't currently accept category_id.
     * This will need to be added to the backend in a future update.
     */
    const checkAndCompleteAssets = async () => {
        const uploads = uploadManager.uploads
        const completionPromises = []
        
        // Complete asset creation for each successful upload
        uploads.forEach((upload, clientRef) => {
            if (upload.status === 'completed' && upload.uploadSessionId && !upload.assetCompleted) {
                // Mark as in progress to prevent duplicate calls
                upload.assetCompleted = 'in_progress'
                
                const promise = window.axios.post('/app/assets/upload/complete', {
                    upload_session_id: upload.uploadSessionId,
                    asset_type: defaultAssetType === 'basic' ? 'asset' : 'marketing',
                    // TODO: Add category_id once backend supports it
                    // category_id: categoryId,
                })
                .then(() => {
                    upload.assetCompleted = true
                })
                .catch(error => {
                    upload.assetCompleted = false
                    console.error('Failed to complete asset creation:', error)
                    const fileId = Array.from(fileIdToClientRefMap.current.entries()).find(
                        ([, ref]) => ref === clientRef
                    )?.[0]
                    if (fileId) {
                        setUploadErrors(prev => ({
                            ...prev,
                            [fileId]: error.response?.data?.message || 'Failed to create asset'
                        }))
                    }
                })
                completionPromises.push(promise)
            }
        })
        
        await Promise.allSettled(completionPromises)
        
        // Check if all succeeded
        const allSucceeded = Array.from(uploads.values()).every(
            u => u.status === 'completed' && u.assetCompleted === true
        )
        
        if (allSucceeded) {
            setIsUploading(false)
            onClose()
            // Reload to refresh asset list
            router.reload()
        } else {
            setIsUploading(false)
            // Keep dialog open to show errors
        }
    }

    /**
     * Handle form submission
     */
    const handleSubmit = async (e) => {
        e.preventDefault()
        
        if (!validateForm()) {
            return
        }

        setIsUploading(true)
        setUploadErrors({})

        try {
            // Add each file to upload manager and start upload
            for (const fileItem of selectedFiles) {
                const clientRefs = uploadManager.addFiles([fileItem.file], {
                    brandId: auth.activeBrand?.id,
                })
                
                if (clientRefs.length > 0) {
                    const clientRef = clientRefs[0]
                    fileIdToClientRefMap.current.set(fileItem.id, clientRef)
                    
                    // Start upload (non-blocking - will complete via event handler)
                    uploadManager.startUpload(clientRef).catch(error => {
                        setUploadErrors(prev => ({
                            ...prev,
                            [fileItem.id]: error.message || 'Upload failed'
                        }))
                    })
                }
            }
            
            // Note: Asset completion will be handled in checkAndCompleteAssets()
            // which is called from the upload update handler
        } catch (error) {
            console.error('Upload failed:', error)
            setIsUploading(false)
        }
    }

    if (!open) return null

    // Filter categories by asset type
    const filteredCategories = (categories || []).filter(cat => {
        if (defaultAssetType === 'basic') {
            // Asset pages show 'basic' or 'asset' type categories
            return cat.asset_type === 'basic' || cat.asset_type === 'asset'
        } else {
            return cat.asset_type === 'marketing'
        }
    })

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div className="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {/* Background overlay */}
                <div
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={!isUploading ? onClose : undefined}
                />

                {/* Modal panel */}
                <div className="relative inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle">
                    <div className="bg-white px-4 pt-5 pb-4 sm:p-6">
                        {/* Header */}
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-medium leading-6 text-gray-900" id="modal-title">
                                Add {defaultAssetType === 'basic' ? 'Asset' : 'Marketing Asset'}
                            </h3>
                            {!isUploading && (
                                <button
                                    type="button"
                                    className="text-gray-400 hover:text-gray-500"
                                    onClick={onClose}
                                >
                                    <XMarkIcon className="h-6 w-6" />
                                </button>
                            )}
                        </div>

                        <form onSubmit={handleSubmit}>
                            {/* Asset Type (read-only) */}
                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Asset Type
                                </label>
                                <input
                                    type="text"
                                    value={defaultAssetType === 'basic' ? 'Asset' : 'Marketing Asset'}
                                    readOnly
                                    disabled
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm bg-gray-50"
                                />
                            </div>

                            {/* Category (required) */}
                            <div className="mb-4">
                                <label htmlFor="category" className="block text-sm font-medium text-gray-700 mb-1">
                                    Category <span className="text-red-500">*</span>
                                </label>
                                <select
                                    id="category"
                                    value={categoryId}
                                    onChange={(e) => {
                                        setCategoryId(e.target.value)
                                        setCategoryError('')
                                    }}
                                    disabled={isUploading}
                                    className={`block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm ${
                                        categoryError ? 'border-red-300' : ''
                                    }`}
                                >
                                    <option value="">Select a category</option>
                                    {filteredCategories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </select>
                                {categoryError && (
                                    <p className="mt-1 text-sm text-red-600">{categoryError}</p>
                                )}
                            </div>

                            {/* Drag and drop area */}
                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-1">
                                    Files
                                </label>
                                <div
                                    ref={dropZoneRef}
                                    onDragEnter={handleDragEnter}
                                    onDragLeave={handleDragLeave}
                                    onDragOver={handleDragOver}
                                    onDrop={handleDrop}
                                    className={`border-2 border-dashed rounded-lg p-6 text-center ${
                                        isDragging
                                            ? 'border-indigo-500 bg-indigo-50'
                                            : 'border-gray-300 hover:border-gray-400'
                                    } ${isUploading ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
                                    onClick={() => !isUploading && fileInputRef.current?.click()}
                                >
                                    <CloudArrowUpIcon className="mx-auto h-12 w-12 text-gray-400" />
                                    <p className="mt-2 text-sm text-gray-600">
                                        {isDragging
                                            ? 'Drop files here'
                                            : 'Drag and drop files here, or click to select'}
                                    </p>
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        multiple
                                        className="hidden"
                                        onChange={(e) => handleFileSelect(e.target.files)}
                                        disabled={isUploading}
                                    />
                                </div>

                                {/* Selected files list */}
                                {selectedFiles.length > 0 && (
                                    <div className="mt-2 space-y-1">
                                        {selectedFiles.map((fileItem) => (
                                            <div
                                                key={fileItem.id}
                                                className="flex items-center justify-between text-sm bg-gray-50 rounded px-2 py-1"
                                            >
                                                <span className="truncate">{fileItem.name}</span>
                                                <div className="flex items-center gap-2">
                                                    {uploadProgress[fileItem.id] !== undefined && (
                                                        <span className="text-xs text-gray-500">
                                                            {uploadProgress[fileItem.id]}%
                                                        </span>
                                                    )}
                                                    {uploadErrors[fileItem.id] && (
                                                        <ExclamationCircleIcon className="h-4 w-4 text-red-500" />
                                                    )}
                                                    {!isUploading && (
                                                        <button
                                                            type="button"
                                                            onClick={() => removeFile(fileItem.id)}
                                                            className="text-red-500 hover:text-red-700"
                                                        >
                                                            <XMarkIcon className="h-4 w-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Error messages */}
                            {Object.keys(uploadErrors).length > 0 && (
                                <div className="mb-4 rounded-md bg-red-50 p-4">
                                    <div className="flex">
                                        <ExclamationCircleIcon className="h-5 w-5 text-red-400" />
                                        <div className="ml-3">
                                            <h3 className="text-sm font-medium text-red-800">
                                                Upload errors
                                            </h3>
                                            <div className="mt-2 text-sm text-red-700">
                                                <ul className="list-disc list-inside space-y-1">
                                                    {Object.values(uploadErrors).map((error, idx) => (
                                                        <li key={idx}>{error}</li>
                                                    ))}
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Submit button */}
                            <div className="flex justify-end gap-3">
                                {!isUploading && (
                                    <button
                                        type="button"
                                        onClick={onClose}
                                        className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                    >
                                        Cancel
                                    </button>
                                )}
                                <button
                                    type="submit"
                                    disabled={
                                        isUploading ||
                                        selectedFiles.length === 0 ||
                                        !categoryId
                                    }
                                    className="rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {isUploading ? 'Uploading...' : 'Upload'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    )
}
