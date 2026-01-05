import { useState, useCallback } from 'react'
import Cropper from 'react-easy-crop'
import 'react-easy-crop/react-easy-crop.css'

/**
 * ImageCropModal - A reusable component for cropping images
 * 
 * @param {boolean} open - Whether the modal is open
 * @param {string} imageSrc - The source image URL/blob
 * @param {function} onClose - Callback when modal is closed
 * @param {function} onCropComplete - Callback with cropped image blob
 * @param {object} aspectRatio - Aspect ratio for cropping (e.g., { width: 1, height: 1 } for square)
 * @param {number} minWidth - Minimum crop width in pixels
 * @param {number} minHeight - Minimum crop height in pixels
 */
export default function ImageCropModal({
    open,
    imageSrc,
    onClose,
    onCropComplete,
    aspectRatio = null, // null for free aspect ratio
    minWidth = 100,
    minHeight = 100,
}) {
    const [crop, setCrop] = useState({ x: 0, y: 0 })
    const [zoom, setZoom] = useState(1)
    const [croppedAreaPixels, setCroppedAreaPixels] = useState(null)
    const [processing, setProcessing] = useState(false)

    const onCropChange = useCallback((crop) => {
        setCrop(crop)
    }, [])

    const onZoomChange = useCallback((zoom) => {
        setZoom(zoom)
    }, [])

    const onCropAreaChange = useCallback((croppedArea, croppedAreaPixels) => {
        setCroppedAreaPixels(croppedAreaPixels)
    }, [])

    const createImage = (url) =>
        new Promise((resolve, reject) => {
            const image = new Image()
            image.addEventListener('load', () => resolve(image))
            image.addEventListener('error', (error) => reject(error))
            image.src = url
        })

    const getCroppedImg = async (imageSrc, pixelCrop) => {
        const image = await createImage(imageSrc)
        const canvas = document.createElement('canvas')
        const ctx = canvas.getContext('2d')

        if (!ctx) {
            throw new Error('No 2d context')
        }

        // Set canvas size to match the cropped area
        canvas.width = pixelCrop.width
        canvas.height = pixelCrop.height

        // Draw the cropped image
        ctx.drawImage(
            image,
            pixelCrop.x,
            pixelCrop.y,
            pixelCrop.width,
            pixelCrop.height,
            0,
            0,
            pixelCrop.width,
            pixelCrop.height
        )

        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (!blob) {
                    reject(new Error('Canvas is empty'))
                    return
                }
                resolve(blob)
            }, 'image/png') // Use PNG to preserve transparency
        })
    }

    const handleCropComplete = async () => {
        if (!croppedAreaPixels) {
            return
        }

        setProcessing(true)
        try {
            const croppedImage = await getCroppedImg(imageSrc, croppedAreaPixels)
            onCropComplete(croppedImage)
            onClose()
        } catch (error) {
            console.error('Error cropping image:', error)
            alert('Error cropping image. Please try again.')
        } finally {
            setProcessing(false)
        }
    }

    if (!open || !imageSrc) {
        return null
    }

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            {/* Backdrop */}
            <div className="fixed inset-0 bg-black bg-opacity-50" onClick={onClose} />

            {/* Modal */}
            <div className="flex min-h-full items-center justify-center p-4">
                <div className="relative bg-white rounded-lg shadow-xl max-w-4xl w-full">
                    {/* Header */}
                    <div className="flex items-center justify-between p-6 border-b border-gray-200">
                        <h3 className="text-lg font-semibold text-gray-900">Crop Logo</h3>
                        <button
                            type="button"
                            onClick={onClose}
                            className="text-gray-400 hover:text-gray-500"
                        >
                            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {/* Cropper */}
                    <div className="relative" style={{ height: '400px', background: '#333' }}>
                        <Cropper
                            image={imageSrc}
                            crop={crop}
                            zoom={zoom}
                            aspect={aspectRatio ? aspectRatio.width / aspectRatio.height : undefined}
                            minWidth={minWidth}
                            minHeight={minHeight}
                            onCropChange={onCropChange}
                            onZoomChange={onZoomChange}
                            onCropComplete={onCropAreaChange}
                        />
                    </div>

                    {/* Controls */}
                    <div className="p-6 border-t border-gray-200">
                        <div className="mb-4">
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Zoom: {Math.round(zoom * 100)}%
                            </label>
                            <input
                                type="range"
                                min={1}
                                max={3}
                                step={0.1}
                                value={zoom}
                                onChange={(e) => setZoom(parseFloat(e.target.value))}
                                className="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>

                        <div className="flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={onClose}
                                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={handleCropComplete}
                                disabled={processing}
                                className="px-4 py-2 text-sm font-medium text-white bg-primary rounded-md hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {processing ? 'Processing...' : 'Apply Crop'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
