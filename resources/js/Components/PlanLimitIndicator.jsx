import { Link } from '@inertiajs/react'

export default function PlanLimitIndicator({ current, max, label, upgradeUrl = '/billing' }) {
    const formatLimit = (limit) => {
        if (limit === Number.MAX_SAFE_INTEGER || limit === 2147483647) {
            return 'Unlimited'
        }
        return limit
    }

    const percentage = max === Number.MAX_SAFE_INTEGER || max === 2147483647 
        ? 0 
        : Math.min((current / max) * 100, 100)

    const isAtLimit = max !== Number.MAX_SAFE_INTEGER && max !== 2147483647 && current >= max

    return (
        <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className="px-4 py-3">
                <div className="flex items-center justify-between mb-2">
                    <p className="text-sm font-medium text-gray-700">{label}</p>
                    <p className="text-sm text-gray-600">
                        <span className="font-medium">{current}</span> / {formatLimit(max)}
                    </p>
                </div>
                {max !== Number.MAX_SAFE_INTEGER && max !== 2147483647 && (
                    <div className="w-full bg-gray-200 rounded-full h-2">
                        <div
                            className={`h-2 rounded-full transition-all ${
                                isAtLimit ? 'bg-red-600' : percentage > 80 ? 'bg-yellow-500' : 'bg-indigo-600'
                            }`}
                            style={{ width: `${percentage}%` }}
                        />
                    </div>
                )}
                {isAtLimit && (
                    <p className="mt-2 text-sm text-gray-600">
                        Limit reached.{' '}
                        <Link href={upgradeUrl} className="font-medium text-indigo-600 hover:text-indigo-500">
                            Upgrade →
                        </Link>
                    </p>
                )}
            </div>
        </div>
    )
}
