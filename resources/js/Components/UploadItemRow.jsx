/**
 * Phase 3.2 Upload Item Row Component
 * 
 * Read-only row component for a single upload item.
 * Displays filename, status, progress, errors, and expandable metadata.
 * 
 * @module UploadItemRow
 */

import { useState, useEffect, useMemo, useRef } from 'react';
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
function getStatusConfig(status) {
    switch (status) {
        case 'queued':
            return {
                label: 'Queued',
                bgColor: 'bg-gray-100',
                textColor: 'text-gray-700',
                icon: ClockIcon,
                iconColor: 'text-gray-500'
            };
        case 'initiating':
            return {
                label: 'Preparing upload…',
                bgColor: 'bg-blue-100',
                textColor: 'text-blue-700',
                icon: ArrowPathIcon,
                iconColor: 'text-blue-500'
            };
        case 'uploading':
            return {
                label: 'Uploading',
                bgColor: 'bg-blue-100',
                textColor: 'text-blue-700',
                icon: ArrowPathIcon,
                iconColor: 'text-blue-500'
            };
        case 'complete':
            return {
                label: 'Complete',
                bgColor: 'bg-green-100',
                textColor: 'text-green-700',
                icon: CheckCircleIcon,
                iconColor: 'text-green-500'
            };
        case 'failed':
            return {
                label: 'Failed',
                bgColor: 'bg-red-100',
                textColor: 'text-red-700',
                icon: ExclamationCircleIcon,
                iconColor: 'text-red-500'
            };
        default:
            return {
                label: 'Unknown',
                bgColor: 'bg-gray-100',
                textColor: 'text-gray-700',
                icon: ClockIcon,
                iconColor: 'text-gray-500'
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
export default function UploadItemRow({ item, uploadManager, onRemove }) {
    const [isExpanded, setIsExpanded] = useState(false);
    const [titleEditing, setTitleEditing] = useState(false);
    const [editedTitle, setEditedTitle] = useState(item.title || getFilenameWithoutExtension(item.originalFilename));
    const [showEditIcon, setShowEditIcon] = useState(false);
    const titleInputRef = useRef(null);
    
    // Resolved filename editing state (power-user control)
    const [filenameEditing, setFilenameEditing] = useState(false);
    const [editedFilename, setEditedFilename] = useState(item.resolvedFilename || item.originalFilename);
    const filenameInputRef = useRef(null);
    
    // Heartbeat fallback for large multipart uploads (shows "Uploading..." when queued >7.5s with no progress)
    const shouldShowUploadingHeartbeat = useUploadHeartbeat(item);
    
    // Use heartbeat status override if active (shows "Uploading..." even when Phase 3 status is 'queued')
    // Also check if we should show "Preparing upload..." (uploading status with 0% progress = initiating phase)
    const isLikelyInitiating = item.uploadStatus === 'uploading' && (item.progress || 0) === 0 && !shouldShowUploadingHeartbeat;
    const displayStatus = shouldShowUploadingHeartbeat ? 'uploading' : (isLikelyInitiating ? 'initiating' : item.uploadStatus);
    const statusConfig = getStatusConfig(displayStatus);
    const StatusIcon = statusConfig.icon;
    
    // Helper to get extension from original filename
    const getFileExtension = (filename) => {
        const lastDot = filename.lastIndexOf('.');
        if (lastDot === -1 || lastDot === filename.length - 1) {
            return '';
        }
        return filename.substring(lastDot + 1).toLowerCase();
    };
    
    // Helper to get filename without extension
    const getFilenameWithoutExtension = (filename) => {
        const lastDot = filename.lastIndexOf('.');
        if (lastDot === -1) {
            return filename;
        }
        return filename.substring(0, lastDot);
    };
    
    const extension = getFileExtension(item.originalFilename);
    
    // Sync editedTitle when item.title changes (but not when editing)
    useEffect(() => {
        if (!titleEditing) {
            setEditedTitle(item.title || getFilenameWithoutExtension(item.originalFilename));
        }
    }, [item.title, item.originalFilename, titleEditing]);
    
    // Sync editedFilename when item.resolvedFilename changes (but not when editing)
    useEffect(() => {
        if (!filenameEditing) {
            setEditedFilename(item.resolvedFilename || item.originalFilename);
        }
    }, [item.resolvedFilename, item.originalFilename, filenameEditing]);
    
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
    
    // Image preview for image files
    const isImage = item.file.type.startsWith('image/');
    const previewUrl = useMemo(() => {
        if (isImage) {
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
    
    // Get effective metadata (read-only)
    const effectiveMetadata = uploadManager.getEffectiveMetadata(item.clientId);
    
    // Get global metadata for comparison
    const globalMetadata = uploadManager.globalMetadataDraft;
    const availableFields = uploadManager.availableMetadataFields;
    
    // Check if field is overridden
    const isFieldOverridden = (fieldKey) => {
        if (!item.isMetadataOverridden) return false;
        if (typeof item.isMetadataOverridden === 'boolean') {
            return item.isMetadataOverridden && item.metadataDraft[fieldKey] !== undefined;
        }
        return item.isMetadataOverridden[fieldKey] === true;
    };
    
    // Original title for fallback display (title is primary, not an override)
    const originalTitle = getFilenameWithoutExtension(item.originalFilename);
    
    // Handle title save
    const handleTitleSave = () => {
        const newTitle = editedTitle.trim() || originalTitle;
        if (newTitle !== item.title) {
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
        const newFilename = editedFilename.trim() || item.resolvedFilename || item.originalFilename;
        if (newFilename !== item.resolvedFilename) {
            uploadManager.setResolvedFilename(item.clientId, newFilename);
        }
        setFilenameEditing(false);
    };
    
    // Handle filename cancel
    const handleFilenameCancel = () => {
        setEditedFilename(item.resolvedFilename || item.originalFilename);
        setFilenameEditing(false);
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
    const canRemove = (item.uploadStatus === 'queued' || item.uploadStatus === 'failed');
    const handleRemove = (e) => {
        e.stopPropagation(); // Prevent row expansion
        if (onRemove && canRemove) {
            onRemove(item.clientId);
        }
    };

    // Get progress bar color based on display status (includes heartbeat override)
    const getProgressBarColor = () => {
        switch (displayStatus) {
            case 'queued':
                return 'bg-gray-300';
            case 'initiating':
            case 'uploading':
                return 'bg-blue-600';
            case 'complete':
                return 'bg-green-600';
            case 'failed':
                return 'bg-red-600';
            default:
                return 'bg-gray-300';
        }
    };
    
    // Check if we should show animated sheen (uploading or initiating status, including heartbeat)
    // Sheen indicates active work is happening even if progress hasn't updated yet
    const shouldShowSheen = displayStatus === 'uploading' || displayStatus === 'initiating';

    // Get progress percentage (heartbeat shows indeterminate progress)
    const getProgressPercentage = () => {
        if (item.uploadStatus === 'complete') return 100;
        if (item.uploadStatus === 'failed') return 0;
        
        // If heartbeat is active, show small indeterminate progress (5% with pulse animation)
        if (shouldShowUploadingHeartbeat) {
            return 5; // Small visual progress to show activity
        }
        
        return item.progress || 0;
    };

    return (
        <div className="bg-white">
            {/* Main row */}
            <div
                className="px-4 py-3 hover:bg-gray-50 transition-colors"
            >
                <div className="flex items-center justify-between">
                    {/* Left: Title and status */}
                    <div className="flex items-center flex-1 min-w-0">
                        {/* Image preview or status icon */}
                        <div className="flex-shrink-0 mr-3">
                            {isImage && previewUrl ? (
                                <img
                                    src={previewUrl}
                                    alt={item.resolvedFilename || item.originalFilename}
                                    className="h-10 w-10 object-cover rounded border border-gray-200"
                                />
                            ) : (
                                <StatusIcon className={`h-5 w-5 ${statusConfig.iconColor}`} />
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
                                {formatFileSize(item.file.size)}
                            </p>
                            
                            {/* Inline progress bar - always visible */}
                            <div className="mt-2 w-full">
                                <div className="relative h-1 w-full overflow-hidden rounded-full bg-gray-200">
                                    <div
                                        className={`h-full transition-[width] duration-300 ${getProgressBarColor()}`}
                                        style={{ width: `${getProgressPercentage()}%` }}
                                    />
                                    {/* Animated sheen overlay (uploading/initiating only) */}
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
                            </div>
                        </div>

                        {/* Status badge */}
                        <div className="flex-shrink-0 mr-4 flex items-center gap-2">
                            <span
                                className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${statusConfig.bgColor} ${statusConfig.textColor}`}
                            >
                                {statusConfig.label}
                            </span>
                            {/* Show "Expired" badge for rehydrated/expired uploads */}
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

                {/* Error message (if failed) */}
                {item.uploadStatus === 'failed' && item.error && (
                    <div className="mt-2 ml-8">
                        <div className="flex items-start">
                            <ExclamationCircleIcon className="h-4 w-4 text-red-500 mr-2 flex-shrink-0 mt-0.5" />
                            <div className="flex-1">
                                <p className="text-sm text-red-600">
                                    {item.error.message || 'Upload failed'}
                                </p>
                                {/* Show indicator if this was a rehydrated/expired upload */}
                                {(item.error.type === 'rehydrated_expired' || item.error.type === 'old_upload_expired' || 
                                  (item.error.message && (item.error.message.includes('expired') || item.error.message.includes('Previous upload session') || item.error.message.includes('does not exist in S3')))) && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        This upload was from a previous session and cannot be resumed. Remove it and upload the file again.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Expanded details with overrides */}
            {isExpanded && (
                <div 
                    className="px-4 py-3 bg-gray-50 border-t border-gray-200"
                    onClick={(e) => e.stopPropagation()} // Prevent collapse when clicking inside
                >
                    <div className="space-y-6">
                        {/* File info */}
                        <div>
                            <h4 className="text-xs font-medium text-gray-700 mb-2">File Information</h4>
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt className="text-gray-500 mb-1">Original filename</dt>
                                    <dd className="text-gray-900 font-mono text-xs break-all">{item.originalFilename}</dd>
                                </div>
                                <div>
                                    <dt className="text-gray-500 mb-1">Resolved filename</dt>
                                    <dd className="text-gray-900">
                                        {filenameEditing ? (
                                            <input
                                                ref={filenameInputRef}
                                                type="text"
                                                value={editedFilename}
                                                onChange={(e) => setEditedFilename(e.target.value)}
                                                onBlur={handleFilenameSave}
                                                onKeyDown={handleFilenameKeyDown}
                                                onClick={(e) => e.stopPropagation()}
                                                className="w-full px-2 py-1 text-xs font-mono border border-gray-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                                placeholder={item.originalFilename}
                                            />
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={handleFilenameEdit}
                                                onMouseDown={(e) => e.stopPropagation()}
                                                className="text-left w-full px-2 py-1 text-xs font-mono break-all hover:bg-gray-50 rounded border border-transparent hover:border-gray-300 transition-colors group"
                                                title="Click to edit (used for storage and URLs)"
                                            >
                                                {item.resolvedFilename || item.originalFilename}
                                            </button>
                                        )}
                                        <p className="mt-1 text-xs text-gray-400">Used for storage and URLs</p>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-gray-500">File size</dt>
                                    <dd className="text-gray-900">{formatFileSize(item.file.size)}</dd>
                                </div>
                                <div>
                                    <dt className="text-gray-500">MIME type</dt>
                                    <dd className="text-gray-900">{item.file.type || 'Unknown'}</dd>
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

                        {/* Effective metadata (read-only display) */}
                        {Object.keys(effectiveMetadata).length > 0 && (
                            <div>
                                <h4 className="text-xs font-medium text-gray-700 mb-2">Effective Metadata</h4>
                                <dl className="space-y-2">
                                    {Object.entries(effectiveMetadata).map(([key, value]) => {
                                        const field = availableFields.find(f => f.key === key);
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

                        {/* Per-file overrides section */}
                        {availableFields.length > 0 && (
                            <div className="border-t border-gray-200 pt-4">
                                <div className="flex items-center justify-between mb-3">
                                    <h4 className="text-xs font-medium text-gray-700">
                                        Overrides (this file only)
                                    </h4>
                                    {/* Count of overridden fields */}
                                    {Object.keys(item.metadataDraft).filter(key => isFieldOverridden(key)).length > 0 && (
                                        <span className="text-xs text-gray-500">
                                            {Object.keys(item.metadataDraft).filter(key => isFieldOverridden(key)).length} field(s) overridden
                                        </span>
                                    )}
                                </div>
                                
                                <div className="space-y-4">
                                    {availableFields.map((field) => {
                                        const isOverridden = isFieldOverridden(field.key);
                                        // Get override value or effective value
                                        const overrideValue = item.metadataDraft[field.key];
                                        const currentValue = isOverridden ? overrideValue : (effectiveMetadata[field.key] ?? field.defaultValue ?? '');
                                        const globalValue = globalMetadata[field.key];
                                        
                                        return (
                                            <div key={field.key} className="relative">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex-1">
                                                        <MetadataFieldRenderer
                                                            field={field}
                                                            value={currentValue}
                                                            onChange={(value) => {
                                                                uploadManager.overrideItemMetadata(item.clientId, field.key, value);
                                                            }}
                                                            hasError={false} // Validation handled at global level
                                                        />
                                                        {/* Show global value hint if not overridden */}
                                                        {!isOverridden && globalValue !== undefined && globalValue !== '' && (
                                                            <p className="mt-1 text-xs text-gray-500">
                                                                Global: {Array.isArray(globalValue) ? globalValue.join(', ') : String(globalValue)}
                                                            </p>
                                                        )}
                                                    </div>
                                                    {/* Reset to global button */}
                                                    {isOverridden && (
                                                        <button
                                                            type="button"
                                                            onClick={() => uploadManager.clearItemOverride(item.clientId, field.key)}
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
