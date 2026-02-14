import CinematicLayout from '../../Layouts/CinematicLayout'

export default function Login({ brand, onEnter }) {
    return (
        <CinematicLayout brand={brand}>
            <div className="min-h-screen flex flex-col items-center justify-center px-6">
                <img
                    src={brand.logoUrl}
                    alt={brand.name}
                    className="h-24 md:h-32 w-auto object-contain opacity-95 mb-6"
                />
                <p className="text-lg text-white/65 mb-12">{brand.tagline}</p>
                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        onEnter?.()
                    }}
                    className="w-full max-w-sm space-y-4"
                >
                    <input
                        type="email"
                        placeholder="Email"
                        className="w-full px-4 py-3 bg-white/[0.04] border border-white/[0.08] rounded-lg text-white placeholder-white/40 focus:outline-none focus:border-[var(--brand-primary)] transition-colors duration-500"
                        readOnly
                        aria-label="Email"
                    />
                    <input
                        type="password"
                        placeholder="Password"
                        className="w-full px-4 py-3 bg-white/[0.04] border border-white/[0.08] rounded-lg text-white placeholder-white/40 focus:outline-none focus:border-[var(--brand-primary)] transition-colors duration-500"
                        readOnly
                        aria-label="Password"
                    />
                    <button
                        type="submit"
                        className="w-full py-3 px-6 bg-white/[0.08] border border-white/[0.08] text-white font-medium rounded-lg hover:bg-white/[0.12] hover:border-[var(--brand-primary)] transition-all duration-500 ease-in-out"
                    >
                        Enter Workspace
                    </button>
                </form>
            </div>
        </CinematicLayout>
    )
}
