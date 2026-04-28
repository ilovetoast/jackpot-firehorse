import { Link, usePage } from '@inertiajs/react'

export default function OverviewTabs({ children }) {
    const { url } = usePage()
    const brand = usePage().props?.brand ?? usePage().props?.auth?.activeBrand

    const tabs = [
        { name: 'Overview', route: 'overview' },
        { name: 'Categories', route: 'manage.categories' },
        { name: 'Team', route: 'companies.team' },
        { name: 'Activity', route: 'companies.activity' },
        { name: 'Insights', route: 'insights.metadata' },
        { name: 'Settings', route: 'brands.edit', params: (b) => (b ? { brand: b.id } : {}) },
    ]

    return (
        <div>
            <div className="border-b border-gray-200 mb-6">
                <nav className="flex space-x-8">
                    {tabs.map((tab) => {
                        const isOverview = tab.route === 'overview'
                        const isBrandsEdit = tab.route === 'brands.edit'
                        const active = isOverview
                            ? route().current('overview')
                            : (isBrandsEdit && brand
                                ? url.includes(`/brands/${brand.id}/edit`)
                                : route().current(tab.route))

                        const href = tab.params && brand
                            ? route(tab.route, tab.params(brand))
                            : route(tab.route)

                        return (
                            <Link
                                key={tab.name}
                                href={href}
                                className={`pb-3 text-sm font-medium border-b-2 -mb-px transition-colors ${
                                    active
                                        ? 'border-slate-900 text-slate-900'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                }`}
                            >
                                {tab.name}
                            </Link>
                        )
                    })}
                </nav>
            </div>

            {children}
        </div>
    )
}
