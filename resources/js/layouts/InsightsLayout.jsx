import { Link, usePage } from '@inertiajs/react'
import AppHead from '../Components/AppHead'
import AppNav from '../Components/AppNav'
import AppFooter from '../Components/AppFooter'
import {
    ChartBarIcon,
    TableCellsIcon,
    ArrowTrendingUpIcon,
    ClockIcon,
    SparklesIcon,
} from '@heroicons/react/24/outline'

const SIDEBAR_ITEMS = [
    { id: 'overview', label: 'Overview', href: '/app/insights/overview', icon: ChartBarIcon },
    { id: 'review', label: 'Review', href: '/app/insights/review', icon: SparklesIcon },
    { id: 'metadata', label: 'Metadata', href: '/app/insights/metadata', icon: TableCellsIcon },
    { id: 'usage', label: 'Usage', href: '/app/insights/usage', icon: ArrowTrendingUpIcon },
    { id: 'activity', label: 'Activity', href: '/app/insights/activity', icon: ClockIcon, disabled: true },
]

export default function InsightsLayout({ children, title = 'Insights', activeSection = 'overview' }) {
    const { auth, tenant } = usePage().props

    return (
        <div className="min-h-screen flex flex-col bg-gray-50">
            <AppHead title={title} />
            <AppNav brand={auth?.activeBrand} tenant={tenant} />

            <div className="flex-1">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-6">
                        <h1 className="text-3xl font-bold text-gray-900">Insights</h1>
                        <p className="mt-2 text-sm text-gray-600">
                            Analytics and metadata health for your brand.
                        </p>
                    </div>

                    <div className="flex flex-col lg:flex-row gap-8">
                        {/* Left sidebar */}
                        <aside className="lg:w-56 flex-shrink-0">
                        <nav className="sticky top-8 space-y-1" aria-label="Insights sections">
                            {SIDEBAR_ITEMS.map((item) => {
                                const Icon = item.icon
                                const isActive = activeSection === item.id
                                const content = (
                                    <>
                                        <Icon className={`h-5 w-5 shrink-0 ${isActive ? 'text-indigo-600' : 'text-gray-400'}`} />
                                        <span className={isActive ? 'font-medium text-gray-900' : 'text-gray-600'}>
                                            {item.label}
                                        </span>
                                    </>
                                )
                                if (item.disabled) {
                                    return (
                                        <div
                                            key={item.id}
                                            className="flex items-center gap-3 px-3 py-2 rounded-md text-gray-400 cursor-not-allowed"
                                        >
                                            <Icon className="h-5 w-5 shrink-0" />
                                            <span>{item.label}</span>
                                            <span className="text-xs text-gray-400">(soon)</span>
                                        </div>
                                    )
                                }
                                return (
                                    <Link
                                        key={item.id}
                                        href={item.href}
                                        className={`flex items-center gap-3 px-3 py-2 rounded-md text-sm transition-colors ${
                                            isActive
                                                ? 'bg-indigo-50 text-indigo-700'
                                                : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                                        }`}
                                    >
                                        {content}
                                    </Link>
                                )
                            })}
                        </nav>
                    </aside>

                        {/* Right content */}
                        <main className="flex-1 min-w-0">
                            {children}
                        </main>
                    </div>
                </div>
            </div>

            <AppFooter />
        </div>
    )
}
