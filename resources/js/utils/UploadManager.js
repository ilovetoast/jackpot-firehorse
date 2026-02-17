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
 * 
 * Phase 2.5: Error classification and diagnostics reporting
 */

// Phase 2.5: Import error classifier
import { classifyUploadError, sendDiagnostics } from './uploadErrorClassifier'

const STORAGE_KEY = 'upload_manager_state'
const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024 // 5 MB (legacy, for backward compatibility)
const MULTIPART_CHUNK_SIZE = 10 * 1024 * 1024 // 10 MB (matches backend MultipartUploadService::DEFAULT_CHUNK_SIZE)
const MULTIPART_THRESHOLD = 100 * 1024 * 1024 // 100 MB (matches backend UploadInitiationService::MULTIPART_THRESHOLD)
const MAX_PARALLEL_UPLOADS = 5 // Configurable limit
const MAX_PARALLEL_PARTS = 3 // Max concurrent part uploads for multipart (conservative, can be increased later)
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
 * @property {('pending'|'initiating'|'uploading'|'completing'|'completed'|'failed'|'cancelled')} status - Upload status
 * @property {number} progress - Upload progress (0-100)
 * @property {string} [error] - Error message if failed
 * @property {number} lastUpdatedAt - Timestamp of last update
 * @property {string} [brandId] - Optional brand ID
 * @property {string} [batchReference] - Optional batch reference for grouping
 * @property {number} [partSize] - Part size for multipart uploads (Phase 2.6)
 * @property {number} [totalParts] - Total number of parts for multipart uploads (Phase 2.6)
 * @property {Object<string, string>} [completedParts] - Map of part_number => etag for completed parts (Phase 2.6)
 * @property {Object<number, number>} [partProgress] - Map of part_number => progress (0-100) for per-part tracking (Phase 2.6)
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
            
            // Phase 2.6: Determine upload type based on file size threshold
            const isMultipart = file.size > MULTIPART_THRESHOLD
            
            const upload = {
                clientReference,
                uploadSessionId: null,
                file,
                fileName: file.name,
                fileSize: file.size,
                mimeType: file.type || 'application/octet-stream',
                uploadType: isMultipart ? 'chunked' : 'direct',
                chunkSize: isMultipart ? MULTIPART_CHUNK_SIZE : undefined,
                multipartUploadId: null,
                // Phase 2.6: Multipart metadata (will be set by backend during /multipart/init)
                partSize: undefined, // Set by backend during multipart init
                totalParts: undefined, // Set by backend during multipart init
                completedParts: {}, // Map of part_number => etag (empty initially, populated during upload/resume)
                partProgress: {}, // Map of part_number => progress (0-100) for per-part tracking
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

        // OPTIMIZATION 3: Don't persist during file add - large files cause blocking serialization
        // Persistence will happen later (on start/complete/fail) when needed
        // Large files inside a modal don't need crash-resume during add
        // Only notify listeners (async state update) - don't block on persistence
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
            
            // Phase 2.6: Restore multipart metadata from resume data
            if (resumeData.part_size) {
                upload.partSize = resumeData.part_size
            }
            if (resumeData.total_parts) {
                upload.totalParts = resumeData.total_parts
            }
            // Restore completed parts from multipart_state if available
            if (resumeData.multipart_state?.completed_parts) {
                upload.completedParts = {}
                Object.entries(resumeData.multipart_state.completed_parts).forEach(([partNum, etag]) => {
                    upload.completedParts[parseInt(partNum)] = etag
                })
            }

            // If expired, mark as failed
            if (resumeData.is_expired) {
                upload.status = 'failed'
                upload.error = 'Upload session has expired'
                upload.lastUpdatedAt = Date.now()
                this.persistToStorage()
                this.notifyListeners()
                return
            }

            // Phase 2.6: Continue with upload based on type
            if (upload.uploadType === 'direct') {
                // Direct uploads: Pre-signed URL may have expired
                // For resume, we'd need a new URL, but backend doesn't provide it via resume endpoint
                // So if resume says we can resume, we'll try to complete (upload may have finished)
                // If not completed, we'd need to re-initiate
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
                // Phase 2.6: Multipart upload - restore multipart metadata from resume data
                if (resumeData.multipart_upload_id) {
                    upload.multipartUploadId = resumeData.multipart_upload_id
                }
                
                // Restore part metadata if available
                if (resumeData.part_size) {
                    upload.partSize = resumeData.part_size
                }
                if (resumeData.total_parts) {
                    upload.totalParts = resumeData.total_parts
                }
                
                // Phase 2.6: Resume multipart upload with completed parts from backend
                // Backend provides multipart_state.completed_parts in resume metadata
                await this.performMultipartUpload(clientReference, [])
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
            
            // Phase 2.6: For multipart uploads, initialize multipart metadata
            if (upload.uploadType === 'chunked') {
                // Initialize multipart tracking if not already set
                upload.partSize = upload.partSize || MULTIPART_CHUNK_SIZE
                upload.totalParts = upload.totalParts || Math.ceil(upload.fileSize / upload.partSize)
                upload.completedParts = upload.completedParts || {}
                upload.partProgress = upload.partProgress || {}
            }
            
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
            // Storage limit exceeded: store full payload for inline upgrade modal
            const responseData = error.response?.data
            if (responseData?.type === 'storage_limit_exceeded') {
                upload.status = 'failed'
                upload.error = 'Storage limit exceeded. Add more storage to continue.'
                upload.errorInfo = {
                    type: 'storage_limit_exceeded',
                    ...responseData,
                }
                upload.storageLimitExceeded = {
                    current_usage_mb: responseData.current_usage_mb,
                    max_storage_mb: responseData.max_storage_mb,
                    addon_packages: responseData.addon_packages || [],
                }
                upload.lastUpdatedAt = Date.now()
                this.activeUploads.delete(clientReference)
                this.stopActivityUpdates(clientReference)
                this.persistToStorage()
                this.notifyListeners()
                return
            }

            // Phase 2.5: Classify error and send diagnostics
            const httpStatus = error.response?.status || null
            const classifiedError = classifyUploadError(error, {
                httpStatus,
                requestPhase: 'initiate',
                uploadSessionId: upload.uploadSessionId,
                fileName: upload.fileName,
                fileSize: upload.fileSize,
                presignedUrl: upload.uploadUrl,
            })
            
            upload.status = 'failed'
            upload.error = classifiedError.message
            upload.errorInfo = classifiedError
            upload.lastUpdatedAt = Date.now()
            
            // Update diagnostics
            upload.diagnostics = upload.diagnostics || {}
            upload.diagnostics.last_error_type = classifiedError.type
            upload.diagnostics.last_error_message = classifiedError.message
            upload.diagnostics.last_http_status = classifiedError.http_status
            upload.diagnostics.request_phase = 'initiate'
            upload.diagnostics.timestamp = classifiedError.timestamp
            
            // Send diagnostics to backend (best-effort, never throws)
            sendDiagnostics(classifiedError).catch(() => {})
            
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
                        // Phase 2.5: Classify error and send diagnostics
                        const errorResponse = {
                            message: `Upload failed with status ${xhr.status}`,
                            response: { status: xhr.status },
                        }
                        const classifiedError = classifyUploadError(errorResponse, {
                            httpStatus: xhr.status,
                            requestPhase: 'upload',
                            uploadSessionId: upload.uploadSessionId,
                            fileName: upload.fileName,
                            fileSize: upload.fileSize,
                            presignedUrl: presignedUrl,
                        })
                        
                        upload.status = 'failed'
                        upload.error = classifiedError.message
                        upload.errorInfo = classifiedError
                        upload.diagnostics = upload.diagnostics || {}
                        upload.diagnostics.last_error_type = classifiedError.type
                        upload.diagnostics.last_error_message = classifiedError.message
                        upload.diagnostics.last_http_status = classifiedError.http_status
                        upload.diagnostics.request_phase = 'upload'
                        upload.diagnostics.timestamp = classifiedError.timestamp
                        
                        sendDiagnostics(classifiedError).catch(() => {})
                        
                        reject(new Error(classifiedError.message))
                    }
                })

                xhr.addEventListener('error', () => {
                    // Phase 2.5: Classify error and send diagnostics
                    const networkError = new Error('Network error during upload')
                    const classifiedError = classifyUploadError(networkError, {
                        requestPhase: 'upload',
                        uploadSessionId: upload.uploadSessionId,
                        fileName: upload.fileName,
                        fileSize: upload.fileSize,
                        presignedUrl: presignedUrl,
                    })
                    
                    upload.status = 'failed'
                    upload.error = classifiedError.message
                    upload.errorInfo = classifiedError
                    upload.diagnostics = upload.diagnostics || {}
                    upload.diagnostics.last_error_type = classifiedError.type
                    upload.diagnostics.last_error_message = classifiedError.message
                    upload.diagnostics.request_phase = 'upload'
                    upload.diagnostics.timestamp = classifiedError.timestamp
                    
                    sendDiagnostics(classifiedError).catch(() => {})
                    
                    reject(networkError)
                })

                xhr.addEventListener('abort', () => {
                    upload.status = 'cancelled'
                    upload.lastUpdatedAt = Date.now()
                    this.persistToStorage()
                    this.notifyListeners()
                    reject(new Error('Upload cancelled'))
                })

                xhr.open('PUT', presignedUrl)
                xhr.setRequestHeader('Content-Type', upload.mimeType)
                xhr.send(upload.file)
            })
        } catch (error) {
            // Phase 2.5: Classify error and send diagnostics
            const classifiedError = classifyUploadError(error, {
                requestPhase: 'upload',
                uploadSessionId: upload.uploadSessionId,
                fileName: upload.fileName,
                fileSize: upload.fileSize,
                presignedUrl: upload.uploadUrl,
            })
            
            upload.status = 'failed'
            upload.error = classifiedError.message
            upload.errorInfo = classifiedError
            upload.diagnostics = upload.diagnostics || {}
            upload.diagnostics.last_error_type = classifiedError.type
            upload.diagnostics.last_error_message = classifiedError.message
            upload.diagnostics.request_phase = 'upload'
            upload.diagnostics.timestamp = classifiedError.timestamp
            
            sendDiagnostics(classifiedError).catch(() => {})
            
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
     * Phase 2.6: Perform multipart chunked upload to S3
     * 
     * Flow:
     * 1. Initiate multipart upload (if not already initiated)
     * 2. Resume: Check multipart_state.completed_parts from backend
     * 3. Upload missing parts sequentially (with limited parallelism)
     * 4. Track per-part progress
     * 5. Complete multipart upload when all parts done
     * 
     * @param {string} clientReference
     * @param {Array} alreadyUploadedParts - Parts already uploaded (for resume, legacy format)
     */
    async performMultipartUpload(clientReference, alreadyUploadedParts = []) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId) {
            throw new Error('Missing required upload data for multipart upload')
        }

        const abortController = new AbortController()
        this.abortControllers.set(clientReference, abortController)

        try {
            const file = upload.file
            if (!file) {
                throw new Error('File object not attached for multipart upload')
            }

            // Phase 2.6: Step 1 - Initiate multipart upload if not already initiated
            if (!upload.multipartUploadId) {
                upload.status = 'initiating'
                this.notifyListeners()

                try {
                    const initResponse = await window.axios.post(
                        `/app/uploads/${upload.uploadSessionId}/multipart/init`
                    )

                    upload.multipartUploadId = initResponse.data.multipart_upload_id
                    upload.partSize = initResponse.data.part_size
                    upload.totalParts = initResponse.data.total_parts
                    upload.completedParts = {}
                    upload.partProgress = {}
                    
                    // Phase 2.5: Store diagnostics
                    upload.diagnostics = upload.diagnostics || {}
                    upload.diagnostics.multipart_upload_id = upload.multipartUploadId
                    upload.diagnostics.part_size = upload.partSize
                    upload.diagnostics.total_parts = upload.totalParts

                    this.persistToStorage()
                    this.notifyListeners()
                } catch (error) {
                    // Phase 2.6: Classify init error
                    const httpStatus = error.response?.status || null
                    const classifiedError = classifyUploadError(error, {
                        httpStatus,
                        requestPhase: 'multipart_init',
                        uploadSessionId: upload.uploadSessionId,
                        fileName: upload.fileName,
                        fileSize: upload.fileSize,
                    })
                    
                    upload.status = 'failed'
                    upload.error = classifiedError.message
                    upload.errorInfo = classifiedError
                    upload.diagnostics = upload.diagnostics || {}
                    upload.diagnostics.last_error_type = classifiedError.type
                    upload.diagnostics.last_error_message = classifiedError.message
                    upload.diagnostics.last_http_status = classifiedError.http_status
                    upload.diagnostics.request_phase = 'multipart_init'
                    upload.diagnostics.timestamp = classifiedError.timestamp
                    
                    sendDiagnostics(classifiedError).catch(() => {})
                    throw error
                }
            }

            // Phase 2.6: Step 2 - Resume: Get completed parts from backend multipart_state
            let completedPartsMap = upload.completedParts || {}
            
            // Query backend for resume metadata (includes multipart_state.completed_parts)
            try {
                const resumeData = await this.fetchResumeMetadata(upload.uploadSessionId)
                
                // If backend has multipart_state, use it for resume
                if (resumeData.multipart_state?.completed_parts) {
                    // Convert backend format { "1": "etag1", "2": "etag2" } to our format
                    completedPartsMap = {}
                    Object.entries(resumeData.multipart_state.completed_parts).forEach(([partNum, etag]) => {
                        completedPartsMap[parseInt(partNum)] = etag
                    })
                    upload.completedParts = completedPartsMap
                }
            } catch (error) {
                // Resume query failed - continue with local state (best-effort)
                console.warn('[UploadManager] Failed to fetch resume metadata, using local state:', error)
            }

            // Phase 2.6: Step 3 - Determine which parts need to be uploaded
            const partSize = upload.partSize || MULTIPART_CHUNK_SIZE
            const totalParts = upload.totalParts || Math.ceil(file.size / partSize)
            const partsToUpload = []

            for (let partNumber = 1; partNumber <= totalParts; partNumber++) {
                if (!completedPartsMap[partNumber]) {
                    partsToUpload.push(partNumber)
                } else {
                    // Part already completed - mark progress as 100%
                    upload.partProgress = upload.partProgress || {}
                    upload.partProgress[partNumber] = 100
                }
            }

            // If all parts are already uploaded, go straight to completion
            if (partsToUpload.length === 0 && Object.keys(completedPartsMap).length > 0) {
                upload.status = 'completing'
                this.notifyListeners()
                await this.completeMultipartUpload(clientReference, completedPartsMap)
                return
            }

            // Phase 2.6: Step 4 - Upload missing parts
            upload.status = 'uploading'
            this.notifyListeners()

            // Upload parts sequentially (can be parallelized later if needed)
            for (const partNumber of partsToUpload) {
                if (abortController.signal.aborted) {
                    throw new Error('Upload cancelled')
                }

                // Calculate chunk boundaries
                const start = (partNumber - 1) * partSize
                const end = Math.min(start + partSize, file.size)
                const chunk = file.slice(start, end)

                // Initialize part progress
                upload.partProgress = upload.partProgress || {}
                upload.partProgress[partNumber] = 0

                try {
                    // Phase 2.6: Get presigned URL for this part
                    const signResponse = await window.axios.post(
                        `/app/uploads/${upload.uploadSessionId}/multipart/sign-part`,
                        { part_number: partNumber }
                    )

                    const partUrl = signResponse.data.upload_url

                    // Phase 2.5: Store diagnostics
                    upload.diagnostics = upload.diagnostics || {}
                    upload.diagnostics.part_number = partNumber
                    upload.diagnostics.last_presigned_url = partUrl
                    upload.diagnostics.request_phase = 'multipart_upload_part'

                    // Upload chunk to S3
                    let partResponse
                    try {
                        partResponse = await fetch(partUrl, {
                            method: 'PUT',
                            body: chunk,
                            signal: abortController.signal,
                        })
                    } catch (fetchError) {
                        // Phase 2.6: Classify fetch errors
                        const classifiedError = classifyUploadError(fetchError, {
                            requestPhase: 'multipart_upload_part',
                            uploadSessionId: upload.uploadSessionId,
                            fileName: upload.fileName,
                            fileSize: upload.fileSize,
                            presignedUrl: partUrl,
                        })
                        
                        upload.errorInfo = classifiedError
                        upload.diagnostics.last_error_type = classifiedError.type
                        upload.diagnostics.last_error_message = classifiedError.message
                        upload.diagnostics.last_http_status = classifiedError.http_status
                        upload.diagnostics.timestamp = classifiedError.timestamp
                        
                        sendDiagnostics(classifiedError).catch(() => {})
                        throw new Error(classifiedError.message)
                    }

                    // Phase 2.5: Store HTTP status
                    upload.diagnostics.last_http_status = partResponse.status

                    if (!partResponse.ok) {
                        // Phase 2.6: Classify HTTP error
                        const errorBody = await partResponse.text().catch(() => '')
                        const errorResponse = {
                            message: `Failed to upload part ${partNumber}: ${partResponse.statusText}`,
                            response: { status: partResponse.status },
                            body: errorBody,
                        }
                        
                        const classifiedError = classifyUploadError(errorResponse, {
                            httpStatus: partResponse.status,
                            requestPhase: 'multipart_upload_part',
                            uploadSessionId: upload.uploadSessionId,
                            fileName: upload.fileName,
                            fileSize: upload.fileSize,
                            presignedUrl: partUrl,
                        })
                        
                        upload.errorInfo = classifiedError
                        upload.diagnostics.last_error_type = classifiedError.type
                        upload.diagnostics.last_error_message = classifiedError.message
                        upload.diagnostics.last_http_status = classifiedError.http_status
                        upload.diagnostics.timestamp = classifiedError.timestamp
                        
                        sendDiagnostics(classifiedError).catch(() => {})
                        throw new Error(classifiedError.message)
                    }

                    const etag = partResponse.headers.get('ETag')?.replace(/"/g, '')

                    if (!etag) {
                        throw new Error(`No ETag received for part ${partNumber}`)
                    }

                    // Store completed part
                    completedPartsMap[partNumber] = etag
                    upload.completedParts = completedPartsMap
                    upload.partProgress[partNumber] = 100

                    // Update overall progress
                    const completedCount = Object.keys(completedPartsMap).length
                    upload.progress = Math.round((completedCount / totalParts) * 100)
                    upload.lastUpdatedAt = Date.now()
                    
                    // Throttle persistence (every 10% or on completion)
                    const shouldPersist = upload.progress % 10 === 0 || upload.progress === 100
                    if (shouldPersist) {
                        this.persistToStorage()
                    }
                    this.notifyListeners()
                } catch (error) {
                    // Part upload failed - mark part as failed but continue with other parts
                    upload.partProgress[partNumber] = -1 // -1 indicates failed
                    upload.lastUpdatedAt = Date.now()
                    this.notifyListeners()
                    
                    // Re-throw to abort entire upload (can be changed to continue on part failure if needed)
                    throw error
                }
            }

            // Phase 2.6: Step 5 - Complete multipart upload
            upload.status = 'completing'
            this.notifyListeners()
            await this.completeMultipartUpload(clientReference, completedPartsMap)
        } catch (error) {
            if (error.name === 'AbortError' || error.message === 'Upload cancelled') {
                upload.status = 'cancelled'
                upload.error = null
                upload.errorInfo = null
                
                // Phase 2.6: Abort multipart upload on S3
                if (upload.uploadSessionId && upload.multipartUploadId) {
                    try {
                        await window.axios.post(
                            `/app/uploads/${upload.uploadSessionId}/multipart/abort`
                        )
                    } catch (abortError) {
                        // Ignore abort errors - best-effort cleanup
                        console.warn('[UploadManager] Failed to abort multipart upload:', abortError)
                    }
                }
            } else {
                upload.status = 'failed'
                // Phase 2.6: Classify and store structured error
                const httpStatus = error.response?.status || null
                const classifiedError = upload.errorInfo || classifyUploadError(error, {
                    httpStatus,
                    requestPhase: 'multipart_upload',
                    uploadSessionId: upload.uploadSessionId,
                    fileName: upload.fileName,
                    fileSize: upload.fileSize,
                })
                
                upload.error = classifiedError.message || error.message || 'Multipart upload failed'
                upload.errorInfo = classifiedError
                upload.diagnostics = upload.diagnostics || {}
                if (!upload.diagnostics.last_error_type) {
                    upload.diagnostics.last_error_type = classifiedError.type
                    upload.diagnostics.last_error_message = classifiedError.message
                    upload.diagnostics.last_http_status = classifiedError.http_status
                    upload.diagnostics.request_phase = 'multipart_upload'
                    upload.diagnostics.timestamp = classifiedError.timestamp
                }
                
                sendDiagnostics(classifiedError).catch(() => {})
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
     * Phase 2.6: Complete multipart upload by assembling parts
     * 
     * @param {string} clientReference
     * @param {Object<number, string>} completedPartsMap - Map of part_number => etag
     */
    async completeMultipartUpload(clientReference, completedPartsMap) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId || !upload.multipartUploadId) {
            throw new Error('Missing required data to complete multipart upload')
        }

        try {
            // Phase 2.6: Convert completedPartsMap to array format for backend
            // Backend expects: { "parts": { "1": "etag1", "2": "etag2", ... } }
            const partsPayload = {}
            Object.entries(completedPartsMap).forEach(([partNum, etag]) => {
                partsPayload[partNum] = etag
            })

            // Call backend multipart complete endpoint
            const completeResponse = await window.axios.post(
                `/app/uploads/${upload.uploadSessionId}/multipart/complete`,
                { parts: partsPayload }
            )

            // Phase 2.5: Store diagnostics
            upload.diagnostics = upload.diagnostics || {}
            upload.diagnostics.multipart_completed = true
            upload.diagnostics.multipart_etag = completeResponse.data.etag

            // Mark progress as complete
            upload.progress = 100
            upload.status = 'completed'
            upload.lastUpdatedAt = Date.now()
            this.persistToStorage()
            this.notifyListeners()

            // Phase 2.6: Note: We don't call completeUpload() here because
            // multipart completion already finalizes the S3 upload.
            // The upload session is now ready for finalization (asset creation).
        } catch (error) {
            // Phase 2.6: Classify completion error
            const httpStatus = error.response?.status || null
            const classifiedError = classifyUploadError(error, {
                httpStatus,
                requestPhase: 'multipart_complete',
                uploadSessionId: upload.uploadSessionId,
                fileName: upload.fileName,
                fileSize: upload.fileSize,
            })
            
            upload.status = 'failed'
            upload.error = classifiedError.message
            upload.errorInfo = classifiedError
            upload.diagnostics = upload.diagnostics || {}
            upload.diagnostics.last_error_type = classifiedError.type
            upload.diagnostics.last_error_message = classifiedError.message
            upload.diagnostics.last_http_status = classifiedError.http_status
            upload.diagnostics.request_phase = 'multipart_complete'
            upload.diagnostics.timestamp = classifiedError.timestamp
            
            sendDiagnostics(classifiedError).catch(() => {})
            
            upload.lastUpdatedAt = Date.now()
            this.persistToStorage()
            this.notifyListeners()
            throw error
        }
    }

    /**
     * Complete upload session (Phase 2 local completion only)
     * 
     * NOTE: This method only marks the upload as complete in Phase 2 state.
     * It does NOT call the backend /complete endpoint.
     * 
     * Backend finalization (with title, category_id, metadata) is handled by Phase 3
     * when the user clicks "Finalize Assets" in the upload dialog.
     * 
     * This prevents assets from being created without metadata when Phase 2
     * automatically completes uploads before the user finalizes.
     * 
     * @param {string} clientReference
     */
    async completeUpload(clientReference) {
        const upload = this.uploads.get(clientReference)
        if (!upload || !upload.uploadSessionId) {
            throw new Error('Cannot complete upload: missing uploadSessionId')
        }

        // Phase 2 completion: Only mark as complete locally, do NOT call backend
        // Backend finalization with metadata (title, category_id, metadata fields) is handled by Phase 3's handleFinalize()
        // This prevents assets from being created without metadata when Phase 2 automatically completes uploads
        upload.status = 'completed'
        upload.progress = 100
        upload.error = null
        upload.lastUpdatedAt = Date.now()
        this.activeUploads.delete(clientReference)
        this.stopActivityUpdates(clientReference)
        this.persistToStorage()
        this.notifyListeners()

        // CRITICAL: Do NOT automatically remove completed uploads
        // Completed uploads must remain in UploadManager until finalization completes
        // The upload dialog cleanup logic (on dialog close) will handle removal
        // Removing them here causes stability check to fail because Phase 2 uploads disappear
        // before finalization can verify backend readiness
        // setTimeout(() => {
        //     this.removeUpload(clientReference)
        // }, 5000)
    }

    /**
     * Phase 2.6: Cancel an upload
     * 
     * For multipart uploads, also aborts the multipart upload on S3.
     * 
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

        // Phase 2.6: Abort multipart upload on S3 if in progress
        if (upload.uploadSessionId && upload.multipartUploadId && 
            (upload.status === 'initiating' || upload.status === 'uploading' || upload.status === 'completing')) {
            try {
                await window.axios.post(
                    `/app/uploads/${upload.uploadSessionId}/multipart/abort`
                )
            } catch (error) {
                // Ignore errors - abort is best-effort
                console.warn('[UploadManager] Failed to abort multipart upload:', error)
            }
        }

        // Cancel with backend (idempotent)
        if (upload.uploadSessionId) {
            try {
                await window.axios.post(`/app/uploads/${upload.uploadSessionId}/cancel`)
            } catch (error) {
                // Ignore errors - cancellation is best-effort
                console.warn('[UploadManager] Failed to cancel upload with backend:', error)
            }
        }

        upload.status = 'cancelled'
        upload.error = null
        upload.errorInfo = null
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
     * Phase 2.6: Get pre-signed URL for multipart upload part
     * 
     * NOTE: This method is kept for backward compatibility but is deprecated.
     * New code should use the /multipart/sign-part endpoint directly.
     * 
     * @param {string} uploadSessionId
     * @param {number} partNumber
     * @returns {Promise<string>}
     * @deprecated Use /multipart/sign-part endpoint directly
     */
    async getMultipartUploadUrl(uploadSessionId, partNumber) {
        const response = await window.axios.post(
            `/app/uploads/${uploadSessionId}/multipart/sign-part`,
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
