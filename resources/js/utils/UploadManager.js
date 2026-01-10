/**
 * UploadManager - Core orchestration for file uploads
 * 
 * Single source of truth for managing:
 * - Multiple parallel uploads
 * - Resumable uploads
 * - Retries and cancellation
 * - Refresh-safe state recovery
 * 
 * SAFETY RULES:
 * - Never guesses backend state - always queries /resume endpoint
 * - Never stores File objects long-term in memory
 * - Never hides failures
 * - All backend operations are idempotent
 */

const STORAGE_KEY = 'upload_manager_state'
const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024 // 5 MB (must match backend)
const MAX_PARALLEL_UPLOADS = 5 // Configurable limit
const ACTIVITY_UPDATE_INTERVAL = 10000 // 10 seconds

/**
 * UploadItem data model
 * @typedef {Object} UploadItem
 * @property {string} clientReference - Unique client-side identifier
 * @property {string} [uploadSessionId] - Backend upload session ID
 * @property {File} file - The file being uploaded
 * @property {string} fileName - Original filename
 * @property {number} fileSize - File size in bytes
 * @property {string} mimeType - MIME type
 * @property {('direct'|'chunked')} uploadType - Upload strategy
 * @property {number} [chunkSize] - Chunk size for multipart uploads
 * @property {string} [multipartUploadId] - S3 multipart upload ID
 * @property {string} [uploadUrl] - Pre-signed URL for direct uploads (temporary, not persisted)
 * @property {('pending'|'initiating'|'uploading'|'paused'|'completed'|'failed'|'cancelled')} status - Upload status
 * @property {number} progress - Upload progress (0-100)
 * @property {string} [error] - Error message if failed
 * @property {number} lastUpdatedAt - Timestamp of last update
 * @property {string} [brandId] - Optional brand ID
 * @property {string} [batchReference] - Optional batch reference for grouping
 */

/**
 * Serializable upload item for localStorage (File objects cannot be serialized)
 * @typedef {Object} SerializableUploadItem
 * @property {string} clientReference
 * @property {string} [uploadSessionId]
 * @property {string} fileName
 * @property {number} fileSize
 * @property {string} mimeType
 * @property {('direct'|'chunked')} uploadType
 * @property {number} [chunkSize]
 * @property {string} [multipartUploadId]
 * @property {('pending'|'initiating'|'uploading'|'paused'|'completed'|'failed'|'cancelled')} status
 * @property {number} progress
 * @property {string} [error]
 * @property {number} lastUpdatedAt
 */

class UploadManager {
    constructor() {
        /** @type {Map<string, UploadItem>} */
        this.uploads = new Map()
        /** @type {Set<string>} */
        this.activeUploads = new Set()
        /** @type {Map<string, AbortController>} */
        this.abortControllers = new Map()
        /** @type {Map<string, NodeJS.Timeout>} */
        this.activityTimers = new Map()
        this.listeners = new Set()
        this.maxParallelUploads = MAX_PARALLEL_UPLOADS
        
        // Load persisted state from localStorage
        this.rehydrateFromStorage()
    }

    /**
     * Generate a unique client reference UUID (v4 format)
     * @returns {string} Valid UUID v4 string
     */
    generateClientReference() {
        // Use crypto.randomUUID() if available (modern browsers)
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID()
        }
        
        // Fallback: Generate UUID v4 manually
        // Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0
            const v = c === 'x' ? r : (r & 0x3 | 0x8)
            return v.toString(16)
        })
    }

    /**
     * Classify upload error into structured format
     * Phase 2.5: Dev-only error classification for better diagnostics
     * @param {Error|Response|any} error - The error to classify
     * @param {number} [httpStatus] - HTTP status code if available
     * @param {string} [presignedUrl] - Presigned URL (for expiration detection)
     * @returns {{type: string, http_status?: number, message: string, raw_error?: any}}
     */
    classifyError(error, httpStatus = null, presignedUrl = null) {
        // CORS errors: fetch throws, no response, network error
        if (error instanceof TypeError && error.message.includes('fetch')) {
            return {
                type: 'cors',
                message: 'Upload blocked by browser security (dev config issue)',
                raw_error: error
            }
        }

        // Network errors: AbortError, connection refused, etc.
        if (error.name === 'AbortError' || error.name === 'NetworkError') {
            return {
                type: 'network',
                message: 'Network connection failed',
                raw_error: error
            }
        }

        // If we have HTTP status, classify based on status
        if (httpStatus !== null) {
            // 403 can be auth error or expired URL
            if (httpStatus === 403) {
                // Check if response body contains XML (S3 error response)
                if (error instanceof Response || (error.body && typeof error.body === 'string' && error.body.includes('<?xml'))) {
                    // Try to determine if it's expired or auth error
                    // Expired URLs often have specific error codes in S3 XML responses
                    const bodyText = error instanceof Response ? '' : String(error.body || '')
                    if (bodyText.includes('ExpiredToken') || bodyText.includes('RequestTimeTooSkewed') || 
                        (presignedUrl && Date.now() > new Date(presignedUrl.split('X-Amz-Expires=')[1]?.split('&')[0] * 1000 + Date.parse(new URL(presignedUrl).searchParams.get('X-Amz-Date') || '')))) {
                        return {
                            type: 'expired',
                            http_status: 403,
                            message: 'Upload link expired, retrying…',
                            raw_error: error
                        }
                    }
                    return {
                        type: 'auth',
                        http_status: 403,
                        message: 'Upload permission denied',
                        raw_error: error
                    }
                }
                // Generic 403 - likely expired if presigned URL
                if (presignedUrl) {
                    return {
                        type: 'expired',
                        http_status: 403,
                        message: 'Upload link expired, retrying…',
                        raw_error: error
                    }
                }
                return {
                    type: 'auth',
                    http_status: 403,
                    message: 'Upload permission denied',
                    raw_error: error
                }
            }

            // 4xx client errors
            if (httpStatus >= 400 && httpStatus < 500) {
                return {
                    type: 'auth',
                    http_status: httpStatus,
                    message: `Upload failed: ${error.message || `HTTP ${httpStatus}`}`,
                    raw_error: error
                }
            }

            // 5xx server errors
            if (httpStatus >= 500) {
                return {
                    type: 'network',
                    http_status: httpStatus,
                    message: 'Upload server error, please retry',
                    raw_error: error
                }
            }
        }

        // Fallback to unknown
        return {
            type: 'unknown',
            http_status: httpStatus || undefined,
            message: error.message || 'Unknown upload error',
            raw_error: error
        }
    }

    /**
     * Persist upload state to localStorage (without File objects)
     */
    persistToStorage() {
        try {
            const serializable = Array.from(this.uploads.values()).map(upload => {
                const { file, ...rest } = upload
                return rest
            })
            localStorage.setItem(STORAGE_KEY, JSON.stringify(serializable))
        } catch (error) {
            console.warn('Failed to persist upload state to localStorage:', error)
        }
    }

    /**
     * Load upload state from localStorage
     * Note: File objects must be re-attached by the caller
     */
    rehydrateFromStorage() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY)
            if (!stored) return

            const items = JSON.parse(stored)
            items.forEach(item => {
                // Restore without File object (must be re-attached)
                this.uploads.set(item.clientReference, {
                    ...item,
                    file: null, // File must be re-attached
                })
            })
        } catch (error) {
            console.warn('Failed to rehydrate upload state from localStorage:', error)
        }
    }

    /**
     * Reattach File object to persisted upload item
     * @param {string} clientReference
     * @param {File} file
     */
    reattachFile(clientReference, file) {
        const upload = this.uploads.get(clientReference)
        if (upload) {
            upload.file = file
            this.uploads.set(clientReference, upload)
            this.notifyListeners()
        }
    }

    /**
     * Add files to upload queue
     * @param {File[]} files
     * @param {Object} options
     * @param {string} [options.brandId]
     * @param {string} [options.batchReference]
     * @returns {string[]} Array of client references
     */
    addFiles(files, options = {}) {
        const clientReferences = []

        files.forEach(file => {
            const clientReference = this.generateClientReference()
            
            const upload = {
                clientReference,
                uploadSessionId: null,
                file,
                fileName: file.name,
                fileSize: file.size,
                mimeType: file.type || 'application/octet-stream',
                uploadType: file.size > DEFAULT_CHUNK_SIZE ? 'chunked' : 'direct',
                chunkSize: file.size > DEFAULT_CHUNK_SIZE ? DEFAULT_CHUNK_SIZE : undefined,
                multipartUploadId: null,
                status: 'pending',
                progress: 0,
                error: null,
                errorInfo: null,
                diagnostics: null,
                lastUpdatedAt: Date.now(),
                brandId: options.brandId,
                batchReference: options.batchReference,
            }

            this.uploads.set(clientReference, upload)
            clientReferences.push(clientReference)
        })

        this.persistToStorage()
        this.notifyListeners()

        return clientReferences
    }

    /**
     * Start upload for a single file
     * @param {string} clientReference
     */
    async startUpload(clientReference) {
        const upload = this.uploads.get(clientReference)
        if (!upload) {
            throw new Error(`Upload not found: ${clientReference}`)
        }

        if (!upload.file) {
            throw new Error(`File object not attached for upload: ${clientReference}`)
        }

        if (upload.status === 'completed') {
            return // Already completed
        }

        if (upload.status === 'uploading' || upload.status === 'initiating') {
            return // Already in progress
        }

        // Check parallel upload limit
        if (this.activeUploads.size >= this.maxParallelUploads) {
            upload.status = 'paused'
            upload.lastUpdatedAt = Date.now()
            this.persistToStorage()
            this.notifyListeners()
            return
        }

        // If we have an uploadSessionId, try to resume
        if (upload.uploadSessionId) {
            return this.resumeUpload(clientReference)
        }

        // Start new upload
        await this.initiateUpload(clientReference)
    }

    /**
     * Resume an upload from saved state
     * @param {string} clientReference
     */
    async resumeUpload(clientReference) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId) {
            throw new Error(`Cannot resume upload: ${clientReference} (no uploadSessionId)`)
        }

        if (!upload.file) {
            throw new Error(`Cannot resume upload: ${clientReference} (file object not attached)`)
        }

        // Always query backend for resume metadata (never guess state)
        try {
            const resumeData = await this.fetchResumeMetadata(upload.uploadSessionId)

            if (!resumeData.can_resume) {
                upload.status = 'failed'
                upload.error = resumeData.error || 'Cannot resume upload'
                upload.lastUpdatedAt = Date.now()
                this.persistToStorage()
                this.notifyListeners()
                return
            }

            // Reconcile frontend state with backend truth
            upload.status = resumeData.upload_session_status === 'initiating' ? 'initiating' : 'uploading'
            upload.uploadType = resumeData.upload_type
            upload.chunkSize = resumeData.chunk_size || upload.chunkSize
            upload.multipartUploadId = resumeData.multipart_upload_id || upload.multipartUploadId

            // If expired, mark as failed
            if (resumeData.is_expired) {
                upload.status = 'failed'
                upload.error = 'Upload session has expired'
                upload.lastUpdatedAt = Date.now()
                this.persistToStorage()
                this.notifyListeners()
                return
            }

            // Continue with upload based on type
            if (upload.uploadType === 'direct') {
                // Direct uploads: Pre-signed URL may have expired
                // For resume, we'd need a new URL, but backend doesn't provide it via resume endpoint
                // So if resume says we can resume, we'll try to complete (upload may have finished)
                // If not completed, we'd need to re-initiate
                const resumeData = await this.fetchResumeMetadata(upload.uploadSessionId)
                
                if (resumeData.upload_session_status === 'completed') {
                    // Upload was completed - just complete on backend
                    await this.completeUpload(clientReference)
                } else {
                    // For interrupted direct upload, we need to re-initiate to get a new URL
                    // This is a limitation: direct upload URLs expire
                    upload.status = 'failed'
                    upload.error = 'Direct upload was interrupted. Please retry to get a new upload URL.'
                    upload.lastUpdatedAt = Date.now()
                    this.persistToStorage()
                    this.notifyListeners()
                }
            } else {
                // Multipart upload requires backend endpoint for part URLs
                // For now, fall back to direct upload or mark as unsupported
                await this.performMultipartUpload(clientReference, resumeData.already_uploaded_parts || [])
            }
        } catch (error) {
            upload.status = 'failed'
            upload.error = error.message || 'Failed to resume upload'
            upload.lastUpdatedAt = Date.now()
            this.persistToStorage()
            this.notifyListeners()
        }
    }

    /**
     * Initiate upload session with backend
     * @param {string} clientReference
     */
    async initiateUpload(clientReference) {
        const upload = this.uploads.get(clientReference)
        if (!upload) return

        upload.status = 'initiating'
        upload.lastUpdatedAt = Date.now()
        this.persistToStorage()
        this.notifyListeners()

        try {
            // Use batch endpoint for better efficiency (even for single files)
            const response = await window.axios.post('/app/uploads/initiate-batch', {
                files: [{
                    file_name: upload.fileName,
                    file_size: upload.fileSize,
                    mime_type: upload.mimeType,
                    client_reference: upload.clientReference,
                }],
                brand_id: upload.brandId,
                batch_reference: upload.batchReference,
            })

            const result = response.data.uploads[0]
            
            if (result.error) {
                throw new Error(result.error)
            }

            // Update upload with backend response
            upload.uploadSessionId = result.upload_session_id
            upload.uploadType = result.upload_type
            upload.chunkSize = result.chunk_size || upload.chunkSize
            upload.multipartUploadId = result.multipart_upload_id || null
            upload.status = result.upload_session_status === 'initiating' ? 'initiating' : 'uploading'
            upload.uploadUrl = result.upload_url || null // Store pre-signed URL for direct uploads
            
            // Phase 2.5: Store diagnostics
            upload.diagnostics = upload.diagnostics || {}
            upload.diagnostics.upload_session_id = result.upload_session_id
            upload.diagnostics.s3_key = `temp/uploads/${result.upload_session_id}/original`
            if (result.multipart_upload_id) {
                upload.diagnostics.multipart_upload_id = result.multipart_upload_id
            }
            if (result.upload_url) {
                try {
                    const url = new URL(result.upload_url)
                    upload.diagnostics.s3_bucket = url.hostname.split('.')[0]
                    // Extract expiration from presigned URL if present
                    const expiresParam = url.searchParams.get('X-Amz-Expires')
                    const dateParam = url.searchParams.get('X-Amz-Date')
                    if (expiresParam && dateParam) {
                        const expiresSeconds = parseInt(expiresParam, 10)
                        const dateMs = Date.parse(dateParam.substring(0, 8) + 'T' + dateParam.substring(9))
                        upload.diagnostics.presigned_url_expires_at = new Date(dateMs + expiresSeconds * 1000).toISOString()
                    }
                } catch (e) {
                    // Ignore URL parsing errors
                }
            }
            
            upload.lastUpdatedAt = Date.now()

            this.persistToStorage()
            this.notifyListeners()

            // Mark as uploading if we have the session ID
            if (upload.uploadSessionId && upload.status === 'initiating') {
                await window.axios.put(`/app/uploads/${upload.uploadSessionId}/start`)
                upload.status = 'uploading'
            }

            // Start actual file upload
            this.activeUploads.add(clientReference)
            this.startActivityUpdates(clientReference)

            if (upload.uploadType === 'direct') {
                await this.performDirectUpload(clientReference, upload.uploadUrl)
            } else {
                await this.performMultipartUpload(clientReference, [])
            }
        } catch (error) {
            upload.status = 'failed'
            upload.error = error.response?.data?.message || error.message || 'Failed to initiate upload'
            upload.lastUpdatedAt = Date.now()
            this.activeUploads.delete(clientReference)
            this.stopActivityUpdates(clientReference)
            this.persistToStorage()
            this.notifyListeners()
        }
    }

    /**
     * Perform direct PUT upload to S3
     * @param {string} clientReference
     * @param {string} [uploadUrl] - Pre-signed URL from initiate response
     */
    async performDirectUpload(clientReference, uploadUrl = null) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId) return

        const abortController = new AbortController()
        this.abortControllers.set(clientReference, abortController)

        try {
            // Use provided URL or fetch from resume endpoint
            let presignedUrl = uploadUrl || upload.uploadUrl
            
            if (!presignedUrl) {
                // Fallback: fetch resume metadata (but this shouldn't have upload_url for direct)
                // Direct uploads should always have upload_url from initiate
                throw new Error('No upload URL available for direct upload')
            }

            // Track upload progress
            const xhr = new XMLHttpRequest()
            
            return new Promise((resolve, reject) => {
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        upload.progress = Math.round((e.loaded / e.total) * 100)
                        upload.lastUpdatedAt = Date.now()
                        this.persistToStorage()
                        this.notifyListeners()
                    }
                })

                xhr.addEventListener('load', async () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        upload.progress = 100
                        upload.status = 'uploading'
                        upload.lastUpdatedAt = Date.now()
                        // Phase 2.5: Clear errors on success
                        upload.error = null
                        upload.errorInfo = null
                        upload.diagnostics = upload.diagnostics || {}
                        upload.diagnostics.last_http_status = xhr.status
                        this.persistToStorage()
                        this.notifyListeners()

                        try {
                            await this.completeUpload(clientReference)
                            resolve()
                        } catch (error) {
                            reject(error)
                        }
                    } else {
                        // Phase 2.5: Classify error
                        const errorObj = new Error(`Upload failed: ${xhr.statusText}`)
                        errorObj.status = xhr.status
                        const errorInfo = this.classifyError(errorObj, xhr.status, presignedUrl)
                        upload.errorInfo = errorInfo
                        upload.diagnostics = upload.diagnostics || {}
                        upload.diagnostics.last_http_status = xhr.status
                        upload.diagnostics.last_error_type = errorInfo.type
                        upload.diagnostics.last_error_message = errorInfo.message
                        reject(errorObj)
                    }
                })

                xhr.addEventListener('error', () => {
                    // Phase 2.5: Classify network error
                    const errorObj = new Error('Upload failed')
                    const errorInfo = this.classifyError(errorObj, null, presignedUrl)
                    upload.errorInfo = errorInfo
                    upload.diagnostics = upload.diagnostics || {}
                    upload.diagnostics.last_error_type = errorInfo.type
                    upload.diagnostics.last_error_message = errorInfo.message
                    reject(errorObj)
                })

                xhr.addEventListener('abort', () => {
                    upload.status = 'cancelled'
                    upload.error = null
                    upload.lastUpdatedAt = Date.now()
                    this.activeUploads.delete(clientReference)
                    this.stopActivityUpdates(clientReference)
                    this.persistToStorage()
                    this.notifyListeners()
                    reject(new Error('Upload cancelled'))
                })

                xhr.open('PUT', presignedUrl)
                xhr.setRequestHeader('Content-Type', upload.mimeType)
                xhr.send(upload.file)

                // Store abort function
                abortController.signal.addEventListener('abort', () => {
                    xhr.abort()
                })
            })
        } catch (error) {
            if (error.name === 'AbortError' || error.message === 'Upload cancelled') {
                upload.status = 'cancelled'
                upload.error = null
                upload.errorInfo = null
            } else {
                upload.status = 'failed'
                // Phase 2.5: Classify and store structured error
                const errorInfo = this.classifyError(error, error.status, uploadUrl || upload.uploadUrl)
                upload.error = errorInfo.message
                upload.errorInfo = errorInfo
                upload.diagnostics = upload.diagnostics || {}
                upload.diagnostics.last_error_type = errorInfo.type
                upload.diagnostics.last_error_message = errorInfo.message
                if (errorInfo.http_status) {
                    upload.diagnostics.last_http_status = errorInfo.http_status
                }
            }
            upload.lastUpdatedAt = Date.now()
            this.activeUploads.delete(clientReference)
            this.stopActivityUpdates(clientReference)
            this.persistToStorage()
            this.notifyListeners()
            throw error
        } finally {
            this.abortControllers.delete(clientReference)
        }
    }


    /**
     * Perform multipart chunked upload to S3
     * @param {string} clientReference
     * @param {Array} alreadyUploadedParts - Parts already uploaded (for resume)
     */
    async performMultipartUpload(clientReference, alreadyUploadedParts = []) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId || !upload.multipartUploadId) {
            throw new Error('Missing required upload data for multipart upload')
        }

        const abortController = new AbortController()
        this.abortControllers.set(clientReference, abortController)

        try {
            const file = upload.file
            const chunkSize = upload.chunkSize || DEFAULT_CHUNK_SIZE
            const totalChunks = Math.ceil(file.size / chunkSize)
            
            // Determine which parts need to be uploaded
            const uploadedPartNumbers = new Set(alreadyUploadedParts.map(p => p.PartNumber))
            const partsToUpload = []

            for (let partNumber = 1; partNumber <= totalChunks; partNumber++) {
                if (!uploadedPartNumbers.has(partNumber)) {
                    partsToUpload.push(partNumber)
                }
            }

            if (partsToUpload.length === 0 && alreadyUploadedParts.length > 0) {
                // All parts uploaded, complete the multipart upload
                await this.completeMultipartUpload(clientReference, alreadyUploadedParts)
                return
            }

            // Upload missing parts
            const uploadedParts = [...alreadyUploadedParts]

            for (const partNumber of partsToUpload) {
                if (abortController.signal.aborted) {
                    throw new Error('Upload cancelled')
                }

                const start = (partNumber - 1) * chunkSize
                const end = Math.min(start + chunkSize, file.size)
                const chunk = file.slice(start, end)

                // Get pre-signed URL for this part
                const partUrl = await this.getMultipartUploadUrl(
                    upload.uploadSessionId,
                    partNumber
                )

                // Phase 2.5: Store diagnostics
                upload.diagnostics = upload.diagnostics || {}
                upload.diagnostics.part_number = partNumber
                upload.diagnostics.last_presigned_url = partUrl

                // Upload chunk to S3
                let partResponse
                try {
                    partResponse = await fetch(partUrl, {
                        method: 'PUT',
                        body: chunk,
                        signal: abortController.signal,
                    })
                } catch (fetchError) {
                    // Phase 2.5: Classify fetch errors (CORS, network)
                    const errorInfo = this.classifyError(fetchError, null, partUrl)
                    upload.errorInfo = errorInfo
                    upload.diagnostics.last_error_type = errorInfo.type
                    upload.diagnostics.last_error_message = errorInfo.message
                    throw new Error(errorInfo.message)
                }

                // Phase 2.5: Store HTTP status
                upload.diagnostics.last_http_status = partResponse.status

                if (!partResponse.ok) {
                    // Phase 2.5: Classify HTTP error
                    const errorBody = await partResponse.text().catch(() => '')
                    const errorObj = new Error(`Failed to upload part ${partNumber}: ${partResponse.statusText}`)
                    errorObj.body = errorBody
                    errorObj.status = partResponse.status
                    const errorInfo = this.classifyError(errorObj, partResponse.status, partUrl)
                    upload.errorInfo = errorInfo
                    upload.diagnostics.last_error_type = errorInfo.type
                    upload.diagnostics.last_error_message = errorInfo.message
                    throw errorObj
                }

                const etag = partResponse.headers.get('ETag')?.replace(/"/g, '')

                if (!etag) {
                    throw new Error(`No ETag received for part ${partNumber}`)
                }

                uploadedParts.push({
                    PartNumber: partNumber,
                    ETag: etag,
                    Size: chunk.size,
                })

                // Update progress
                const uploadedSize = uploadedParts.reduce((sum, p) => sum + p.Size, 0)
                upload.progress = Math.round((uploadedSize / file.size) * 100)
                upload.lastUpdatedAt = Date.now()
                this.persistToStorage()
                this.notifyListeners()
            }

            // Complete multipart upload
            await this.completeMultipartUpload(clientReference, uploadedParts)
        } catch (error) {
            if (error.name === 'AbortError') {
                upload.status = 'cancelled'
                upload.error = null
                upload.errorInfo = null
            } else {
                upload.status = 'failed'
                // Phase 2.5: Classify and store structured error
                const errorInfo = upload.errorInfo || this.classifyError(error, error.status)
                upload.error = errorInfo.message || error.message || 'Multipart upload failed'
                upload.errorInfo = errorInfo
                upload.diagnostics = upload.diagnostics || {}
                if (!upload.diagnostics.last_error_type) {
                    upload.diagnostics.last_error_type = errorInfo.type
                    upload.diagnostics.last_error_message = errorInfo.message
                    if (errorInfo.http_status) {
                        upload.diagnostics.last_http_status = errorInfo.http_status
                    }
                }
            }
            upload.lastUpdatedAt = Date.now()
            this.activeUploads.delete(clientReference)
            this.stopActivityUpdates(clientReference)
            this.persistToStorage()
            this.notifyListeners()
        } finally {
            this.abortControllers.delete(clientReference)
        }
    }

    /**
     * Complete multipart upload by assembling parts
     * @param {string} clientReference
     * @param {Array} parts
     */
    async completeMultipartUpload(clientReference, parts) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId || !upload.multipartUploadId) {
            throw new Error('Missing required data to complete multipart upload')
        }

        // Complete multipart upload with backend
        // Note: Backend handles S3 multipart completion
        // For now, we just mark progress as complete and call /complete
        upload.progress = 100
        upload.lastUpdatedAt = Date.now()
        this.persistToStorage()
        this.notifyListeners()

        await this.completeUpload(clientReference)
    }

    /**
     * Complete upload session with backend
     * @param {string} clientReference
     */
    async completeUpload(clientReference) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId) {
            throw new Error('Cannot complete upload: missing uploadSessionId')
        }

        try {
            const response = await window.axios.post('/app/assets/upload/complete', {
                upload_session_id: upload.uploadSessionId,
            })

            upload.status = 'completed'
            upload.progress = 100
            upload.error = null
            upload.lastUpdatedAt = Date.now()
            this.activeUploads.delete(clientReference)
            this.stopActivityUpdates(clientReference)
            this.persistToStorage()
            this.notifyListeners()

            // Remove from active uploads after a delay (allow UI to show completion)
            setTimeout(() => {
                this.removeUpload(clientReference)
            }, 5000)
        } catch (error) {
            upload.status = 'failed'
            upload.error = error.response?.data?.message || error.message || 'Failed to complete upload'
            upload.lastUpdatedAt = Date.now()
            this.activeUploads.delete(clientReference)
            this.stopActivityUpdates(clientReference)
            this.persistToStorage()
            this.notifyListeners()
        }
    }

    /**
     * Cancel an upload
     * @param {string} clientReference
     */
    async cancelUpload(clientReference) {
        const upload = this.uploads.get(clientReference)
        if (!upload) return

        // Abort any ongoing fetch requests
        const abortController = this.abortControllers.get(clientReference)
        if (abortController) {
            abortController.abort()
            this.abortControllers.delete(clientReference)
        }

        // Stop activity updates
        this.stopActivityUpdates(clientReference)

        // Cancel with backend (idempotent)
        if (upload.uploadSessionId) {
            try {
                await window.axios.post(`/app/uploads/${upload.uploadSessionId}/cancel`)
            } catch (error) {
                // Ignore errors - cancellation is best-effort
                console.warn('Failed to cancel upload with backend:', error)
            }
        }

        upload.status = 'cancelled'
        upload.error = null
        upload.lastUpdatedAt = Date.now()
        this.activeUploads.delete(clientReference)
        this.persistToStorage()
        this.notifyListeners()
    }

    /**
     * Retry a failed upload
     * @param {string} clientReference
     */
    async retryUpload(clientReference) {
        const upload = this.uploads.get(clientReference)
        if (!upload) return

        if (upload.status !== 'failed') {
            return // Can only retry failed uploads
        }

        // Reset error and status
        upload.status = 'pending'
        upload.error = null
        upload.progress = 0
        upload.lastUpdatedAt = Date.now()
        this.persistToStorage()
        this.notifyListeners()

        // Start upload again
        await this.startUpload(clientReference)
    }

    /**
     * Remove upload from manager
     * @param {string} clientReference
     */
    removeUpload(clientReference) {
        this.uploads.delete(clientReference)
        this.activeUploads.delete(clientReference)
        this.abortControllers.delete(clientReference)
        this.stopActivityUpdates(clientReference)
        this.persistToStorage()
        this.notifyListeners()
    }

    /**
     * Fetch resume metadata from backend
     * @param {string} uploadSessionId
     * @returns {Promise<Object>}
     */
    async fetchResumeMetadata(uploadSessionId) {
        const response = await window.axios.get(`/app/uploads/${uploadSessionId}/resume`)
        return response.data
    }

    /**
     * Get pre-signed URL for multipart upload part
     * @param {string} uploadSessionId
     * @param {number} partNumber
     * @returns {Promise<string>}
     */
    async getMultipartUploadUrl(uploadSessionId, partNumber) {
        const response = await window.axios.post(
            `/app/uploads/${uploadSessionId}/multipart-part-url`,
            {
                part_number: partNumber,
            }
        )

        return response.data.upload_url
    }


    /**
     * Start periodic activity updates for upload
     * @param {string} clientReference
     */
    startActivityUpdates(clientReference) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId) return

        // Clear existing timer if any
        this.stopActivityUpdates(clientReference)

        const timer = setInterval(async () => {
            if (upload.status !== 'uploading' && upload.status !== 'initiating') {
                this.stopActivityUpdates(clientReference)
                return
            }

            try {
                await window.axios.put(`/app/uploads/${upload.uploadSessionId}/activity`)
            } catch (error) {
                // Ignore activity update errors - non-critical
                console.warn('Failed to update upload activity:', error)
            }
        }, ACTIVITY_UPDATE_INTERVAL)

        this.activityTimers.set(clientReference, timer)
    }

    /**
     * Stop periodic activity updates
     * @param {string} clientReference
     */
    stopActivityUpdates(clientReference) {
        const timer = this.activityTimers.get(clientReference)
        if (timer) {
            clearInterval(timer)
            this.activityTimers.delete(clientReference)
        }
    }

    /**
     * Get all uploads
     * @returns {UploadItem[]}
     */
    getUploads() {
        return Array.from(this.uploads.values())
    }

    /**
     * Get upload by client reference
     * @param {string} clientReference
     * @returns {UploadItem|null}
     */
    getUpload(clientReference) {
        return this.uploads.get(clientReference) || null
    }

    /**
     * Subscribe to upload state changes
     * @param {Function} listener
     * @returns {Function} Unsubscribe function
     */
    subscribe(listener) {
        this.listeners.add(listener)
        return () => {
            this.listeners.delete(listener)
        }
    }

    /**
     * Notify all listeners of state changes
     */
    notifyListeners() {
        this.listeners.forEach(listener => {
            try {
                listener(this.getUploads())
            } catch (error) {
                console.error('Error in upload listener:', error)
            }
        })
    }
}

// Export singleton instance
export default new UploadManager()
