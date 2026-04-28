/**
 * Consistent page/section title + one-line description + optional "What this affects" line.
 */
export default function SettingsSectionIntro({ title, description, affects, className = '' }) {
    return (
        <header className={`max-w-2xl ${className}`.trim()}>
            <h2 className="text-xl font-semibold tracking-tight text-slate-900">{title}</h2>
            {description && <p className="mt-2 text-sm text-slate-600 leading-relaxed">{description}</p>}
            {affects && (
                <p className="mt-2 text-xs text-slate-500 leading-relaxed">
                    <span className="font-medium text-slate-600">Affects:</span> {affects}
                </p>
            )}
        </header>
    )
}
