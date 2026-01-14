/**
 * Phase 3.2 Upload Item Row Component
 * 
 * Read-only row component for a single upload item.
 * Displays filename, status, progress, errors, and expandable metadata.
 * 
 * @module UploadItemRow
 */

import { useState, useEffect, useMemo, useRef, memo } from 'react';
import {
    CheckCircleIcon,
    ExclamationCircleIcon,
    ClockIcon,
    ArrowPathIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    XMarkIcon,
    PhotoIcon,
    PencilIcon
} from '@heroicons/react/24/outline';
import MetadataFieldRenderer from './MetadataFieldRenderer';
import FileTypeIcon from './FileTypeIcon';

/**
 * Phase 3.0B: Performance instrumentation
 * 
 * Dev-only logging to diagnose UI slowness in upload dialog.
 * Tracks render frequency per row to identify unnecessary re-renders.
 */

/**
 * Heartbeat fallback for large multipart uploads
 * 
 * For large files, the first progress event may not fire for 30-60 seconds.
 * During this window, uploads are active (network activity) but progress is still 0.
 * This causes the UI to show "Queued" even though uploads are happening.
 * 
 * Solution: If upload status is 'queued' and enough time has elapsed (>7.5s),
 * show "Uploading..." state anyway (with indeterminate progress animation).
 * 
 * This is a UI-only change - does not affect actual upload state or finalization logic.
 */
function useUploadHeartbeat(item) {
    const queuedSinceRef = useRef(null)
    const [heartbeatActive, setHeartbeatActive] = useState(false)
    
    useEffect(() => {
        // Track when upload first enters 'queued' state
        if (item.uploadStatus === 'queued' && queuedSinceRef.current === null && (item.progress || 0) === 0) {
            queuedSinceRef.current = Date.now()
            setHeartbeatActive(false) // Reset heartbeat state
        }
        
        // Reset timestamp when status changes away from 'queued' or progress appears
        if (item.uploadStatus !== 'queued' || (item.progress || 0) > 0) {
            queuedSinceRef.current = null
            setHeartbeatActive(false)
            return
        }
        
        // Only set up interval if item is queued and has no progress
        if ((item.progress || 0) > 0 || queuedSinceRef.current === null) {
            return
        }
        
        // Check every second if we should trigger heartbeat
        const interval = setInterval(() => {
            if (queuedSinceRef.current === null) {
                setHeartbeatActive(false)
                clearInterval(interval)
                return
            }
            
            const timeElapsed = Date.now() - queuedSinceRef.current
            if (timeElapsed >= 7500) {
                setHeartbeatActive(true) // Trigger heartbeat UI update
                clearInterval(interval)
            }
        }, 1000) // Check every second
        
        return () => clearInterval(interval)
    }, [item.uploadStatus, item.progress])
    
    // Return heartbeat active state (only true if queued, no progress, and >7.5s elapsed)
    return heartbeatActive
}

/**
 * Get status badge configuration
 * @param {string} status - Upload status
 * @returns {Object} Badge config with color and icon
 */
/**
 * Phase 3.0: Enhanced status configuration with improved visual hierarchy
 * 
 * Provides status badge configuration with icons, colors, and labels.
 * Visual states are optimized for clarity without changing upload mechanics.
 */
function getStatusConfig(status) {
    switch (status) {
        case 'queued':
            return {
                label: 'Queued',
                bgColor: 'bg-gray-100',
                textColor: 'text-gray-700',
                icon: ClockIcon,
                iconColor: 'text-gray-500',
                pulse: false
            };
        case 'initiating':
            return {
                label: 'Preparing…',
                bgColor: 'bg-blue-50 border border-blue-200',
                textColor: 'text-blue-700',
                icon: ArrowPathIcon,
                iconColor: 'text-blue-600',
                pulse: true
            };
        case 'uploading':
            return {
                label: 'Uploading',
                bgColor: 'bg-blue-50 border border-blue-200',
                textColor: 'text-blue-700',
                icon: ArrowPathIcon,
                iconColor: 'text-blue-600',
                pulse: true
            };
        case 'processing':
            return {
                label: 'Processing…',
                bgColor: 'bg-indigo-50 border border-indigo-200',
                textColor: 'text-indigo-700',
                icon: ArrowPathIcon,
                iconColor: 'text-indigo-600',
                pulse: true
            };
        case 'completing':
            return {
                label: 'Finalizing…',
                bgColor: 'bg-indigo-50 border border-indigo-200',
                textColor: 'text-indigo-700',
                icon: ArrowPathIcon,
                iconColor: 'text-indigo-600',
                pulse: true
            };
        case 'complete':
            return {
                label: 'Complete',
                bgColor: 'bg-green-100 border border-green-200',
                textColor: 'text-green-700',
                icon: CheckCircleIcon,
                iconColor: 'text-green-600',
                pulse: false
            };
        case 'failed':
            return {
                label: 'Failed',
                bgColor: 'bg-red-100 border border-red-200',
                textColor: 'text-red-700',
                icon: ExclamationCircleIcon,
                iconColor: 'text-red-600',
                pulse: false
            };
        default:
            return {
                label: 'Unknown',
                bgColor: 'bg-gray-100',
                textColor: 'text-gray-700',
                icon: ClockIcon,
                iconColor: 'text-gray-500',
                pulse: false
            };
    }
}

/**
 * Format file size for display
 * @param {number} bytes - File size in bytes
 * @returns {string} Formatted size string
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

/**
 * UploadItemRow - Individual upload item row with per-file overrides
 * 
 * @param {Object} props
 * @param {UploadItem} props.item - Upload item to display
 * @param {Object} props.uploadManager - Phase 3 upload manager instance
 * @param {Function} [props.onRemove] - Callback when item should be removed
 */
/**
 * Phase 3.0B: UploadItemRow with render containment optimization
 * 
 * This component is memoized to prevent unnecessary re-renders when other rows update.
 * Without memoization, updating progress on one file causes ALL rows to re-render,
 * causing janky UI with 40+ files. With memoization, only the active row re-renders.
 */
function UploadItemRow({ item, uploadManager, onRemove, disabled = false }) {

    // CLEAN UPLOADER V2: DEV warning for failed files
    if (process.env.NODE_ENV === 'development' && item.uploadStatus === 'failed') {
        console.warn('[UPLOAD_V2_UI] rendering failed file', { 
            clientId: item.clientId, 
            error: item.error 
        });
    }

    // Helper to get filename without extension (must be defined before use)
    const getFilenameWithoutExtension = (filename) => {
        if (!filename) return '';
        const lastDot = filename.lastIndexOf('.');
        if (lastDot === -1) {
            return filename;
        }
        return filename.substring(0, lastDot);
    };
    
    // Helper to get extension from original filename
    const getFileExtension = (filename) => {
        if (!filename) return '';
        const lastDot = filename.lastIndexOf('.');
        if (lastDot === -1 || lastDot === filename.length - 1) {
            return '';
        }
        return filename.substring(lastDot + 1).toLowerCase();
    };
    
    // Safe filename accessors with defaults
    const originalFilename = item.originalFilename || 'unknown';
    const resolvedFilename = item.resolvedFilename || originalFilename;
    
    const [isExpanded, setIsExpanded] = useState(false);
    const [titleEditing, setTitleEditing] = useState(false);
    const [editedTitle, setEditedTitle] = useState(item.title || getFilenameWithoutExtension(originalFilename));
    const [showEditIcon, setShowEditIcon] = useState(false);
    const titleInputRef = useRef(null);
    
    // Resolved filename editing state (power-user control)
    const [filenameEditing, setFilenameEditing] = useState(false);
    const [editedFilename, setEditedFilename] = useState(resolvedFilename);
    const [showFilenameEditIcon, setShowFilenameEditIcon] = useState(false);
    const [filenameError, setFilenameError] = useState(null);
    const filenameInputRef = useRef(null);
    
    // Heartbeat fallback for large multipart uploads (shows "Uploading..." when queued >7.5s with no progress)
    const shouldShowUploadingHeartbeat = useUploadHeartbeat(item);
    
    // Phase 3.0: Enhanced status display logic
    // Use heartbeat status override if active (shows "Uploading..." even when Phase 3 status is 'queued')
    // Also check if we should show "Preparing upload..." (uploading status with 0% progress = initiating phase)
    const isLikelyInitiating = item.uploadStatus === 'uploading' && (item.progress || 0) === 0 && !shouldShowUploadingHeartbeat;
    let displayStatus = shouldShowUploadingHeartbeat ? 'uploading' : (isLikelyInitiating ? 'initiating' : item.uploadStatus);
    
    // Phase 3.0: Map 'completing' to 'processing' for clearer visual distinction
    if (displayStatus === 'completing') {
        displayStatus = 'processing';
    }
    
    const statusConfig = getStatusConfig(displayStatus);
    const StatusIcon = statusConfig.icon;
    
    const extension = getFileExtension(originalFilename);
    
    // Sync editedTitle when item.title changes (but not when editing)
    useEffect(() => {
        if (!titleEditing) {
            setEditedTitle(item.title || getFilenameWithoutExtension(originalFilename));
        }
    }, [item.title, originalFilename, titleEditing]);
    
    // Sync editedFilename when item.resolvedFilename changes (but not when editing)
    // Extract just the name part (without extension) for editing
    useEffect(() => {
        if (!filenameEditing) {
            // Extract name without extension for editing
            const nameWithoutExt = getFilenameWithoutExtension(resolvedFilename);
            setEditedFilename(nameWithoutExt);
        }
    }, [resolvedFilename, filenameEditing]);
    
    // Focus input when entering edit mode
    useEffect(() => {
        if (titleEditing && titleInputRef.current) {
            titleInputRef.current.focus();
            titleInputRef.current.select();
        }
    }, [titleEditing]);
    
    // Focus filename input when entering edit mode
    useEffect(() => {
        if (filenameEditing && filenameInputRef.current) {
            filenameInputRef.current.focus();
            filenameInputRef.current.select();
        }
    }, [filenameEditing]);
    
    // Image preview for image files (safe access)
    const isImage = item.file?.type?.startsWith('image/') || false;
    const previewUrl = useMemo(() => {
        if (isImage && item.file) {
            return URL.createObjectURL(item.file);
        }
        return null;
    }, [isImage, item.file]);
    
    // Cleanup object URL on unmount
    useEffect(() => {
        return () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        };
    }, [previewUrl]);
    
    // Get effective metadata (read-only) - safe access
    const effectiveMetadata = uploadManager?.getEffectiveMetadata?.(item.clientId) || {};
    
    // Get global metadata for comparison - safe access
    const globalMetadata = uploadManager?.globalMetadataDraft || {};
    const availableFields = uploadManager?.availableMetadataFields || [];
    
    // Check if field is overridden - safe access
    // Field is overridden if it exists in metadataDraft (even if value is empty/undefined)
    const isFieldOverridden = (fieldKey) => {
        return item.metadataDraft && fieldKey in item.metadataDraft;
    };
    
    // Original title for fallback display (title is primary, not an override)
    const originalTitle = getFilenameWithoutExtension(originalFilename);
    
    // Handle title save
    const handleTitleSave = () => {
        const newTitle = editedTitle.trim() || originalTitle;
        if (newTitle !== item.title && uploadManager?.setTitle) {
            uploadManager.setTitle(item.clientId, newTitle);
            // resolvedFilename will be automatically derived by setTitle
        }
        setTitleEditing(false);
    };
    
    // Handle title cancel
    const handleTitleCancel = () => {
        setEditedTitle(item.title || originalTitle);
        setTitleEditing(false);
    };
    
    // Handle title edit start
    const handleTitleEdit = (e) => {
        e.stopPropagation(); // Prevent row expansion
        setTitleEditing(true);
    };
    
    // Handle keydown in title input
    const handleTitleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleTitleSave();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            handleTitleCancel();
        }
    };
    
    // Handle filename save
    const handleFilenameSave = () => {
        // Combine edited name with locked extension
        const editedNameOnly = editedFilename.trim();
        const fullFilename = editedNameOnly ? `${editedNameOnly}.${extension}` : resolvedFilename;
        
        // Clear any previous error
        setFilenameError(null);
        
        // Validate that we have a name (extension is always locked, so no need to validate it)
        if (!editedNameOnly) {
            setFilenameError('Filename cannot be empty');
            return;
        }
        
        if (fullFilename !== item.resolvedFilename) {
            uploadManager?.setResolvedFilename?.(item.clientId, fullFilename);
        }
        setFilenameEditing(false);
        setFilenameError(null);
    };
    
    // Handle filename cancel
    const handleFilenameCancel = () => {
        // Reset to name without extension
        const nameWithoutExt = getFilenameWithoutExtension(resolvedFilename);
        setEditedFilename(nameWithoutExt);
        setFilenameEditing(false);
        setFilenameError(null);
    };
    
    // Handle filename edit start
    const handleFilenameEdit = (e) => {
        e.stopPropagation(); // Prevent row expansion
        setFilenameEditing(true);
    };
    
    // Handle keydown in filename input
    const handleFilenameKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleFilenameSave();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            handleFilenameCancel();
        }
    };

    // Handle remove (only for queued/failed items)
    const canRemove = !disabled && (item.uploadStatus === 'queued' || item.uploadStatus === 'failed');
    const handleRemove = (e) => {
        e.stopPropagation(); // Prevent row expansion
        if (onRemove && canRemove) {
            onRemove(item.clientId);
        }
    };

    // Phase 3.0: Enhanced visual state indicators
    // Phase 3.0B: Calculate isActive for animation gating
    // Only active rows (uploading/initiating/processing) should animate to reduce CPU usage
    const isActive = displayStatus === 'uploading' || displayStatus === 'initiating' || displayStatus === 'processing';
    const isComplete = displayStatus === 'complete';
    const isFailed = displayStatus === 'failed';
    
    /**
     * Phase 3.0B: Animation gating - critical for performance
     * 
     * Animations (pulse, spin, sheen) are CPU-intensive. With 40+ files, animating all rows
     * causes frame drops and janky scrolling. By gating animations to only active rows,
     * we ensure smooth UI even with large upload queues.
     * 
     * Completed/queued/failed rows remain visually static (no animations).
     */
    const shouldAnimate = isActive && (
        displayStatus === 'initiating' ||
        displayStatus === 'uploading' ||
        displayStatus === 'processing'
    );
    
    // Phase 3.0: Enhanced progress bar color coding
    const getProgressBarColor = () => {
        switch (displayStatus) {
            case 'queued':
                return 'bg-gray-300';
            case 'initiating':
            case 'uploading':
                return 'bg-blue-600';
            case 'processing':
                return 'bg-indigo-600';
            case 'complete':
                return 'bg-green-600';
            case 'failed':
                return 'bg-red-600';
            default:
                return 'bg-gray-300';
        }
    };
    
    // Phase 3.0: Animated sheen for active states (uploading, initiating, or processing)
    // Phase 3.0B: Gate sheen animation to active rows only (performance optimization)
    // Sheen indicates active work is happening even if progress hasn't updated yet
    const shouldShowSheen = shouldAnimate;

    // Phase 3.0: Enhanced progress percentage calculation
    const getProgressPercentage = () => {
        if (item.uploadStatus === 'complete') return 100;
        if (item.uploadStatus === 'failed') return 0;
        
        // Phase 3.0: Processing state shows 95% to indicate near-completion
        if (displayStatus === 'processing') {
            return Math.max(item.progress || 0, 95); // Show high progress during finalization
        }
        
        // If heartbeat is active, show small indeterminate progress (5% with pulse animation)
        if (shouldShowUploadingHeartbeat) {
            return 5; // Small visual progress to show activity
        }
        
        return item.progress || 0;
    };


    return (
        <div className={`bg-white transition-colors ${
            isActive ? 'border-l-2 border-l-blue-500' : 
            isComplete ? 'border-l-2 border-l-green-500' :
            isFailed ? 'border-l-2 border-l-red-500' : ''
        }`}>
            {/* Phase 3.0: Main row with enhanced visual hierarchy */}
            <div
                className={`px-4 py-3 transition-colors ${
                    isActive ? 'bg-blue-50/30 hover:bg-blue-50/50' :
                    isComplete ? 'bg-green-50/30 hover:bg-green-50/50' :
                    isFailed ? 'bg-red-50/30 hover:bg-red-50/50' :
                    'hover:bg-gray-50'
                }`}
            >
                <div className="flex items-center justify-between">
                    {/* Left: Title and status */}
                    <div className="flex items-center flex-1 min-w-0">
                        {/* Phase 3.0C: Thumbnail preview or file-type icon */}
                        <div className="flex-shrink-0 mr-3">
                            {isImage && previewUrl ? (
                                // Show file preview for images during upload (blob URL from file object)
                                <div className="relative h-10 w-10 rounded border border-gray-200 overflow-hidden bg-gray-50">
                                    <img
                                        src={previewUrl}
                                        alt={resolvedFilename}
                                        className="h-full w-full object-cover"
                                        onError={(e) => {
                                            // If preview fails to load, hide image and show icon instead
                                            e.currentTarget.style.display = 'none'
                                            const iconContainer = e.currentTarget.nextElementSibling
                                            if (iconContainer) {
                                                iconContainer.style.display = 'flex'
                                            }
                                        }}
                                    />
                                    {/* Fallback file-type icon (hidden by default, shown if image fails) */}
                                    <div className="absolute inset-0 flex items-center justify-center" style={{ display: 'none' }}>
                                        <FileTypeIcon
                                            fileExtension={extension}
                                            mimeType={item.file?.type}
                                            size="sm"
                                            iconClassName="text-gray-400"
                                        />
                                    </div>
                                </div>
                            ) : (
                                // Show file-type icon for non-image files or when no preview available
                                <div className="h-10 w-10 flex items-center justify-center">
                                    <FileTypeIcon
                                        fileExtension={extension}
                                        mimeType={item.file?.type}
                                        size="sm"
                                        iconClassName={statusConfig.iconColor}
                                    />
                                </div>
                            )}
                        </div>

                        {/* Title (inline editable headline) and inline progress bar */}
                        <div className="flex-1 min-w-0 mr-4">
                            <div 
                                className="flex items-center group"
                                onMouseEnter={() => !titleEditing && setShowEditIcon(true)}
                                onMouseLeave={() => setShowEditIcon(false)}
                            >
                                {titleEditing ? (
                                    <div className="flex items-center flex-1 min-w-0">
                                        <input
                                            ref={titleInputRef}
                                            type="text"
                                            value={editedTitle}
                                            onChange={(e) => setEditedTitle(e.target.value)}
                                            onBlur={handleTitleSave}
                                            onKeyDown={handleTitleKeyDown}
                                            onClick={(e) => e.stopPropagation()}
                                            className="flex-1 text-sm font-semibold text-gray-900 bg-transparent border-0 border-b border-gray-300 focus:border-indigo-500 focus:ring-0 focus:outline-none px-0 py-0 min-w-0"
                                            placeholder={originalTitle}
                                        />
                                        {extension && (
                                            <span className="text-gray-400 ml-1 text-sm font-normal flex-shrink-0">.{extension}</span>
                                        )}
                                    </div>
                                ) : (
                                    <div className="flex items-center flex-1 min-w-0">
                                        <button
                                            type="button"
                                            onClick={handleTitleEdit}
                                            onMouseDown={(e) => e.stopPropagation()} // Prevent row expansion
                                            className="flex items-center gap-1.5 text-left min-w-0 flex-1 group/title cursor-text"
                                        >
                                            <span className="text-sm font-semibold text-gray-900 truncate group-hover/title:underline">
                                                {item.title || originalTitle}
                                            </span>
                                            {showEditIcon && (
                                                <PencilIcon className="h-3.5 w-3.5 text-gray-400 flex-shrink-0" />
                                            )}
                                        </button>
                                        {extension && (
                                            <span className="text-gray-400 ml-1 text-sm font-normal flex-shrink-0">.{extension}</span>
                                        )}
                                    </div>
                                )}
                            </div>
                            <p className="text-xs text-gray-500 mt-0.5">
                                {item.file?.size ? formatFileSize(item.file.size) : 'Unknown size'}
                            </p>
                            
                            {/* Phase 3.0: Enhanced progress bar with percentage */}
                            <div className="mt-2 w-full">
                                <div className="flex items-center gap-2">
                                    <div className="relative flex-1 h-2 overflow-hidden rounded-full bg-gray-200">
                                        <div
                                            className={`h-full transition-[width] duration-300 ${getProgressBarColor()}`}
                                            style={{ width: `${getProgressPercentage()}%` }}
                                        />
                                        {/* Animated sheen overlay (uploading/initiating/processing only) */}
                                        {shouldShowSheen && (
                                            <div className="absolute inset-0 overflow-hidden rounded-full">
                                                <div 
                                                    className="upload-sheen"
                                                    style={{
                                                        position: 'absolute',
                                                        inset: 0,
                                                        background: 'linear-gradient(110deg, transparent 25%, rgba(255, 255, 255, 0.35) 37%, transparent 63%)',
                                                        backgroundSize: '200% 100%',
                                                        animation: 'upload-sheen-animation 1.6s linear infinite'
                                                    }}
                                                />
                                            </div>
                                        )}
                                    </div>
                                    {/* Phase 3.0: Progress percentage text */}
                                    <span className="text-xs font-medium text-gray-600 tabular-nums min-w-[3rem] text-right">
                                        {item.uploadStatus === 'complete' ? '100%' : 
                                         item.uploadStatus === 'failed' ? '—' :
                                         `${Math.round(getProgressPercentage())}%`}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Phase 3.0: Enhanced status badge with icon */}
                        {/* Phase 3.0B: Animations gated to active rows only for performance */}
                        <div className="flex-shrink-0 mr-4 flex items-center gap-2">
                            <span
                                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${statusConfig.bgColor} ${statusConfig.textColor} ${
                                    shouldAnimate && statusConfig.pulse ? 'animate-pulse' : ''
                                }`}
                            >
                                <StatusIcon className={`h-3.5 w-3.5 ${statusConfig.iconColor} ${
                                    shouldAnimate && statusConfig.pulse ? 'animate-spin' : ''
                                }`} />
                                {statusConfig.label}
                            </span>
                            {/* Show "Expired" badge for rehydrated/expired uploads - safe access */}
                            {(item.error?.type === 'rehydrated_expired' || item.error?.type === 'old_upload_expired') && (
                                <span
                                    className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700"
                                    title="This upload was from a previous session and cannot be resumed"
                                >
                                    Expired
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Right: Remove button and expand */}
                    <div className="flex items-center flex-shrink-0 gap-2">
                        {/* Remove button (only for queued/failed) */}
                        {canRemove && onRemove && (
                            <button
                                type="button"
                                onClick={handleRemove}
                                className="text-gray-400 hover:text-red-600 transition-colors p-1 rounded"
                                title="Remove upload"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        )}

                        {/* Expand/collapse button */}
                        <button
                            type="button"
                            onClick={() => setIsExpanded(!isExpanded)}
                            className="text-gray-400 hover:text-gray-600 transition-colors p-1 rounded"
                            title={isExpanded ? "Collapse details" : "Expand details"}
                        >
                            {isExpanded ? (
                                <ChevronUpIcon className="h-5 w-5" />
                            ) : (
                                <ChevronDownIcon className="h-5 w-5" />
                            )}
                        </button>
                    </div>
                </div>

                {/* Error message (if failed) - safe access */}
                {item.uploadStatus === 'failed' && item.error && (
                    <div className="mt-2 ml-8">
                        <div className="flex items-start">
                            <ExclamationCircleIcon className={`h-4 w-4 mr-2 flex-shrink-0 mt-0.5 ${
                                item.error?.stage === 'finalize' ? 'text-amber-500' : 'text-red-500'
                            }`} />
                            <div className="flex-1">
                                <p className={`text-sm ${
                                    item.error?.stage === 'finalize' ? 'text-amber-700' : 'text-red-600'
                                }`}>
                                    {item.error?.message || 'Upload failed'}
                                </p>
                                {/* Phase 3.0C: Show specific message for session expiration (419 CSRF errors) */}
                                {item.error?.message && item.error.message.includes('Session expired') && (
                                    <p className="text-xs text-amber-600 mt-1 font-medium">
                                        Your session has expired. Please refresh the page to continue uploading.
                                    </p>
                                )}
                                {/* Show indicator if this was a rehydrated/expired upload - safe access */}
                                {!item.error?.message?.includes('Session expired') && 
                                 (item.error?.type === 'rehydrated_expired' || item.error?.type === 'old_upload_expired' || 
                                  (item.error?.message && (item.error.message.includes('expired') || item.error.message.includes('Previous upload session') || item.error.message.includes('does not exist in S3')))) && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        This upload was from a previous session and cannot be resumed. Remove it and upload the file again.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Phase 3.0: Enhanced expanded details section */}
            {isExpanded && (
                <div 
                    className={`px-4 py-3 border-t transition-colors ${
                        isActive ? 'bg-blue-50/20 border-blue-100' :
                        isComplete ? 'bg-green-50/20 border-green-100' :
                        isFailed ? 'bg-red-50/20 border-red-100' :
                        'bg-gray-50 border-gray-200'
                    }`}
                    onClick={(e) => e.stopPropagation()} // Prevent collapse when clicking inside
                >
                    <div className="space-y-6">
                        {/* File info */}
                        <div>
                            <h4 className="text-xs font-medium text-gray-700 mb-2">File Information</h4>
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt className="text-gray-500 mb-1">Original filename</dt>
                                    <dd className="text-gray-900 font-mono text-xs break-all">{originalFilename}</dd>
                                </div>
                                <div>
                                    <dt className="text-gray-500 mb-1">Resolved filename</dt>
                                    <dd className="text-gray-900">
                                        {filenameEditing ? (
                                            <div>
                                                <div className="flex items-center gap-1">
                                                    <input
                                                        ref={filenameInputRef}
                                                        type="text"
                                                        value={editedFilename}
                                                        onChange={(e) => {
                                                            setEditedFilename(e.target.value);
                                                            setFilenameError(null); // Clear error on change
                                                        }}
                                                        onBlur={handleFilenameSave}
                                                        onKeyDown={handleFilenameKeyDown}
                                                        onClick={(e) => e.stopPropagation()}
                                                        className={`flex-1 px-2 py-1 text-xs font-mono border rounded-l focus:ring-1 focus:outline-none ${
                                                            filenameError 
                                                                ? 'border-red-500 focus:border-red-500 focus:ring-red-500' 
                                                                : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500'
                                                        }`}
                                                        placeholder={getFilenameWithoutExtension(originalFilename)}
                                                    />
                                                    <div className="px-2 py-1 text-xs font-mono border border-l-0 border-gray-300 rounded-r bg-gray-50 text-gray-600 flex items-center">
                                                        .{extension}
                                                    </div>
                                                </div>
                                                {filenameError && (
                                                    <p className="mt-1 text-xs text-red-600">{filenameError}</p>
                                                )}
                                            </div>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={handleFilenameEdit}
                                                onMouseEnter={() => !filenameEditing && setShowFilenameEditIcon(true)}
                                                onMouseLeave={() => setShowFilenameEditIcon(false)}
                                                onMouseDown={(e) => e.stopPropagation()}
                                                className="text-left w-full px-2 py-1 text-xs font-mono break-all hover:bg-gray-50 rounded border border-transparent hover:border-gray-300 transition-colors group flex items-center gap-1"
                                                title="Click to edit (used for storage and URLs)"
                                            >
                                                <span className="flex-1 break-all">{resolvedFilename}</span>
                                                {showFilenameEditIcon && (
                                                    <PencilIcon className="h-3.5 w-3.5 text-gray-400 flex-shrink-0" />
                                                )}
                                            </button>
                                        )}
                                        <p className="mt-1 text-xs text-gray-400">Used for storage and URLs</p>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-gray-500">File size</dt>
                                    <dd className="text-gray-900">{item.file?.size ? formatFileSize(item.file.size) : 'Unknown'}</dd>
                                </div>
                                <div>
                                    <dt className="text-gray-500">MIME type</dt>
                                    <dd className="text-gray-900">{item.file?.type || 'Unknown'}</dd>
                                </div>
                                <div>
                                    <dt className="text-gray-500">Progress</dt>
                                    <dd className="text-gray-900">{item.progress}%</dd>
                                </div>
                                {item.uploadSessionId && (
                                    <div>
                                        <dt className="text-gray-500">Session ID</dt>
                                        <dd className="text-gray-900 font-mono text-xs break-all">
                                            {item.uploadSessionId}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </div>

                        {/* Effective metadata (read-only display) - safe access */}
                        {effectiveMetadata && Object.keys(effectiveMetadata).length > 0 && (
                            <div>
                                <h4 className="text-xs font-medium text-gray-700 mb-2">Effective Metadata</h4>
                                <dl className="space-y-2">
                                    {Object.entries(effectiveMetadata).map(([key, value]) => {
                                        const field = availableFields?.find?.(f => f.key === key);
                                        const isOverridden = isFieldOverridden(key);
                                        
                                        return (
                                            <div key={key} className="flex items-start">
                                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0 flex items-center gap-1">
                                                    {field?.label || key}
                                                    {isOverridden && (
                                                        <span className="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium bg-purple-100 text-purple-700">
                                                            Override
                                                        </span>
                                                    )}
                                                </dt>
                                                <dd className="text-sm text-gray-900 flex-1">
                                                    {Array.isArray(value) ? (
                                                        <span className="inline-flex flex-wrap gap-1">
                                                            {value.map((v, i) => (
                                                                <span
                                                                    key={i}
                                                                    className="inline-flex items-center rounded px-2 py-0.5 bg-gray-100 text-gray-700 text-xs"
                                                                >
                                                                    {String(v)}
                                                                </span>
                                                            ))}
                                                        </span>
                                                    ) : typeof value === 'boolean' ? (
                                                        <span className="text-gray-900">
                                                            {value ? 'Yes' : 'No'}
                                                        </span>
                                                    ) : (
                                                        <span className="text-gray-900">{String(value)}</span>
                                                    )}
                                                </dd>
                                            </div>
                                        );
                                    })}
                                </dl>
                            </div>
                        )}

                        {/* Per-file overrides section - safe access */}
                        {Array.isArray(availableFields) && availableFields.length > 0 && (
                            <div className="border-t border-gray-200 pt-4">
                                <div className="flex items-center justify-between mb-3">
                                    <h4 className="text-xs font-medium text-gray-700">
                                        Overrides (this file only)
                                    </h4>
                                    {/* Count of overridden fields - safe access */}
                                    {item.metadataDraft && Object.keys(item.metadataDraft).filter(key => isFieldOverridden(key)).length > 0 && (
                                        <span className="text-xs text-gray-500">
                                            {Object.keys(item.metadataDraft).filter(key => isFieldOverridden(key)).length} field(s) overridden
                                        </span>
                                    )}
                                </div>
                                
                                <div className="space-y-4">
                                    {availableFields.map((field) => {
                                        const isOverridden = isFieldOverridden(field.key);
                                        // Get override value or effective value - safe access
                                        const overrideValue = item.metadataDraft?.[field.key];
                                        const currentValue = isOverridden ? overrideValue : (effectiveMetadata?.[field.key] ?? field?.defaultValue ?? '');
                                        const globalValue = globalMetadata?.[field.key];
                                        
                                        // Check for field-level error (finalize validation errors)
                                        const fieldError = item.error?.stage === 'finalize' && item.error?.fields && typeof item.error.fields === 'object' 
                                            ? item.error.fields[field.key] 
                                            : null;
                                        const hasFieldError = !!fieldError;
                                        
                                        return (
                                            <div key={field.key} className="relative">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex-1">
                                                        <MetadataFieldRenderer
                                                            field={field}
                                                            value={currentValue}
                                                            onChange={(value) => {
                                                                uploadManager?.overrideItemMetadata?.(item.clientId, field.key, value);
                                                            }}
                                                            hasError={hasFieldError}
                                                            disabled={disabled}
                                                        />
                                                        {/* Show field-level error message if present */}
                                                        {hasFieldError && (
                                                            <p className="mt-1 text-xs text-amber-600">
                                                                {typeof fieldError === 'string' ? fieldError : 'Invalid value'}
                                                            </p>
                                                        )}
                                                        {/* Show global value hint if not overridden - safe access */}
                                                        {!isOverridden && !hasFieldError && globalValue !== undefined && globalValue !== '' && (
                                                            <p className="mt-1 text-xs text-gray-500">
                                                                Global: {Array.isArray(globalValue) ? globalValue.join(', ') : String(globalValue)}
                                                            </p>
                                                        )}
                                                    </div>
                                                    {/* Reset to global button */}
                                                    {isOverridden && (
                                                        <button
                                                            type="button"
                                                            onClick={() => uploadManager?.clearItemOverride?.(item.clientId, field.key)}
                                                            className="flex-shrink-0 mt-6 text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1"
                                                            title="Reset to global value"
                                                        >
                                                            <XMarkIcon className="h-3 w-3" />
                                                            Reset
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                                
                                {availableFields.length === 0 && (
                                    <p className="text-xs text-gray-500 italic">
                                        No metadata fields available. Select a category to configure metadata.
                                    </p>
                                )}
                            </div>
                        )}

                        {/* No metadata message */}
                        {Object.keys(effectiveMetadata).length === 0 && availableFields.length === 0 && (
                            <div className="text-sm text-gray-500 italic">
                                No metadata assigned. Select a category to configure metadata.
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * Phase 3.0B: Render containment via React.memo with custom comparator
 * 
 * WHY MEMOIZATION EXISTS:
 * Without memoization, updating progress on one file triggers re-renders of ALL rows
 * (because parent state updates cause all children to re-render). With 40+ files,
 * this causes 40+ unnecessary re-renders per progress update, leading to janky UI.
 * 
 * Custom comparator (not shallow comparison) is required because:
 * - Item object reference changes on every parent update (would always re-render)
 * - We only care about specific fields (status, progress, title, etc.)
 * - UploadManager and onRemove are stable references (don't need comparison)
 * 
 * This ensures only rows with changed data re-render, not all rows.
 * 
 * RENDER CONTAINMENT IS MANDATORY for all future queue features.
 */
export default memo(UploadItemRow, (prevProps, nextProps) => {
    const prev = prevProps.item;
    const next = nextProps.item;
    
    // Phase 3.0B: Calculate isActive for both (needed for animation gating comparison)
    const prevDisplayStatus = prev.uploadStatus;
    const nextDisplayStatus = next.uploadStatus;
    const prevIsActive = prevDisplayStatus === 'uploading' || prevDisplayStatus === 'initiating' || prevDisplayStatus === 'processing';
    const nextIsActive = nextDisplayStatus === 'uploading' || nextDisplayStatus === 'initiating' || nextDisplayStatus === 'processing';
    
    // Phase 3.0B: Compare only fields that affect render output
    // Return true if all fields are equal (skip re-render)
    const propsEqual = (
        prev.clientId === next.clientId &&
        prev.uploadStatus === next.uploadStatus &&
        prev.progress === next.progress &&
        prev.title === next.title &&
        prev.resolvedFilename === next.resolvedFilename &&
        // Error comparison - check both message and stage
        ((!prev.error && !next.error) || 
         (prev.error?.message === next.error?.message && 
          prev.error?.stage === next.error?.stage &&
          prev.error?.type === next.error?.type)) &&
        prevIsActive === nextIsActive &&
        prevProps.disabled === nextProps.disabled &&
        // File object reference comparison (should be stable)
        prev.file === next.file
    );
    
    // Return true = props equal (skip render), false = props different (re-render)
    return propsEqual;
});
