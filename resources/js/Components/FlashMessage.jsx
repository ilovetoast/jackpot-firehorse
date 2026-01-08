import { useEffect, useState } from 'react'
import { usePage } from '@inertiajs/react'

export default function FlashMessage() {
    const { flash, errors } = usePage().props
    const [visible, setVisible] = useState(false)
    const [message, setMessage] = useState(null)
    const [type, setType] = useState('success')

    useEffect(() => {
        // Check for flash messages
        if (flash?.success) {
            setMessage(flash.success)
            setType('success')
            setVisible(true)
        } else if (flash?.error) {
            setMessage(flash.error)
            setType('error')
            setVisible(true)
        } else if (flash?.warning) {
            setMessage(flash.warning)
            setType('warning')
            setVisible(true)
        } else if (flash?.info) {
            setMessage(flash.info)
            setType('info')
            setVisible(true)
        } else if (errors && Object.keys(errors).length > 0) {
            // Show first error if there are validation errors
            const firstError = Object.values(errors)[0]
            setMessage(Array.isArray(firstError) ? firstError[0] : firstError)
            setType('error')
            setVisible(true)
        } else {
            setVisible(false)
        }
    }, [flash, errors])

    // Auto-hide after 5 seconds
    useEffect(() => {
        if (visible && message) {
            const timer = setTimeout(() => {
                setVisible(false)
            }, 5000)

            return () => clearTimeout(timer)
        }
    }, [visible, message])

    if (!visible || !message) {
        return null
    }

    const getStyles = () => {
        switch (type) {
            case 'error':
                return 'bg-red-50 border-red-200 text-red-800'
            case 'warning':
                return 'bg-yellow-50 border-yellow-200 text-yellow-800'
            case 'info':
                return 'bg-blue-50 border-blue-200 text-blue-800'
            default:
                return 'bg-green-50 border-green-200 text-green-800'
        }
    }

    const getIcon = () => {
        switch (type) {
            case 'error':
                return (
                    <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clipRule="evenodd" />
                    </svg>
                )
            case 'warning':
                return (
                    <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                    </svg>
                )
            case 'info':
                return (
                    <svg className="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clipRule="evenodd" />
                    </svg>
                )
            default:
                return (
                    <svg className="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                    </svg>
                )
        }
    }

    return (
        <div className="fixed top-4 right-4 z-50 max-w-md w-full">
            <div className={`rounded-lg border p-4 shadow-lg ${getStyles()}`}>
                <div className="flex items-start">
                    <div className="flex-shrink-0">
                        {getIcon()}
                    </div>
                    <div className="ml-3 flex-1">
                        <p className="text-sm font-medium">{message}</p>
                    </div>
                    <div className="ml-4 flex-shrink-0">
                        <button
                            type="button"
                            onClick={() => setVisible(false)}
                            className={`inline-flex rounded-md p-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2 ${
                                type === 'error' ? 'text-red-500 hover:bg-red-100 focus:ring-red-600' :
                                type === 'warning' ? 'text-yellow-500 hover:bg-yellow-100 focus:ring-yellow-600' :
                                type === 'info' ? 'text-blue-500 hover:bg-blue-100 focus:ring-blue-600' :
                                'text-green-500 hover:bg-green-100 focus:ring-green-600'
                            }`}
                        >
                            <span className="sr-only">Dismiss</span>
                            <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
