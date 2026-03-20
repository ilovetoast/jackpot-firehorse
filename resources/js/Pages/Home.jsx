import { Link, usePage } from '@inertiajs/react'
import JackpotLogo from '../Components/JackpotLogo'

export default function Home() {
    const { auth, signup_enabled } = usePage().props

    const getButtonProps = () => {
        if (auth?.user) {
            return { text: 'Dashboard', href: '/gateway' }
        }
        return { text: 'Sign in', href: '/gateway' }
    }

    const buttonProps = getButtonProps()

    return (
        <div className="bg-[#0a0a0f] text-white min-h-screen relative overflow-hidden">
            {/* Cinematic ambient gradients */}
            <div className="pointer-events-none fixed inset-0 z-0" aria-hidden="true">
                <div className="absolute -top-[40%] -left-[20%] w-[80%] h-[80%] rounded-full bg-indigo-900/20 blur-[160px]" />
                <div className="absolute -bottom-[30%] -right-[15%] w-[60%] h-[70%] rounded-full bg-purple-900/15 blur-[140px]" />
                <div className="absolute top-[20%] right-[10%] w-[30%] h-[40%] rounded-full bg-violet-800/10 blur-[120px]" />
            </div>

            {/* Grain overlay */}
            <div
                className="pointer-events-none fixed inset-0 z-[1] opacity-[0.03]"
                aria-hidden="true"
                style={{
                    backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E")`,
                    backgroundRepeat: 'repeat',
                }}
            />

            {/* Navigation */}
            <nav className="relative z-50 border-b border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex items-center">
                            <Link href="/" className="flex items-center">
                                <JackpotLogo className="h-8 w-auto" textClassName="text-xl font-bold text-white" />
                            </Link>
                        </div>
                        <div className="flex items-center gap-4">
                            {auth?.user ? (
                                <Link
                                    href={buttonProps.href}
                                    className="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm hover:bg-gray-100 transition-colors"
                                >
                                    {buttonProps.text}
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href="/gateway"
                                        className="text-sm font-semibold text-white/70 hover:text-white transition-colors"
                                    >
                                        Login
                                    </Link>
                                    {signup_enabled !== false && (
                                        <Link
                                            href="/gateway?mode=register"
                                            className="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm hover:bg-gray-100 transition-colors"
                                        >
                                            Sign up
                                        </Link>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </nav>

            {/* Hero Section */}
            <div className="relative z-10 px-6 pt-14 lg:px-8">
                <div className="mx-auto max-w-2xl py-32 sm:py-48 lg:py-56">
                    <div className="text-center">
                        <h1 className="text-4xl font-bold tracking-tight text-white sm:text-6xl">
                            Asset Management Made Simple
                        </h1>
                        <p className="mt-6 text-lg leading-8 text-white/60">
                            Organize, manage, and access all your brand assets in one place. Built for teams that value efficiency and clarity.
                        </p>
                        <div className="mt-10 flex items-center justify-center gap-x-6">
                            <Link
                                href="/gateway?mode=register"
                                className="rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-gray-900 shadow-sm hover:bg-gray-100 transition-colors"
                            >
                                Get started
                            </Link>
                            <a href="#features" className="text-sm font-semibold leading-6 text-white/70 hover:text-white transition-colors">
                                Learn more <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {/* Features Section */}
            <div id="features" className="relative z-10 border-t border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto max-w-2xl lg:text-center">
                        <h2 className="text-sm font-semibold uppercase tracking-widest text-indigo-400">Everything you need</h2>
                        <p className="mt-2 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            Powerful asset management
                        </p>
                        <p className="mt-6 text-lg leading-8 text-white/50">
                            Manage your brand assets with ease. Organize by categories, control access, and keep everything in sync.
                        </p>
                    </div>
                    <div className="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-4xl">
                        <dl className="grid max-w-xl grid-cols-1 gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-2 lg:gap-y-16">
                            {[
                                {
                                    title: 'Organized Storage',
                                    desc: 'Store all your brand assets in one centralized location with intelligent categorization.',
                                    icon: 'M2.25 12.75V12A2.25 2.25 0 014.5 9h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z',
                                },
                                {
                                    title: 'Easy Access',
                                    desc: 'Quick search and filtering make it easy to find exactly what you need, when you need it.',
                                    icon: 'M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z',
                                },
                                {
                                    title: 'Secure & Private',
                                    desc: 'Control access with granular permissions and keep sensitive assets private.',
                                    icon: 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
                                },
                                {
                                    title: 'Team Collaboration',
                                    desc: 'Work together seamlessly with role-based access control and shared workspaces.',
                                    icon: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
                                },
                            ].map((feature) => (
                                <div key={feature.title} className="relative pl-16">
                                    <dt className="text-base font-semibold leading-7 text-white">
                                        <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-white/[0.08] ring-1 ring-white/[0.1]">
                                            <svg className="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d={feature.icon} />
                                            </svg>
                                        </div>
                                        {feature.title}
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-white/50">{feature.desc}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            </div>

            {/* Brand-Centric Section */}
            <div className="relative z-10 border-t border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            Organize assets the way your brand actually works
                        </h2>
                        <p className="mt-6 text-lg leading-8 text-white/50">
                            Move beyond folders with brand-aware structure, smart categories, and metadata that scales across teams and regions.
                        </p>
                    </div>
                    <div className="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-4xl">
                        <dl className="grid max-w-xl grid-cols-1 gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-2 lg:gap-y-16">
                            {[
                                { title: 'Brand-first organization', desc: 'Brand \u2192 Category \u2192 Asset hierarchy that mirrors how your organization thinks and works.', icon: 'M2.25 12.75V12A2.25 2.25 0 014.5 9h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z' },
                                { title: 'Raw vs final separation', desc: 'Keep raw marketing assets separate from final executions with clear, organized structure.', icon: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h7.5c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z' },
                                { title: 'Collections and campaigns', desc: 'Group assets into collections and campaigns without duplicating files or losing organization.', icon: 'M3.75 12V6.75m0 0l3 3m-3-3l-3 3M3.75 12H18m-9 5.25h9m-9 0l-3-3m3 3l3-3' },
                                { title: 'Shared system structure', desc: 'Consistent structure across teams and regions, with brand-level customization where needed.', icon: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z' },
                            ].map((f) => (
                                <div key={f.title} className="relative pl-16">
                                    <dt className="text-base font-semibold leading-7 text-white">
                                        <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-white/[0.08] ring-1 ring-white/[0.1]">
                                            <svg className="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d={f.icon} />
                                            </svg>
                                        </div>
                                        {f.title}
                                    </dt>
                                    <dd className="mt-2 text-base leading-7 text-white/50">{f.desc}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            </div>

            {/* AI Section */}
            <div className="relative z-10 border-t border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto grid max-w-2xl grid-cols-1 gap-x-8 gap-y-16 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-center">
                        <div>
                            <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                                AI that accelerates your team &mdash; without risking your brand
                            </h2>
                            <p className="mt-6 text-lg leading-8 text-white/50">
                                Automated tagging, smart metadata, and brand signals &mdash; all reviewed by humans, governed by rules, and backed by audit trails.
                            </p>
                            <dl className="mt-10 space-y-6">
                                {[
                                    { title: 'AI-generated metadata suggestions with confidence scoring', desc: 'Get intelligent suggestions powered by AI, with confidence scores to help you make informed decisions.', icon: 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z' },
                                    { title: 'Human approval before AI changes go live', desc: 'Every AI suggestion requires human review and approval, ensuring your brand stays protected.', icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z' },
                                    { title: 'Brand-aware tagging rules and computed metadata', desc: 'Intelligent rules that understand your brand guidelines and automatically apply consistent tagging.', icon: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z' },
                                    { title: 'Full audit trails for enterprise accountability', desc: 'Complete visibility into every change, approval, and action with comprehensive audit logs.', icon: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z' },
                                ].map((f) => (
                                    <div key={f.title} className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-white">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-white/[0.08] ring-1 ring-white/[0.1]">
                                                <svg className="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d={f.icon} />
                                                </svg>
                                            </div>
                                            {f.title}
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-white/50">{f.desc}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                        <div className="lg:pl-8">
                            <div className="overflow-hidden rounded-2xl bg-white/[0.04] ring-1 ring-white/[0.08]">
                                <img
                                    src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800&h=600&fit=crop"
                                    alt="AI-assisted asset management dashboard"
                                    className="aspect-[16/10] w-full object-cover opacity-60"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Governance Section */}
            <div className="relative z-10 border-t border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            Control access, approvals, and sharing &mdash; without slowing teams down
                        </h2>
                        <p className="mt-6 text-lg leading-8 text-white/50">
                            Granular permissions, approval workflows, and secure sharing links keep assets moving while protecting brand integrity.
                        </p>
                    </div>

                    <div className="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-x-8 gap-y-16 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-center">
                        <div>
                            <h3 className="text-2xl font-bold tracking-tight text-white">Approvals & governance</h3>
                            <dl className="mt-6 space-y-4">
                                {[
                                    { title: 'Role-based review', desc: 'Assign reviewers by role and ensure the right people approve changes before they go live.' },
                                    { title: 'Metadata and asset approvals', desc: 'Require approval for both metadata changes and asset uploads to maintain brand consistency.' },
                                    { title: 'Full audit trails', desc: 'Complete visibility into every approval, change, and action with comprehensive audit logs.' },
                                ].map((f) => (
                                    <div key={f.title} className="relative pl-12">
                                        <dt className="text-base font-semibold leading-7 text-white">
                                            <div className="absolute left-0 top-0 flex h-8 w-8 items-center justify-center rounded-lg bg-white/[0.08] ring-1 ring-white/[0.1]">
                                                <svg className="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                                </svg>
                                            </div>
                                            {f.title}
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-white/50">{f.desc}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                        <div>
                            <h3 className="text-2xl font-bold tracking-tight text-white">Secure sharing & access</h3>
                            <dl className="mt-6 space-y-4">
                                {[
                                    { title: 'Share links with permissions and expiration', desc: 'Create secure sharing links with granular permissions and automatic expiration for external access.' },
                                    { title: 'External partners and contributors', desc: 'Collaborate safely with external partners and contributors while maintaining control over access.' },
                                    { title: 'Brand-safe access controls', desc: 'Enforce brand guidelines through access controls that prevent unauthorized use or modification.' },
                                ].map((f) => (
                                    <div key={f.title} className="relative pl-12">
                                        <dt className="text-base font-semibold leading-7 text-white">
                                            <div className="absolute left-0 top-0 flex h-8 w-8 items-center justify-center rounded-lg bg-white/[0.08] ring-1 ring-white/[0.1]">
                                                <svg className="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                </svg>
                                            </div>
                                            {f.title}
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-white/50">{f.desc}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            {/* Brand Governance & On-Brand Scoring */}
            <div className="relative z-10 border-t border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto max-w-3xl text-center">
                        <span className="inline-flex items-center rounded-full bg-indigo-500/10 px-3 py-1 text-sm font-semibold text-indigo-400 ring-1 ring-inset ring-indigo-400/20">
                            Brand Governance
                        </span>
                        <h2 className="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            On-brand scoring AI that works behind the scenes
                        </h2>
                        <p className="mt-6 text-lg leading-8 text-white/50">
                            Most DAM systems stop at storage. We use AI to keep your brand consistent across every execution &mdash; automatically. Agencies manage brand governance through AI-powered tools that reinforce guidelines without slowing creative teams down.
                        </p>
                    </div>

                    <div className="mx-auto mt-16 max-w-5xl">
                        <div className="grid grid-cols-1 gap-8 lg:grid-cols-2">
                            <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-8">
                                <h3 className="text-sm font-semibold uppercase tracking-wider text-white/40">Most systems</h3>
                                <ul className="mt-6 space-y-4">
                                    <li className="flex items-start gap-3">
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/[0.06] text-white/30">&times;</span>
                                        <span className="text-base leading-7 text-white/50"><strong className="text-white/70">Hard rule-based</strong> &mdash; rigid checks that block or allow, no nuance</span>
                                    </li>
                                    <li className="flex items-start gap-3">
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/[0.06] text-white/30">&times;</span>
                                        <span className="text-base leading-7 text-white/50"><strong className="text-white/70">Static compliance</strong> &mdash; same rules forever, no learning from your brand</span>
                                    </li>
                                </ul>
                                <p className="mt-6 text-sm text-white/30">Traditional DAM: storage + checkboxes.</p>
                            </div>
                            <div className="rounded-2xl border border-indigo-500/20 bg-indigo-500/[0.04] p-8 ring-1 ring-indigo-500/10 lg:scale-[1.02]">
                                <h3 className="text-sm font-semibold uppercase tracking-wider text-indigo-400">Jackpot</h3>
                                <ul className="mt-6 space-y-4">
                                    {[
                                        { bold: 'Hybrid AI + human', rest: 'AI suggests, humans approve. Best of both.' },
                                        { bold: 'Adaptive brand reinforcement', rest: 'learns from your brand and gets smarter over time' },
                                        { bold: 'Feedback-loop driven', rest: 'approvals and corrections feed back into the system' },
                                    ].map((item) => (
                                        <li key={item.bold} className="flex items-start gap-3">
                                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-500/20 text-indigo-400">&check;</span>
                                            <span className="text-base leading-7 text-white/50"><strong className="text-white/80">{item.bold}</strong> &mdash; {item.rest}</span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-6 text-sm font-medium text-indigo-400">That&apos;s a real product.</p>
                            </div>
                        </div>
                    </div>

                    <div className="mx-auto mt-16 max-w-2xl text-center">
                        <p className="text-base leading-7 text-white/40">
                            On-brand scoring runs in the background &mdash; tagging, suggesting, and flagging so your team stays consistent across campaigns, regions, and channels.
                        </p>
                    </div>
                </div>
            </div>

            {/* Agency Partner Program */}
            <div className="relative z-10 border-t border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-6 py-24 sm:py-32 lg:px-8">
                    <div className="mx-auto max-w-3xl text-center">
                        <span className="inline-flex items-center rounded-full bg-white/[0.06] px-3 py-1 text-sm font-semibold text-white/60 ring-1 ring-inset ring-white/[0.1]">
                            Coming Soon
                        </span>
                        <h2 className="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            Agency Partner Program
                        </h2>
                        <p className="mt-6 text-lg leading-8 text-white/50">
                            Built for the way agencies and brands actually work together. Agencies incubate and steward client brands; when a brand transfers to the client, the agency keeps a retained partner role and earns rewards.
                        </p>
                    </div>

                    <div className="mx-auto mt-16 max-w-4xl">
                        <div className="grid grid-cols-1 gap-8 md:grid-cols-2">
                            <div className="rounded-2xl bg-white/[0.03] p-6 ring-1 ring-white/[0.06]">
                                <h3 className="text-base font-semibold text-white">For agencies</h3>
                                <p className="mt-3 text-sm leading-6 text-white/50">
                                    Run multiple client brands from one workspace. Incubate brands, transfer ownership when clients are ready, and stay on as a trusted partner.
                                </p>
                            </div>
                            <div className="rounded-2xl bg-white/[0.03] p-6 ring-1 ring-white/[0.06]">
                                <h3 className="text-base font-semibold text-white">For clients</h3>
                                <p className="mt-3 text-sm leading-6 text-white/50">
                                    Your agency delivers a fully configured DAM and hands over ownership with one transfer &mdash; no re-upload, no re-setup.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="mx-auto mt-20 max-w-6xl">
                        <h3 className="text-center text-sm font-semibold uppercase tracking-wider text-white/30">Partner tiers</h3>
                        <p className="mt-2 text-center text-base text-white/40">Advance by activated clients; more transfers unlock higher tiers and benefits.</p>
                        <div className="mt-12 grid grid-cols-1 gap-8 lg:grid-cols-3">
                            <div className="flex flex-col rounded-2xl border border-white/[0.06] bg-white/[0.02] p-8">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-white/[0.06] text-white/40 font-bold">Ag</span>
                                    <h4 className="text-lg font-bold tracking-tight text-white">Silver</h4>
                                </div>
                                <p className="mt-4 text-sm leading-6 text-white/50">
                                    Entry tier. Unlimited incubated brands, revenue credit on activated clients, and partner directory listing.
                                </p>
                                <p className="mt-3 text-xs font-medium text-white/30">0 activated clients to qualify</p>
                            </div>
                            <div className="flex flex-col rounded-2xl border border-amber-500/20 bg-amber-500/[0.04] p-8 ring-1 ring-amber-500/10 lg:-my-2 lg:scale-105">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500/30 to-amber-600/20 text-amber-400 font-bold">Au</span>
                                    <h4 className="text-lg font-bold tracking-tight text-white">Gold</h4>
                                </div>
                                <p className="mt-4 text-sm leading-6 text-white/50">
                                    Higher revenue credit, priority support, co-branding options, and agency dashboards.
                                </p>
                                <p className="mt-3 text-xs font-medium text-amber-400/60">5 activated clients to qualify</p>
                            </div>
                            <div className="flex flex-col rounded-2xl border border-white/[0.06] bg-white/[0.02] p-8">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-slate-400/20 to-slate-500/10 text-slate-400 font-bold">Pt</span>
                                    <h4 className="text-lg font-bold tracking-tight text-white">Platinum</h4>
                                </div>
                                <p className="mt-4 text-sm leading-6 text-white/50">
                                    Custom revenue share, white-label options, joint sales, and early feature access.
                                </p>
                                <p className="mt-3 text-xs font-medium text-white/30">15 activated clients to qualify</p>
                            </div>
                        </div>
                    </div>

                    <div className="mt-16 flex justify-center">
                        <a
                            href="#"
                            className="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-400 hover:text-indigo-300 transition-colors"
                        >
                            Contact Sales
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>

            {/* Footer */}
            <footer className="relative z-10 border-t border-white/[0.06]">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
                    <p className="text-center text-sm text-white/30">
                        <span>Jackpot</span> copyright &mdash; Velvetysoft
                    </p>
                </div>
            </footer>
        </div>
    )
}
