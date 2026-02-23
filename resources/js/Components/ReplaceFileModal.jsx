/**
 * Phase J.3.1: Replace File Modal Component
 * 
 * Modal for contributors to replace the file of a rejected asset.
 * Single file upload only, no metadata editing, optional comment.
 */

import { useState, useRef } from 'react'
import { XMarkIcon, CloudArrowUpIcon } from '@heroicons/react/24/outline'
import { usePage } from '@inertiajs/react'

export default function ReplaceFileModal({ asset, isOpen, onClose, onSuccess }) {
    const { auth } = usePage().props
    const planAllowsVersions = auth?.plan_allows_versions ?? false
    const actionLabel = planAllowsVersions ? 'Upload New Version' : 'Replace File'
    const [selectedFile, setSelectedFile] = useState(null)
    const [comment, setComment] = useState('')
    const [uploading, setUploading] = useState(false)
    const [uploadProgress, setUploadProgress] = useState(0)
    const [error, setError] = useState(null)
    const fileInputRef = useRef(null)

    if (!isOpen || !asset) return null

    const handleFileSelect = (e) => {
        const file = e.target.files?.[0]
        if (file) {
            setSelectedFile(file)
            setError(null)
        }
    }

    /**
     * Perform multipart (chunked) upload for replace-file.
     * Calls multipart/init, uploads each part via sign-part + PUT, then multipart/complete.
     * @param {string} uploadSessionId
     * @param {File} file
     * @param {function(number): void} onProgress - callback with 0-100 progress
     */
    const performMultipartReplaceUpload = async (uploadSessionId, file, onProgress) => {
        const initRes = await window.axios.post(`/app/uploads/${uploadSessionId}/multipart/init`)
        const { part_size: partSize, total_parts: totalParts } = initRes.data

        const parts = {}
        for (let partNumber = 1; partNumber <= totalParts; partNumber++) {
            const start = (partNumber - 1) * partSize
            const end = Math.min(start + partSize, file.size)
            const chunk = file.slice(start, end)

            const signRes = await window.axios.post(
                `/app/uploads/${uploadSessionId}/multipart/sign-part`,
                { part_number: partNumber }
            )
            const partUrl = signRes.data.upload_url

            const putRes = await fetch(partUrl, {
                method: 'PUT',
                body: chunk,
            })
            if (!putRes.ok) {
                throw new Error(`Part ${partNumber} upload failed: ${putRes.status} ${putRes.statusText}`)
            }
            const etag = putRes.headers.get('ETag')?.replace(/"/g, '')
            if (!etag) throw new Error(`No ETag for part ${partNumber}`)
            parts[String(partNumber)] = etag

            onProgress(Math.round((partNumber / totalParts) * 100))
        }

        await window.axios.post(`/app/uploads/${uploadSessionId}/multipart/complete`, { parts })
        onProgress(100)
    }

    const handleReplace = async () => {
        if (!selectedFile || uploading) return

        setUploading(true)
        setUploadProgress(0)
        setError(null)

        try {
            // Step 1: Initiate replace upload session
            const initiateResponse = await window.axios.post(
                `/app/assets/${asset.id}/replace-file`,
                {
                    file_name: selectedFile.name,
                    file_size: selectedFile.size,
                    mime_type: selectedFile.type,
                }
            )

            const { upload_session_id, upload_type, upload_url } = initiateResponse.data

            // Step 2: Upload file to S3 (direct or multipart)
            if (upload_type === 'direct' && upload_url) {
                // Direct upload: PUT file to S3 using fetch (pre-signed URL)
                const uploadResponse = await fetch(upload_url, {
                    method: 'PUT',
                    body: selectedFile,
                    headers: {
                        'Content-Type': selectedFile.type || 'application/octet-stream',
                    },
                })

                if (!uploadResponse.ok) {
                    throw new Error(`Upload failed: ${uploadResponse.status} ${uploadResponse.statusText}`)
                }
                setUploadProgress(100)
            } else if (upload_type === 'chunked') {
                // Chunked upload: multipart init → upload parts → complete
                await performMultipartReplaceUpload(
                    upload_session_id,
                    selectedFile,
                    (p) => setUploadProgress(p)
                )
            } else {
                throw new Error(`Unsupported upload type: ${upload_type}`)
            }

            // Step 3: Finalize upload (replace file)
            const finalizeResponse = await window.axios.post('/app/uploads/finalize', {
                manifest: [
                    {
                        upload_key: `temp/uploads/${upload_session_id}/original`,
                        expected_size: selectedFile.size,
                        resolved_filename: selectedFile.name,
                        comment: comment.trim() || null,
                    },
                ],
            })

            if (finalizeResponse.data?.results?.[0]?.status === 'success') {
                onSuccess()
            } else {
                // Extract error message from error object (may be string or object with message property)
                const errorData = finalizeResponse.data?.results?.[0]?.error
                const errorMessage = typeof errorData === 'string' 
                    ? errorData 
                    : errorData?.message || 'Finalization failed'
                throw new Error(errorMessage)
            }
        } catch (error) {
            console.error('[ReplaceFileModal] Failed to replace file', error)
            // Extract error message safely (handle objects, arrays, etc.)
            let errorMessage = 'Failed to replace file. Please try again.'
            if (error.response?.data?.error) {
                errorMessage = typeof error.response.data.error === 'string' 
                    ? error.response.data.error 
                    : error.response.data.error?.message || JSON.stringify(error.response.data.error)
            } else if (error.response?.data?.message) {
                errorMessage = typeof error.response.data.message === 'string'
                    ? error.response.data.message
                    : JSON.stringify(error.response.data.message)
            } else if (error.message) {
                errorMessage = error.message
            }
            setError(errorMessage)
        } finally {
            setUploading(false)
            setUploadProgress(0)
        }
    }

    const handleClose = () => {
        if (!uploading) {
            setSelectedFile(null)
            setComment('')
            setUploadProgress(0)
            setError(null)
            if (fileInputRef.current) {
                fileInputRef.current.value = ''
            }
            onClose()
        }
    }

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={handleClose}
                />
                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                    <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                        <button
                            type="button"
                            className="rounded-md bg-white text-gray-400 hover:text-gray-500"
                            onClick={handleClose}
                            disabled={uploading}
                        >
                            <XMarkIcon className="h-6 w-6" />
                        </button>
                    </div>
                    <div className="sm:flex sm:items-start">
                        <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                            <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                {actionLabel}
                            </h3>
                            <p className="text-sm text-gray-500 mb-4">
                                {planAllowsVersions
                                    ? 'Upload a new version of this asset. Version history will be preserved and the asset will be reviewed again before publishing.'
                                    : 'Replace the file for this asset. Metadata will remain unchanged and the asset will be reviewed again before publishing.'}
                            </p>

                            {/* File Input */}
                            <div className="mt-4">
                                <label htmlFor="replace-file-input" className="block text-sm font-medium text-gray-700 mb-2">
                                    Select File
                                </label>
                                <input
                                    ref={fileInputRef}
                                    id="replace-file-input"
                                    type="file"
                                    onChange={handleFileSelect}
                                    disabled={uploading}
                                    className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                                />
                                {selectedFile && (
                                    <p className="mt-2 text-sm text-gray-600">
                                        Selected: {selectedFile.name} ({(selectedFile.size / 1024 / 1024).toFixed(2)} MB)
                                    </p>
                                )}
                            </div>

                            {/* Optional Comment */}
                            <div className="mt-4">
                                <label htmlFor="replace-comment" className="block text-sm font-medium text-gray-700 mb-2">
                                    Comment (optional)
                                </label>
                                <textarea
                                    id="replace-comment"
                                    rows={3}
                                    value={comment}
                                    onChange={(e) => setComment(e.target.value)}
                                    disabled={uploading}
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    placeholder="Add a comment about the file replacement..."
                                />
                            </div>

                            {/* Upload Progress */}
                            {uploading && (
                                <div className="mt-4">
                                    <div className="flex items-center justify-between text-sm text-gray-600 mb-1">
                                        <span>Uploading...</span>
                                        <span>{uploadProgress}%</span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                                            style={{ width: `${uploadProgress}%` }}
                                        />
                                    </div>
                                </div>
                            )}

                            {/* Error Display */}
                            {error && (
                                <div className="mt-4 rounded-md bg-red-50 p-4">
                                    <div className="flex">
                                        <div className="flex-shrink-0">
                                            <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clipRule="evenodd" />
                                            </svg>
                                        </div>
                                        <div className="ml-3">
                                            <p className="text-sm font-medium text-red-800">{error}</p>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-2">
                        <button
                            type="button"
                            disabled={!selectedFile || uploading}
                            onClick={handleReplace}
                            className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:ml-3 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {uploading ? (planAllowsVersions ? 'Uploading...' : 'Replacing...') : actionLabel}
                        </button>
                        <button
                            type="button"
                            onClick={handleClose}
                            disabled={uploading}
                            className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto disabled:opacity-50"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
