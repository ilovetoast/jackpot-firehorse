import { Link, usePage } from '@inertiajs/react'
import AppHead from '../Components/AppHead'
import AppNav from '../Components/AppNav'
import AppFooter from '../Components/AppFooter'
import { Squares2X2Icon, TagIcon, ListBulletIcon } from '@heroicons/react/24/outline'

const MANAGE_CATEGORIES_HREF =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

const SIDEBAR_ITEMS = [
    { id: 'categories', label: 'Categories', href: MANAGE_CATEGORIES_HREF, icon: Squares2X2Icon },
    { id: 'tags', label: 'Tags', href: '/app/manage/tags', icon: TagIcon },
    { id: 'values', label: 'Values', href: '/app/manage/values', icon: ListBulletIcon },
]

export default function ManageLayout({ children, title = 'Manage', activeSection = 'categories' }) {
    const { auth, tenant } = usePage().props

    return (
        <div className="min-h-screen flex flex-col bg-gray-50">
            <AppHead title={title} />
            <AppNav brand={auth?.activeBrand} tenant={tenant} />

            <div className="flex-1">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-6">
                        <h1 className="text-3xl font-bold text-gray-900">Manage</h1>
                        <p className="mt-2 text-sm text-gray-600">
                            Configure categories, tags, and controlled values for your brand library.
                        </p>
                    </div>

                    <div className="flex flex-col lg:flex-row gap-8">
                        <aside className="lg:w-56 flex-shrink-0">
                            <nav className="sticky top-8 space-y-1" aria-label="Manage sections">
                                {SIDEBAR_ITEMS.map((item) => {
                                    const Icon = item.icon
                                    const isActive = activeSection === item.id
                                    return (
                                        <Link
                                            key={item.id}
                                            href={item.href}
                                            className={`flex w-full items-center gap-3 px-3 py-2 rounded-md text-sm transition-colors ${
                                                isActive
                                                    ? 'bg-indigo-50 text-indigo-700'
                                                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                                            }`}
                                        >
                                            <Icon
                                                className={`h-5 w-5 shrink-0 ${isActive ? 'text-indigo-600' : 'text-gray-400'}`}
                                            />
                                            <span className={isActive ? 'font-medium text-gray-900' : ''}>{item.label}</span>
                                        </Link>
                                    )
                                })}
                            </nav>
                        </aside>

                        <main className="flex-1 min-w-0">{children}</main>
                    </div>
                </div>
            </div>

            <AppFooter />
        </div>
    )
}
