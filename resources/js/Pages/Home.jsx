import { Link, usePage } from '@inertiajs/react'

export default function Home() {
    const { auth } = usePage().props

    // Determine the button text and link based on auth status
    const getButtonProps = () => {
        if (auth?.user) {
            // Check if user has a tenant, if so go to dashboard, otherwise companies page
            if (auth.companies && auth.companies.length > 0) {
                return {
                    text: 'Dashboard',
                    href: '/app/dashboard',
                }
            } else {
                return {
                    text: 'Dashboard',
                    href: '/app/companies',
                }
            }
        } else {
            return {
                text: 'Sign in',
                href: '/login',
            }
        }
    }

    const buttonProps = getButtonProps()

    return (
        <div className="bg-white">
            {/* Navigation */}
            <nav className="bg-white shadow-sm relative z-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex items-center">
                            <Link href="/" className="text-xl font-bold text-gray-900">
                                Jackpot
                            </Link>
                        </div>
                        <div className="flex items-center gap-4">
                            {auth?.user ? (
                                <Link
                                    href={buttonProps.href}
                                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                >
                                    {buttonProps.text}
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href="/login"
                                        className="text-sm font-semibold leading-6 text-gray-900 hover:text-gray-700"
                                    >
                                        Login
                                    </Link>
                                    <Link
                                        href="/signup"
                                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                    >
                                        Sign up
                                    </Link>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </nav>

            {/* Hero Section */}
            <div className="relative isolate px-6 pt-14 lg:px-8">
                <div
                    className="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80"
                    aria-hidden="true"
                >
                    <div
                        className="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]"
                        style={{
                            clipPath:
                                'polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.5% 76.7%, 76.1% 97.7%, 74.1% 44.1%)',
                        }}
                    />
                </div>
                <div className="mx-auto max-w-2xl py-32 sm:py-48 lg:py-56">
                    <div className="text-center">
                        <h1 className="text-4xl font-bold tracking-tight text-gray-900 sm:text-6xl">
                            Asset Management Made Simple
                        </h1>
                        <p className="mt-6 text-lg leading-8 text-gray-600">
                            Organize, manage, and access all your brand assets in one place. Built for teams that value efficiency and clarity.
                        </p>
                        <div className="mt-10 flex items-center justify-center gap-x-6">
                            <button
                                onClick={(e) => e.preventDefault()}
                                className="rounded-md bg-indigo-600 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 cursor-default"
                            >
                                Get started
                            </button>
                            <a href="#" className="text-sm font-semibold leading-6 text-gray-900">
                                Learn more <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div
                    className="absolute inset-x-0 top-[calc(100%-13rem)] -z-10 transform-gpu overflow-hidden blur-3xl sm:top-[calc(100%-30rem)]"
                    aria-hidden="true"
                >
                    <div
                        className="relative left-[calc(50%+3rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%+36rem)] sm:w-[72.1875rem]"
                        style={{
                            clipPath:
                                'polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.5% 76.7%, 76.1% 97.7%, 74.1% 44.1%)',
                        }}
                    />
                </div>
            </div>

            {/* Features Section */}
            <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                <div className="mx-auto max-w-2xl lg:text-center">
                    <h2 className="text-base font-semibold leading-7 text-indigo-600">Everything you need</h2>
                    <p className="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                        Powerful asset management
                    </p>
                    <p className="mt-6 text-lg leading-8 text-gray-600">
                        Manage your brand assets with ease. Organize by categories, control access, and keep everything in sync.
                    </p>
                </div>
                <div className="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-4xl">
                    <dl className="grid max-w-xl grid-cols-1 gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-2 lg:gap-y-16">
                        <div className="relative pl-16">
                            <dt className="text-base font-semibold leading-7 text-gray-900">
                                <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                    <svg
                                        className="h-6 w-6 text-white"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth="1.5"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M2.25 12.75V12A2.25 2.25 0 014.5 9h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"
                                        />
                                    </svg>
                                </div>
                                Organized Storage
                            </dt>
                            <dd className="mt-2 text-base leading-7 text-gray-600">
                                Store all your brand assets in one centralized location with intelligent categorization.
                            </dd>
                        </div>
                        <div className="relative pl-16">
                            <dt className="text-base font-semibold leading-7 text-gray-900">
                                <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                    <svg
                                        className="h-6 w-6 text-white"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth="1.5"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"
                                        />
                                    </svg>
                                </div>
                                Easy Access
                            </dt>
                            <dd className="mt-2 text-base leading-7 text-gray-600">
                                Quick search and filtering make it easy to find exactly what you need, when you need it.
                            </dd>
                        </div>
                        <div className="relative pl-16">
                            <dt className="text-base font-semibold leading-7 text-gray-900">
                                <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                    <svg
                                        className="h-6 w-6 text-white"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth="1.5"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
                                        />
                                    </svg>
                                </div>
                                Secure & Private
                            </dt>
                            <dd className="mt-2 text-base leading-7 text-gray-600">
                                Control access with granular permissions and keep sensitive assets private.
                            </dd>
                        </div>
                        <div className="relative pl-16">
                            <dt className="text-base font-semibold leading-7 text-gray-900">
                                <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                    <svg
                                        className="h-6 w-6 text-white"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth="1.5"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"
                                        />
                                    </svg>
                                </div>
                                Team Collaboration
                            </dt>
                            <dd className="mt-2 text-base leading-7 text-gray-600">
                                Work together seamlessly with role-based access control and shared workspaces.
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            {/* Brand-Centric Organization Section */}
            <div className="bg-white">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                            Organize assets the way your brand actually works
                        </h2>
                        <p className="mt-6 text-lg leading-8 text-gray-600">
                            Move beyond folders with brand-aware structure, smart categories, and metadata that scales across teams and regions.
                        </p>
                    </div>
                    <div className="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-4xl">
                        <dl className="grid max-w-xl grid-cols-1 gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-2 lg:gap-y-16">
                            <div className="relative pl-16">
                                <dt className="text-base font-semibold leading-7 text-gray-900">
                                    <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                        <svg
                                            className="h-6 w-6 text-white"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            strokeWidth="1.5"
                                            stroke="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M2.25 12.75V12A2.25 2.25 0 014.5 9h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"
                                            />
                                        </svg>
                                    </div>
                                    Brand-first organization
                                </dt>
                                <dd className="mt-2 text-base leading-7 text-gray-600">
                                    Brand → Category → Asset hierarchy that mirrors how your organization thinks and works.
                                </dd>
                            </div>
                            <div className="relative pl-16">
                                <dt className="text-base font-semibold leading-7 text-gray-900">
                                    <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                        <svg
                                            className="h-6 w-6 text-white"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            strokeWidth="1.5"
                                            stroke="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h7.5c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"
                                            />
                                        </svg>
                                    </div>
                                    Raw vs final separation
                                </dt>
                                <dd className="mt-2 text-base leading-7 text-gray-600">
                                    Keep raw marketing assets separate from final deliverables with clear, organized structure.
                                </dd>
                            </div>
                            <div className="relative pl-16">
                                <dt className="text-base font-semibold leading-7 text-gray-900">
                                    <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                        <svg
                                            className="h-6 w-6 text-white"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            strokeWidth="1.5"
                                            stroke="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M3.75 12V6.75m0 0l3 3m-3-3l-3 3M3.75 12H18m-9 5.25h9m-9 0l-3-3m3 3l3-3"
                                            />
                                        </svg>
                                    </div>
                                    Collections and campaigns
                                </dt>
                                <dd className="mt-2 text-base leading-7 text-gray-600">
                                    Group assets into collections and campaigns without duplicating files or losing organization.
                                </dd>
                            </div>
                            <div className="relative pl-16">
                                <dt className="text-base font-semibold leading-7 text-gray-900">
                                    <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                        <svg
                                            className="h-6 w-6 text-white"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            strokeWidth="1.5"
                                            stroke="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"
                                            />
                                        </svg>
                                    </div>
                                    Shared system structure
                                </dt>
                                <dd className="mt-2 text-base leading-7 text-gray-600">
                                    Consistent structure across teams and regions, with brand-level customization where needed.
                                </dd>
                            </div>
                        </dl>
                    </div>
                    {/* Optional placeholder image */}
                    <div className="mx-auto mt-16 max-w-5xl">
                        <div className="overflow-hidden rounded-2xl bg-gray-900/5 ring-1 ring-gray-900/10">
                            <img
                                src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=1200&h=600&fit=crop"
                                alt="Brand-centric asset organization"
                                className="aspect-[2/1] w-full object-cover"
                            />
                        </div>
                    </div>
                </div>
            </div>

            {/* AI-Assisted Asset Management Section */}
            <div className="bg-white">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto grid max-w-2xl grid-cols-1 gap-x-8 gap-y-16 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-center">
                        {/* Text Content */}
                        <div>
                            <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                                AI that accelerates your team — without risking your brand
                            </h2>
                            <p className="mt-6 text-lg leading-8 text-gray-600">
                                Automated tagging, smart metadata, and brand signals — all reviewed by humans, governed by rules, and backed by audit trails.
                            </p>
                            <dl className="mt-10 space-y-6">
                                <div className="relative pl-16">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-6 w-6 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"
                                                />
                                            </svg>
                                        </div>
                                        AI-generated metadata suggestions with confidence scoring
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Get intelligent suggestions powered by AI, with confidence scores to help you make informed decisions.
                                    </dd>
                                </div>
                                <div className="relative pl-16">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-6 w-6 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"
                                                />
                                            </svg>
                                        </div>
                                        Human approval before AI changes go live
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Every AI suggestion requires human review and approval, ensuring your brand stays protected.
                                    </dd>
                                </div>
                                <div className="relative pl-16">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-6 w-6 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"
                                                />
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M6 6h.008v.008H6V6z"
                                                />
                                            </svg>
                                        </div>
                                        Brand-aware tagging rules and computed metadata
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Intelligent rules that understand your brand guidelines and automatically apply consistent tagging.
                                    </dd>
                                </div>
                                <div className="relative pl-16">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-6 w-6 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"
                                                />
                                            </svg>
                                        </div>
                                        Full audit trails for enterprise accountability
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Complete visibility into every change, approval, and action with comprehensive audit logs.
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        {/* Image Content */}
                        <div className="lg:pl-8">
                            <div className="overflow-hidden rounded-2xl bg-gray-900/5 ring-1 ring-gray-900/10">
                                <img
                                    src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800&h=600&fit=crop"
                                    alt="AI-assisted asset management dashboard"
                                    className="aspect-[16/10] w-full object-cover"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Governance, Approvals & Access Control Section */}
            <div className="bg-white">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    {/* Header */}
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                            Control access, approvals, and sharing — without slowing teams down
                        </h2>
                        <p className="mt-6 text-lg leading-8 text-gray-600">
                            Granular permissions, approval workflows, and secure sharing links keep assets moving while protecting brand integrity.
                        </p>
                    </div>

                    {/* Feature Row 1: Approvals & Governance (Text Left, Image Right) */}
                    <div className="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-x-8 gap-y-16 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-center">
                        <div>
                            <h3 className="text-2xl font-bold tracking-tight text-gray-900">
                                Approvals & governance
                            </h3>
                            <dl className="mt-6 space-y-4">
                                <div className="relative pl-12">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-5 w-5 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"
                                                />
                                            </svg>
                                        </div>
                                        Role-based review
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Assign reviewers by role and ensure the right people approve changes before they go live.
                                    </dd>
                                </div>
                                <div className="relative pl-12">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-5 w-5 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"
                                                />
                                            </svg>
                                        </div>
                                        Metadata and asset approvals
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Require approval for both metadata changes and asset uploads to maintain brand consistency.
                                    </dd>
                                </div>
                                <div className="relative pl-12">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-5 w-5 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"
                                                />
                                            </svg>
                                        </div>
                                        Full audit trails
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Complete visibility into every approval, change, and action with comprehensive audit logs.
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <div className="lg:pl-8">
                            <div className="overflow-hidden rounded-2xl bg-gray-900/5 ring-1 ring-gray-900/10">
                                <img
                                    src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800&h=600&fit=crop"
                                    alt="Approvals and governance dashboard"
                                    className="aspect-[16/10] w-full object-cover"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Feature Row 2: Secure Sharing & Access (Image Left, Text Right) */}
                    <div className="mx-auto mt-24 grid max-w-2xl grid-cols-1 gap-x-8 gap-y-16 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-center">
                        <div className="lg:order-first lg:pr-8">
                            <div className="overflow-hidden rounded-2xl bg-gray-900/5 ring-1 ring-gray-900/10">
                                <img
                                    src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800&h=600&fit=crop"
                                    alt="Secure sharing and access controls"
                                    className="aspect-[16/10] w-full object-cover"
                                />
                            </div>
                        </div>
                        <div>
                            <h3 className="text-2xl font-bold tracking-tight text-gray-900">
                                Secure sharing & access
                            </h3>
                            <dl className="mt-6 space-y-4">
                                <div className="relative pl-12">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-5 w-5 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"
                                                />
                                            </svg>
                                        </div>
                                        Share links with permissions and expiration
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Create secure sharing links with granular permissions and automatic expiration for external access.
                                    </dd>
                                </div>
                                <div className="relative pl-12">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-5 w-5 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"
                                                />
                                            </svg>
                                        </div>
                                        External partners and contributors
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Collaborate safely with external partners and contributors while maintaining control over access.
                                    </dd>
                                </div>
                                <div className="relative pl-12">
                                    <dt className="text-base font-semibold leading-7 text-gray-900">
                                        <div className="absolute left-0 top-0 flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                                            <svg
                                                className="h-5 w-5 text-white"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                strokeWidth="1.5"
                                                stroke="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"
                                                />
                                            </svg>
                                        </div>
                                        Brand-safe access controls
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-gray-600">
                                        Enforce brand guidelines through access controls that prevent unauthorized use or modification.
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            {/* Agency Partner Program Section */}
            <div className="relative overflow-hidden bg-gradient-to-b from-slate-50 to-slate-100">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-indigo-50/80 via-transparent to-transparent" aria-hidden="true" />
                <div className="relative mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto max-w-3xl text-center">
                        <span className="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-sm font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
                            Coming Soon
                        </span>
                        <h2 className="mt-4 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                            Agency Partner Program
                        </h2>
                        <p className="mt-6 text-lg leading-8 text-gray-600">
                            Built for the way agencies and brands actually work together. Agencies incubate and steward client brands; when a brand transfers to the client, the agency keeps a retained partner role and earns rewards. Clients get a production-ready DAM from day one. One platform, shared governance, shared success.
                        </p>
                    </div>

                    {/* Value for both sides */}
                    <div className="mx-auto mt-16 max-w-4xl">
                        <div className="grid grid-cols-1 gap-8 md:grid-cols-2">
                            <div className="rounded-2xl bg-white/80 p-6 shadow-sm ring-1 ring-gray-200/60 backdrop-blur-sm">
                                <h3 className="text-base font-semibold text-gray-900">For agencies</h3>
                                <p className="mt-3 text-sm leading-6 text-gray-600">
                                    Run multiple client brands from one workspace. Incubate brands, transfer ownership when clients are ready, and stay on as a trusted partner with upload, approval, and brand-management access. Partner tier and rewards grow as more clients activate.
                                </p>
                            </div>
                            <div className="rounded-2xl bg-white/80 p-6 shadow-sm ring-1 ring-gray-200/60 backdrop-blur-sm">
                                <h3 className="text-base font-semibold text-gray-900">For clients</h3>
                                <p className="mt-3 text-sm leading-6 text-gray-600">
                                    Your agency delivers a fully configured DAM and hands over ownership with one transfer — no re-upload, no re-setup. Keep your agency as a partner with clear roles and no billing control, so you stay in charge while they keep supporting your brands.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Silver, Gold, Platinum tiers */}
                    <div className="mx-auto mt-20 max-w-6xl">
                        <h3 className="text-center text-sm font-semibold uppercase tracking-wider text-gray-500">Partner tiers</h3>
                        <p className="mt-2 text-center text-base text-gray-600">Advance by activated clients; more transfers unlock higher tiers and benefits.</p>
                        <div className="mt-12 grid grid-cols-1 gap-8 lg:grid-cols-3">
                            <div className="flex flex-col rounded-2xl border border-gray-200/80 bg-white p-8 shadow-sm ring-1 ring-gray-100">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gray-200/80 text-gray-600 font-bold shadow-inner" aria-hidden="true">Ag</span>
                                    <h4 className="text-lg font-bold tracking-tight text-gray-900">Silver</h4>
                                </div>
                                <p className="mt-4 text-sm leading-6 text-gray-600">
                                    Entry tier. Unlimited incubated brands, revenue credit on activated clients, and partner directory listing (coming soon). Perfect for agencies onboarding their first client brands.
                                </p>
                                <p className="mt-3 text-xs font-medium text-gray-500">0 activated clients to qualify</p>
                            </div>
                            <div className="flex flex-col rounded-2xl border-2 border-amber-200/80 bg-white p-8 shadow-md ring-1 ring-amber-100 lg:-my-2 lg:scale-105">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-amber-200 to-amber-400 text-amber-900 font-bold shadow-inner" aria-hidden="true">Au</span>
                                    <h4 className="text-lg font-bold tracking-tight text-gray-900">Gold</h4>
                                </div>
                                <p className="mt-4 text-sm leading-6 text-gray-600">
                                    Higher revenue credit, priority support, co-branding options, and agency dashboards. For agencies with multiple activated clients who want visibility and recognition.
                                </p>
                                <p className="mt-3 text-xs font-medium text-amber-700">5 activated clients to qualify</p>
                            </div>
                            <div className="flex flex-col rounded-2xl border border-slate-200/80 bg-white p-8 shadow-sm ring-1 ring-slate-100">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-slate-300 to-slate-400 text-slate-800 font-bold shadow-inner" aria-hidden="true">Pt</span>
                                    <h4 className="text-lg font-bold tracking-tight text-gray-900">Platinum</h4>
                                </div>
                                <p className="mt-4 text-sm leading-6 text-gray-600">
                                    Custom revenue share, white-label options, joint sales, and early feature access. For strategic partners ready to scale with us.
                                </p>
                                <p className="mt-3 text-xs font-medium text-slate-600">15 activated clients to qualify</p>
                            </div>
                        </div>
                    </div>

                    <div className="mt-16 flex justify-center">
                        <a
                            href="#"
                            className="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:text-indigo-500"
                        >
                            Contact Sales
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>
            </div>

            {/* Footer */}
            <footer className="border-t border-gray-200 bg-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
                    <div className="grid grid-cols-1 gap-8 md:grid-cols-4">
                        <div className="col-span-1 md:col-span-4">
                            <p className="text-center text-sm text-gray-500">
                                Jackpot copyright - Velvetysoft
                            </p>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    )
}
