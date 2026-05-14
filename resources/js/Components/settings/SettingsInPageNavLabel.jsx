/**
 * Small caps label for sticky in-page section nav (Brand Settings, campaign identity, etc.).
 * Keeps copy and styling consistent wherever we show anchor / section sidebars.
 */
export default function SettingsInPageNavLabel({ children = 'On this page' }) {
    return (
        <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-3 px-3">
            {children}
        </p>
    )
}
