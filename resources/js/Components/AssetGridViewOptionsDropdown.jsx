/**
 * Single control for tile size, grid/masonry layout, and show-info toggle.
 * Use as a Popover (default) or panelOnly when nested inside AssetGridViewMenu / mobile sheet.
 */
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react'
import { ViewColumnsIcon, InformationCircleIcon } from '@heroicons/react/24/outline'

const SIZE_PRESETS = [160, 220, 280, 360]
const SIZE_LABELS = ['Compact', 'Default', 'Cozy', 'Spacious']
const SIZE_ICON_KEYS = ['small', 'medium', 'large', 'xlarge']

function snapToPreset(value) {
    return SIZE_PRESETS.reduce((prev, curr) => (Math.abs(curr - value) < Math.abs(prev - value) ? curr : prev))
}

function SizeIcon({ size, className = 'h-4 w-4' }) {
    const gridPatterns = {
        small: (
            <svg className={className} fill="none" viewBox="0 0 28 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
                <rect x="1" y="1" width="4" height="6" rx="0.5" />
                <rect x="6" y="1" width="4" height="6" rx="0.5" />
                <rect x="11" y="1" width="4" height="6" rx="0.5" />
                <rect x="16" y="1" width="4" height="6" rx="0.5" />
                <rect x="21" y="1" width="4" height="6" rx="0.5" />
                <rect x="1" y="9" width="4" height="6" rx="0.5" />
                <rect x="6" y="9" width="4" height="6" rx="0.5" />
                <rect x="11" y="9" width="4" height="6" rx="0.5" />
                <rect x="16" y="9" width="4" height="6" rx="0.5" />
                <rect x="21" y="9" width="4" height="6" rx="0.5" />
            </svg>
        ),
        medium: (
            <svg className={className} fill="none" viewBox="0 0 24 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
                <rect x="1" y="1" width="4.5" height="6" rx="0.5" />
                <rect x="6.5" y="1" width="4.5" height="6" rx="0.5" />
                <rect x="12" y="1" width="4.5" height="6" rx="0.5" />
                <rect x="17.5" y="1" width="4.5" height="6" rx="0.5" />
                <rect x="1" y="9" width="4.5" height="6" rx="0.5" />
                <rect x="6.5" y="9" width="4.5" height="6" rx="0.5" />
                <rect x="12" y="9" width="4.5" height="6" rx="0.5" />
                <rect x="17.5" y="9" width="4.5" height="6" rx="0.5" />
            </svg>
        ),
        large: (
            <svg className={className} fill="none" viewBox="0 0 20 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
                <rect x="1" y="1" width="5" height="6" rx="0.5" />
                <rect x="7.5" y="1" width="5" height="6" rx="0.5" />
                <rect x="14" y="1" width="5" height="6" rx="0.5" />
                <rect x="1" y="9" width="5" height="6" rx="0.5" />
                <rect x="7.5" y="9" width="5" height="6" rx="0.5" />
                <rect x="14" y="9" width="5" height="6" rx="0.5" />
            </svg>
        ),
        xlarge: (
            <svg className={className} fill="none" viewBox="0 0 16 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
                <rect x="1" y="1" width="6" height="6" rx="0.5" />
                <rect x="9" y="1" width="6" height="6" rx="0.5" />
                <rect x="1" y="9" width="6" height="6" rx="0.5" />
                <rect x="9" y="9" width="6" height="6" rx="0.5" />
            </svg>
        ),
    }
    return gridPatterns[size] || gridPatterns.medium
}

function LayoutUniformIcon({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 20 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
            <rect x="1" y="1" width="5" height="6" rx="0.5" />
            <rect x="7.5" y="1" width="5" height="6" rx="0.5" />
            <rect x="14" y="1" width="5" height="6" rx="0.5" />
            <rect x="1" y="9" width="5" height="6" rx="0.5" />
            <rect x="7.5" y="9" width="5" height="6" rx="0.5" />
            <rect x="14" y="9" width="5" height="6" rx="0.5" />
        </svg>
    )
}

function LayoutMasonryIcon({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 20 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
            <rect x="1" y="1" width="5.5" height="4" rx="0.5" />
            <rect x="8" y="1" width="5.5" height="7" rx="0.5" />
            <rect x="14.5" y="1" width="4.5" height="5" rx="0.5" />
            <rect x="1" y="6" width="5.5" height="9" rx="0.5" />
            <rect x="8" y="9" width="5.5" height="6" rx="0.5" />
            <rect x="14.5" y="7" width="4.5" height="8" rx="0.5" />
        </svg>
    )
}

const panelClass =
    'z-[210] w-[min(calc(100vw-1.5rem),18rem)] [--anchor-gap:6px] rounded-xl border border-gray-200 bg-white/95 p-3 shadow-2xl ring-1 ring-black/5 backdrop-blur-md motion-safe:transition motion-safe:duration-200 motion-safe:ease-out data-[closed]:opacity-0 motion-reduce:transition-none motion-reduce:data-[closed]:opacity-100'

/**
 * @param {'full'|'panel'} props.mode - full = Popover+trigger; panel = inner content only
 * @param {'default'|'icon'} props.triggerVariant
 */
export default function AssetGridViewOptionsDropdown({
    mode = 'full',
    triggerVariant = 'default',
    cardSize = 220,
    onCardSizeChange = () => {},
    layoutMode = 'grid',
    onLayoutModeChange = () => {},
    showInfo = true,
    onToggleInfo = () => {},
    primaryColor = '#6366f1',
    className = '',
}) {
    const currentPresetIndex = SIZE_PRESETS.indexOf(snapToPreset(cardSize))
    const idx = currentPresetIndex >= 0 ? currentPresetIndex : 1
    const sizeLabel = SIZE_LABELS[idx] ?? 'Default'
    const layoutLabel = layoutMode === 'masonry' ? 'Masonry' : 'Grid'
    const triggerSubtitle = `${sizeLabel} · ${layoutLabel}`

    const panelInner = (
        <div className="flex flex-col gap-3">
            <div>
                <p className="mb-2 text-[10px] font-semibold uppercase tracking-wide text-gray-500">Tile size</p>
                <div className="flex flex-col gap-1">
                    {SIZE_PRESETS.map((size, index) => {
                        const selected = index === idx
                        const iconKey = SIZE_ICON_KEYS[index] || 'medium'
                        return (
                            <button
                                key={size}
                                type="button"
                                onClick={() => onCardSizeChange(size)}
                                className={`flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition-colors ${
                                    selected ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-700 hover:bg-gray-50'
                                }`}
                                style={selected ? { boxShadow: `inset 0 0 0 1px ${primaryColor}55` } : undefined}
                                aria-pressed={selected}
                            >
                                <SizeIcon size={iconKey} className="h-4 w-4 shrink-0 text-gray-500" />
                                <span>{SIZE_LABELS[index]}</span>
                            </button>
                        )
                    })}
                </div>
            </div>

            <div className="border-t border-gray-100 pt-2">
                <p className="mb-2 text-[10px] font-semibold uppercase tracking-wide text-gray-500">Layout</p>
                <div className="flex flex-col gap-1">
                    <button
                        type="button"
                        onClick={() => onLayoutModeChange('grid')}
                        className={`flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition-colors ${
                            layoutMode === 'grid'
                                ? 'bg-gray-50 font-medium text-gray-900'
                                : 'text-gray-700 hover:bg-gray-50'
                        }`}
                        style={layoutMode === 'grid' ? { boxShadow: `inset 0 0 0 1px ${primaryColor}55` } : undefined}
                        aria-pressed={layoutMode === 'grid'}
                    >
                        <LayoutUniformIcon className="h-4 w-4 shrink-0 text-gray-500" />
                        <span>Uniform grid</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => onLayoutModeChange('masonry')}
                        className={`flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition-colors ${
                            layoutMode === 'masonry'
                                ? 'bg-gray-50 font-medium text-gray-900'
                                : 'text-gray-700 hover:bg-gray-50'
                        }`}
                        style={
                            layoutMode === 'masonry' ? { boxShadow: `inset 0 0 0 1px ${primaryColor}55` } : undefined
                        }
                        aria-pressed={layoutMode === 'masonry'}
                    >
                        <LayoutMasonryIcon className="h-4 w-4 shrink-0 text-gray-500" />
                        <span>Masonry</span>
                    </button>
                </div>
            </div>

            <div className="border-t border-gray-100 pt-2">
                <div className="flex items-center justify-between gap-3 rounded-lg px-1 py-1">
                    <span className="flex items-center gap-2 text-sm text-gray-700">
                        <InformationCircleIcon className="h-4 w-4 shrink-0 text-gray-500" aria-hidden />
                        Show titles and types
                    </span>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={showInfo}
                        onClick={onToggleInfo}
                        className="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-1 motion-reduce:transition-none"
                        style={{
                            backgroundColor: showInfo ? primaryColor : '#d1d5db',
                            '--tw-ring-color': primaryColor,
                        }}
                    >
                        <span
                            className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform duration-200 ease-in-out motion-reduce:transition-none ${
                                showInfo ? 'translate-x-5' : 'translate-x-0'
                            }`}
                        />
                    </button>
                </div>
            </div>
        </div>
    )

    if (mode === 'panel') {
        return <div className={className}>{panelInner}</div>
    }

    const triggerBtn =
        triggerVariant === 'icon' ? (
            <PopoverButton
                type="button"
                className={`inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset motion-safe:transition-colors motion-safe:duration-200 motion-reduce:transition-none ${className}`}
                style={{ '--tw-ring-color': primaryColor }}
                aria-label={`View: ${triggerSubtitle}`}
                title={triggerSubtitle}
            >
                <ViewColumnsIcon className="h-4 w-4 shrink-0" aria-hidden />
            </PopoverButton>
        ) : (
            <PopoverButton
                type="button"
                className={`inline-flex h-9 min-w-0 max-w-[11rem] shrink-0 items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset motion-safe:transition-colors motion-safe:duration-200 motion-reduce:transition-none sm:max-w-[14rem] ${className}`}
                style={{ '--tw-ring-color': primaryColor }}
                aria-label={`View options: ${triggerSubtitle}`}
            >
                <ViewColumnsIcon className="h-4 w-4 shrink-0 text-gray-500" aria-hidden />
                <span className="hidden sm:inline">View</span>
                <span className="min-w-0 truncate text-xs font-normal text-gray-500 sm:max-w-[6.5rem]">
                    {triggerSubtitle}
                </span>
            </PopoverButton>
        )

    return (
        <Popover className={`relative ${triggerVariant === 'icon' ? 'shrink-0' : ''}`}>
            {triggerBtn}
            <PopoverPanel transition anchor="bottom end" className={panelClass}>
                {panelInner}
            </PopoverPanel>
        </Popover>
    )
}
