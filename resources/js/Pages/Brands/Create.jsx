import { useForm, Link, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'

export default function BrandsCreate() {
    const { auth } = usePage().props
    
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        slug: '',
        show_in_selector: true,
        primary_color: '',
        secondary_color: '',
        accent_color: '',
        nav_color: '',
        logo_filter: 'none',
        settings: {},
    })

    const submit = (e) => {
        e.preventDefault()
        post('/app/brands', {
            onSuccess: () => {},
        })
    }

    return (
        <div className="min-h-full">
            <AppHead title="Create Brand" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <Link
                        href="/app/brands"
                        className="text-sm font-medium text-gray-500 hover:text-gray-700"
                    >
                        ← Back to Brands
                    </Link>
                    <h1 className="mt-4 text-3xl font-bold tracking-tight text-gray-900">Create Brand</h1>
                    <p className="mt-2 text-sm text-gray-700">Create a new brand for your organization</p>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    {/* Basic Information */}
                    <div className="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">Basic Information</h3>
                            <div className="space-y-6">
                                <div>
                                    <label htmlFor="name" className="block text-sm font-medium leading-6 text-gray-900">
                                        Brand Name
                                    </label>
                                    <div className="mt-2">
                                        <input
                                            type="text"
                                            name="name"
                                            id="name"
                                            required
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="Acme Corporation"
                                        />
                                        {errors.name && <p className="mt-2 text-sm text-red-600">{errors.name}</p>}
                                    </div>
                                </div>

                                <div>
                                    <label htmlFor="show_in_selector" className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                        Show in brand selector
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() => setData('show_in_selector', !data.show_in_selector)}
                                        className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                            data.show_in_selector ? 'bg-indigo-600' : 'bg-gray-200'
                                        }`}
                                        role="switch"
                                        aria-checked={data.show_in_selector}
                                    >
                                        <span
                                            className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                data.show_in_selector ? 'translate-x-5' : 'translate-x-0'
                                            }`}
                                        />
                                    </button>
                                    <p className="mt-2 text-sm text-gray-500">
                                        When enabled, this brand will appear in the brand selector dropdown in the top navigation. Useful for hiding auto-created default brands.
                                    </p>
                                </div>

                                <p className="text-sm text-gray-500">
                                    Add your logo and icon after creating the brand via Brand Settings → Identity.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Brand Colors */}
                    <div className="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">Brand Colors</h3>
                            <p className="text-sm text-gray-500 mb-4">Define your brand's color palette (optional)</p>
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                                <div>
                                    <label htmlFor="primary_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Primary Color
                                    </label>
                                    <div className="mt-2 flex gap-2">
                                        <input
                                            type="color"
                                            id="primary_color_picker"
                                            value={data.primary_color || '#6366f1'}
                                            onChange={(e) => {
                                                setData('primary_color', e.target.value)
                                                // Auto-update nav_color if it's empty or matches old primary
                                                if (!data.nav_color || data.nav_color === data.primary_color) {
                                                    setData('nav_color', e.target.value)
                                                }
                                            }}
                                            className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                        />
                                        <input
                                            type="text"
                                            name="primary_color"
                                            id="primary_color"
                                            value={data.primary_color}
                                            onChange={(e) => setData('primary_color', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="#6366f1"
                                            pattern="^#[0-9A-Fa-f]{6}$"
                                        />
                                    </div>
                                    {errors.primary_color && <p className="mt-2 text-sm text-red-600">{errors.primary_color}</p>}
                                </div>

                                <div>
                                    <label htmlFor="secondary_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Secondary Color
                                    </label>
                                    <div className="mt-2 flex gap-2">
                                        <input
                                            type="color"
                                            id="secondary_color_picker"
                                            value={data.secondary_color || '#8b5cf6'}
                                            onChange={(e) => setData('secondary_color', e.target.value)}
                                            className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                        />
                                        <input
                                            type="text"
                                            name="secondary_color"
                                            id="secondary_color"
                                            value={data.secondary_color}
                                            onChange={(e) => setData('secondary_color', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="#8b5cf6"
                                            pattern="^#[0-9A-Fa-f]{6}$"
                                        />
                                    </div>
                                    {errors.secondary_color && <p className="mt-2 text-sm text-red-600">{errors.secondary_color}</p>}
                                </div>

                                <div>
                                    <label htmlFor="accent_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Accent Color
                                    </label>
                                    <div className="mt-2 flex gap-2">
                                        <input
                                            type="color"
                                            id="accent_color_picker"
                                            value={data.accent_color || '#ec4899'}
                                            onChange={(e) => setData('accent_color', e.target.value)}
                                            className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                        />
                                        <input
                                            type="text"
                                            name="accent_color"
                                            id="accent_color"
                                            value={data.accent_color}
                                            onChange={(e) => setData('accent_color', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="#ec4899"
                                            pattern="^#[0-9A-Fa-f]{6}$"
                                        />
                                    </div>
                                    {errors.accent_color && <p className="mt-2 text-sm text-red-600">{errors.accent_color}</p>}
                                </div>
                            </div>

                            {/* Color Preview */}
                            {(data.primary_color || data.secondary_color || data.accent_color) && (
                                <div className="mt-6 pt-6 border-t border-gray-200">
                                    <p className="text-sm font-medium text-gray-700 mb-3">Color Preview:</p>
                                    <div className="flex gap-2">
                                        {data.primary_color && (
                                            <div className="flex-1">
                                                <div
                                                    className="h-16 rounded-md border border-gray-200"
                                                    style={{ backgroundColor: data.primary_color }}
                                                />
                                                <p className="mt-2 text-xs text-center text-gray-600">Primary</p>
                                            </div>
                                        )}
                                        {data.secondary_color && (
                                            <div className="flex-1">
                                                <div
                                                    className="h-16 rounded-md border border-gray-200"
                                                    style={{ backgroundColor: data.secondary_color }}
                                                />
                                                <p className="mt-2 text-xs text-center text-gray-600">Secondary</p>
                                            </div>
                                        )}
                                        {data.accent_color && (
                                            <div className="flex-1">
                                                <div
                                                    className="h-16 rounded-md border border-gray-200"
                                                    style={{ backgroundColor: data.accent_color }}
                                                />
                                                <p className="mt-2 text-xs text-center text-gray-600">Accent</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Navigation Settings */}
                    <div className="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">Navigation Settings</h3>
                            <p className="text-sm text-gray-500 mb-4">Customize the top navigation bar appearance</p>
                            
                            <div className="space-y-6">
                                {/* Nav Color */}
                                <div>
                                    <label htmlFor="nav_color" className="block text-sm font-medium leading-6 text-gray-900">
                                        Navigation Bar Color
                                    </label>
                                    <p className="mt-1 text-sm text-gray-500 mb-2">
                                        Override the primary color for the top navigation bar. Leave empty to use primary color.
                                    </p>
                                    <div className="mt-2 flex gap-2">
                                        <input
                                            type="color"
                                            id="nav_color_picker"
                                            value={data.nav_color || data.primary_color || '#ffffff'}
                                            onChange={(e) => setData('nav_color', e.target.value)}
                                            className="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                                        />
                                        <input
                                            type="text"
                                            name="nav_color"
                                            id="nav_color"
                                            value={data.nav_color}
                                            onChange={(e) => setData('nav_color', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder={data.primary_color || '#ffffff'}
                                            pattern="^#[0-9A-Fa-f]{6}$"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setData('nav_color', '')}
                                            className="rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                        >
                                            Reset
                                        </button>
                                    </div>
                                    {errors.nav_color && <p className="mt-2 text-sm text-red-600">{errors.nav_color}</p>}
                                </div>

                                {/* Logo Filter */}
                                <div>
                                    <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                        Logo Filter
                                    </label>
                                    <p className="text-sm text-gray-500 mb-3">
                                        Apply a filter to the logo for better visibility on the navigation bar
                                    </p>
                                    <div className="space-y-2">
                                        <label className="flex items-center">
                                            <input
                                                type="radio"
                                                name="logo_filter"
                                                value="none"
                                                checked={data.logo_filter === 'none'}
                                                onChange={(e) => setData('logo_filter', e.target.value)}
                                                className="h-4 w-4 text-indigo-600 focus:ring-indigo-600"
                                            />
                                            <span className="ml-2 text-sm text-gray-900">None (Original)</span>
                                        </label>
                                        <label className="flex items-center">
                                            <input
                                                type="radio"
                                                name="logo_filter"
                                                value="white"
                                                checked={data.logo_filter === 'white'}
                                                onChange={(e) => setData('logo_filter', e.target.value)}
                                                className="h-4 w-4 text-indigo-600 focus:ring-indigo-600"
                                            />
                                            <span className="ml-2 text-sm text-gray-900">White</span>
                                        </label>
                                        <label className="flex items-center">
                                            <input
                                                type="radio"
                                                name="logo_filter"
                                                value="black"
                                                checked={data.logo_filter === 'black'}
                                                onChange={(e) => setData('logo_filter', e.target.value)}
                                                className="h-4 w-4 text-indigo-600 focus:ring-indigo-600"
                                            />
                                            <span className="ml-2 text-sm text-gray-900">Black</span>
                                        </label>
                                    </div>
                                    {errors.logo_filter && <p className="mt-2 text-sm text-red-600">{errors.logo_filter}</p>}
                                </div>

                                {/* Real-time Preview */}
                                <div className="mt-6 pt-6 border-t border-gray-200">
                                    <p className="text-sm font-medium text-gray-700 mb-3">Live Preview:</p>
                                    <div 
                                        className="rounded-lg border border-gray-200 overflow-hidden"
                                        style={{ 
                                            backgroundColor: data.nav_color || data.primary_color || '#ffffff',
                                            minHeight: '64px',
                                        }}
                                    >
                                        <div className="px-4 py-3 flex items-center justify-between">
                                            <div className="flex items-center">
                                                <div className="h-8 w-8 rounded-full bg-indigo-600 flex items-center justify-center text-white font-bold text-sm">
                                                    {data.name?.charAt(0).toUpperCase() || 'B'}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium" style={{ 
                                                    color: (data.nav_color || data.primary_color) ? '#ffffff' : '#000000'
                                                }}>
                                                    User Name
                                                </span>
                                                <div className="h-8 w-8 rounded-full bg-indigo-600 flex items-center justify-center text-sm font-medium text-white">
                                                    U
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {errors.plan_limit && (
                        <div className="rounded-md bg-red-50 p-4">
                            <p className="text-sm text-red-800">{errors.plan_limit}</p>
                        </div>
                    )}

                    <div className="flex items-center justify-end gap-3">
                        <Link
                            href="/app/brands"
                            className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            Create Brand
                        </button>
                    </div>
                </form>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
