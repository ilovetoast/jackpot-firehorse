import CinematicLayout from '../../Layouts/CinematicLayout'

export default function WorkspaceSelect({ brand, onSelectBrandGuidelines, onSelectDAM }) {
    return (
        <CinematicLayout brand={brand}>
            <div className="min-h-screen flex flex-col items-center justify-center px-6 py-16">
                <h1 className="text-[56px] md:text-[96px] font-light tracking-tight text-white/95 mb-4">
                    Choose Workspace
                </h1>
                <p className="text-lg text-white/65 mb-16">
                    Select where you want to go
                </p>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6 w-full max-w-2xl">
                    <button
                        type="button"
                        onClick={onSelectDAM}
                        className="group relative overflow-hidden rounded-lg border border-white/[0.08] bg-white/[0.04] p-8 text-left transition-all duration-500 ease-in-out hover:border-[var(--brand-primary)] hover:bg-white/[0.06] hover:translate-y-[-2px]"
                    >
                        <h2 className="text-2xl font-medium text-white mb-2">
                            Digital Asset Management
                        </h2>
                        <p className="text-sm text-white/65">
                            Organize. Govern. Distribute.
                        </p>
                        <div
                            className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"
                            style={{
                                background: `radial-gradient(circle at 50% 50%, var(--brand-primary)15 0%, transparent 70%)`,
                            }}
                        />
                    </button>
                    <button
                        type="button"
                        onClick={onSelectBrandGuidelines}
                        className="group relative overflow-hidden rounded-lg border border-white/[0.08] bg-white/[0.04] p-8 text-left transition-all duration-500 ease-in-out hover:border-[var(--brand-primary)] hover:bg-white/[0.06] hover:translate-y-[-2px]"
                    >
                        <h2 className="text-2xl font-medium text-white mb-2">
                            Brand Guidelines
                        </h2>
                        <p className="text-sm text-white/65">
                            Identity. Voice. Standards.
                        </p>
                        <div
                            className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"
                            style={{
                                background: `radial-gradient(circle at 50% 50%, var(--brand-primary)15 0%, transparent 70%)`,
                            }}
                        />
                    </button>
                </div>
            </div>
        </CinematicLayout>
    )
}
