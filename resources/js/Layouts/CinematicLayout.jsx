import FilmGrainOverlay from '../Components/FilmGrainOverlay'

/**
 * CinematicLayout - Full viewport dark layout for Brand Operating System experience
 * No sidebar, no AppLayout. Injects CSS variables for brand theming.
 */
export default function CinematicLayout({ children, brand }) {
    const primary = brand?.primaryColor ?? '#0A1F44'
    const secondary = brand?.secondaryColor ?? '#B4975A'

    return (
        <div
            className="min-h-screen bg-[#0B0B0D] text-white"
            style={{
                '--brand-primary': primary,
                '--brand-secondary': secondary,
                background: `
                    radial-gradient(ellipse 80% 50% at 50% 0%, ${primary}22 0%, transparent 50%),
                    #0B0B0D
                `,
            }}
        >
            {children}
            <FilmGrainOverlay />
        </div>
    )
}
