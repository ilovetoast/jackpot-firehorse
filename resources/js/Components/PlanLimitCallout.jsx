import { Link } from '@inertiajs/react'

/**
 * Reusable callout component for plan limit warnings
 * @param {string} title - The title of the callout
 * @param {string} message - The message/description
 * @param {string} actionLabel - Label for the action button (default: "Upgrade Plan")
 * @param {string} actionHref - URL for the action button (default: "/app/billing")
 * @param {string} variant - Color variant: "warning" (yellow) or "info" (blue) (default: "warning")
 */
export default function PlanLimitCallout({ 
    title, 
    message, 
    actionLabel = "Upgrade Plan", 
    actionHref = "/app/billing",
    variant = "warning" 
}) {
    const isWarning = variant === "warning"
    
    const bgColor = isWarning ? "bg-yellow-50" : "bg-blue-50"
    const borderColor = isWarning ? "border-yellow-300" : "border-blue-300"
    const iconColor = isWarning ? "text-yellow-400" : "text-blue-400"
    const titleColor = isWarning ? "text-yellow-800" : "text-blue-800"
    const messageColor = isWarning ? "text-yellow-700" : "text-blue-700"
    
    return (
        <div className={`mb-4 rounded-md border ${borderColor} ${bgColor} p-4`}>
            <div className="flex">
                <div className="flex-shrink-0">
                    <svg className={`h-5 w-5 ${iconColor}`} viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                    </svg>
                </div>
                <div className="ml-3 flex-1">
                    <h3 className={`text-sm font-medium ${titleColor}`}>{title}</h3>
                    <div className={`mt-2 text-sm ${messageColor}`}>
                        <p>{message}</p>
                    </div>
                    <div className="mt-4">
                        <Link
                            href={actionHref}
                            className="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800"
                        >
                            {actionLabel}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    )
}
