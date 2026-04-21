import { useMemo } from 'react'
import { useSidebarEditor } from './SidebarEditorContext'
import ToggleControl from './controls/ToggleControl'
import BackgroundControl from './controls/BackgroundControl'
import SelectControl from './controls/SelectControl'
import HeroLogoOverridePicker from './HeroLogoOverridePicker'
import ColorPickerControl from './controls/ColorPickerControl'
import WysiwygField from './WysiwygField'

const TITLE_SIZE_OPTIONS = [
    { value: 'sm', label: 'Small' },
    { value: 'md', label: 'Medium' },
    { value: 'lg', label: 'Large' },
    { value: 'xl', label: 'Extra Large' },
]

const TITLE_STYLE_OPTIONS = [
    { value: 'default', label: 'Default' },
    { value: 'uppercase', label: 'Uppercase' },
    { value: 'normal', label: 'Normal Case' },
]

const MISSION_STYLE_OPTIONS = [
    { value: 'quote', label: 'Quote' },
    { value: 'plain', label: 'Plain' },
    { value: 'highlight', label: 'Highlight' },
]

function unwrapValue(field) {
    if (field && typeof field === 'object' && !Array.isArray(field) && 'value' in field) return field.value
    return field
}

/** Reads `allowed_color_palette` from Brand DNA and lets users hide/show individual swatches on the guidelines page. */
function PaletteManager({ ctx, sectionId }) {
    const rawPalette = unwrapValue(ctx.modelPayload?.scoring_rules?.allowed_color_palette) ?? []
    const palette = useMemo(() => {
        return (rawPalette || [])
            .map((c) => {
                if (typeof c === 'string') return { hex: c, role: null }
                if (c && typeof c === 'object') {
                    const hex = typeof c.hex === 'string' ? c.hex : (typeof c.value === 'string' ? c.value : null)
                    const role = typeof c.role === 'string' ? c.role : null
                    return hex ? { hex, role } : null
                }
                return null
            })
            .filter((c) => c && typeof c.hex === 'string' && c.hex.startsWith('#'))
    }, [rawPalette])

    if (palette.length === 0) return null

    const hiddenList = ctx.draftOverrides?.sections?.[sectionId]?.content?.hidden_palette_colors ?? []
    const hiddenSet = new Set(hiddenList.map((h) => String(h).toLowerCase()))

    const toggleColor = (hex) => {
        const key = hex.toLowerCase()
        const next = hiddenSet.has(key)
            ? hiddenList.filter((h) => String(h).toLowerCase() !== key)
            : [...hiddenList, hex]
        ctx.updateOverride(sectionId, 'content.hidden_palette_colors', next)
    }

    const showAll = () => {
        ctx.updateOverride(sectionId, 'content.hidden_palette_colors', [])
    }

    return (
        <div className="space-y-1.5 pt-1">
            <div className="flex items-center justify-between">
                <span className="text-[10px] text-gray-500 font-medium">Extended palette ({palette.length - hiddenSet.size}/{palette.length} shown)</span>
                {hiddenSet.size > 0 && (
                    <button
                        type="button"
                        onClick={showAll}
                        className="text-[10px] text-indigo-600 hover:text-indigo-800 font-medium"
                    >
                        Show all
                    </button>
                )}
            </div>
            <p className="text-[10px] text-gray-400 leading-snug">Click a swatch to hide colors that don't align with your brand. Brand DNA is unchanged.</p>
            <div className="flex flex-wrap gap-1">
                {palette.map((c, i) => {
                    const isHidden = hiddenSet.has(c.hex.toLowerCase())
                    return (
                        <button
                            key={`${c.hex}-${i}`}
                            type="button"
                            title={`${c.hex}${c.role ? ` (${c.role})` : ''}${isHidden ? ' — hidden' : ''}`}
                            onClick={() => toggleColor(c.hex)}
                            className={`relative h-7 w-7 rounded-md border transition-all ${isHidden ? 'border-gray-300 opacity-40' : 'border-gray-300 hover:ring-2 hover:ring-indigo-300'}`}
                            style={{ backgroundColor: c.hex }}
                        >
                            {isHidden && (
                                <svg className="absolute inset-0 m-auto h-3.5 w-3.5 text-white mix-blend-difference" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            )}
                        </button>
                    )
                })}
            </div>
        </div>
    )
}

/** Walk model_payload by dot path (e.g. identity.mission) and unwrap { value } leaves. */
function getDnaAtPath(modelPayload, path) {
    if (!modelPayload || !path) return undefined
    const parts = path.split('.')
    let cur = modelPayload
    for (const p of parts) {
        if (cur == null) return undefined
        cur = cur[p]
    }
    return unwrapValue(cur)
}

/** TipTap expects HTML; DNA strings are usually plain text from the builder. */
function toEditorHtml(value) {
    if (value == null || value === '') return ''
    const s = typeof value === 'string' ? value : String(value)
    const t = s.trim()
    if (!t) return ''
    if (/<\/?[a-z][\s\S]*>/i.test(t)) return t
    const escaped = t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    return `<p>${escaped}</p>`
}

/**
 * Same merge as the main preview: presentation_content override, else DNA (guidelines page).
 */
function getWysiwygValue(draftContent, modelPayload, sectionId, field) {
    const override = draftContent?.[sectionId]?.[field.key]
    const overrideIsSet = override && override !== '<p></p>' && String(override).trim() !== ''
    if (overrideIsSet) return override
    if (!field.dnaPath) return ''
    const dna = getDnaAtPath(modelPayload, field.dnaPath)
    if (dna == null || dna === '') return ''
    return toEditorHtml(dna)
}

function EditableWysiwygField({ ctx, sectionId, field, modelPayload }) {
    const value = useMemo(
        () => getWysiwygValue(ctx.draftContent, modelPayload, sectionId, field),
        [ctx.draftContent, modelPayload, sectionId, field.key, field.dnaPath],
    )

    const hasOverride = !!ctx.draftContent?.[sectionId]?.[field.key]

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs text-gray-500 font-medium">{field.label}</span>
                {hasOverride && (
                    <button
                        type="button"
                        onClick={() => ctx.updateContent(sectionId, field.key, '')}
                        className="text-[10px] text-gray-400 hover:text-red-500"
                        title="Revert to Brand DNA value"
                    >
                        Reset
                    </button>
                )}
            </div>
            <div className="border border-gray-200 rounded-md p-2 bg-white">
                <WysiwygField
                    value={value}
                    onChange={(html) => ctx.updateContent(sectionId, field.key, html)}
                    placeholder={`Edit ${field.label.toLowerCase()}...`}
                />
            </div>
            <p className="text-[10px] text-gray-400">Override only — Brand DNA unchanged</p>
        </div>
    )
}

/** Override the swatch backgrounds used in the Brand Identity showcase without touching DNA colors. */
function IdentityColorOverrides({ ctx, sectionId }) {
    const brand = ctx.brand || {}
    const overrides = ctx.draftOverrides?.sections?.[sectionId]?.content ?? {}

    const rows = [
        { key: 'primary_bg', label: 'Primary BG', fallback: brand.primary_color || '#6366f1' },
        { key: 'reversed_bg', label: 'Reversed BG', fallback: brand.secondary_color || '#8b5cf6' },
        { key: 'accent_bg', label: 'Accent BG', fallback: brand.accent_color || '#06b6d4' },
    ]

    return (
        <div className="space-y-2 pt-1">
            <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Showcase Colors</div>
            {rows.map((row) => {
                const custom = overrides[row.key]
                return (
                    <div key={row.key} className="flex items-center justify-between gap-2">
                        <span className="text-xs text-gray-500 font-medium shrink-0">{row.label}</span>
                        <div className="flex items-center gap-1.5">
                            {custom && (
                                <button
                                    type="button"
                                    onClick={() => ctx.updateOverride(sectionId, `content.${row.key}`, null)}
                                    className="text-[10px] text-gray-400 hover:text-red-500"
                                    title="Revert to brand color"
                                >
                                    Reset
                                </button>
                            )}
                            <ColorPickerControl
                                hideLabel
                                value={custom || row.fallback}
                                onChange={(v) => ctx.updateOverride(sectionId, `content.${row.key}`, v)}
                            />
                        </div>
                    </div>
                )
            })}
            <p className="text-[10px] text-gray-400 leading-snug">Overrides how each logo showcase tile is colored — Brand DNA colors remain unchanged.</p>
        </div>
    )
}

/** Link to the brand settings page so users can edit Logo Standards copy in one place. */
function LogoStandardsLinks({ ctx }) {
    if (!ctx.brandId) return null
    const brandSettingsUrl = typeof window.route === 'function'
        ? window.route('brands.edit', { brand: ctx.brandId })
        : `/app/brands/${ctx.brandId}/edit`

    return (
        <div className="space-y-1 pt-1">
            <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Guideline Copy</div>
            <a
                href={`${brandSettingsUrl}#logo-guidelines`}
                className="inline-flex items-center gap-1 text-[11px] text-indigo-600 hover:text-indigo-800 font-medium"
            >
                Edit Do / Don't copy in Brand Settings
                <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
            </a>
        </div>
    )
}

const SECTION_CONFIGS = {
    'sec-hero': {
        contentToggles: [
            { key: 'show_logo', label: 'Show Logo', default: true },
            { key: 'show_tagline', label: 'Show Tagline', default: true },
            { key: 'show_color_dots', label: 'Show Color Dots', default: true },
        ],
        textControls: true,
        extraControls: () => <HeroLogoOverridePicker />,
        editableFields: [],
    },
    'sec-purpose': {
        contentToggles: [
            { key: 'show_industry', label: 'Show Industry', default: true },
            { key: 'show_audience', label: 'Show Audience', default: true },
            { key: 'show_tagline', label: 'Show Tagline', default: true },
        ],
        extraControls: (ctx, sectionId) => (
            <SelectControl
                label="Mission Style"
                value={ctx.draftOverrides?.sections?.[sectionId]?.content?.mission_style || 'quote'}
                onChange={(v) => ctx.updateOverride(sectionId, 'content.mission_style', v)}
                options={MISSION_STYLE_OPTIONS}
            />
        ),
        editableFields: [
            { key: 'mission_html', label: 'Mission', dnaPath: 'identity.mission' },
            { key: 'positioning_html', label: 'Positioning', dnaPath: 'identity.positioning' },
        ],
    },
    'sec-values': {
        contentToggles: [],
        editableFields: [],
    },
    'sec-voice': {
        contentToggles: [],
        editableFields: [
            { key: 'voice_html', label: 'Brand Voice', dnaPath: 'personality.voice_description' },
        ],
    },
    'sec-archetype': {
        contentToggles: [
            { key: 'show_traits', label: 'Show Traits', default: true },
            { key: 'show_brand_look', label: 'Show Brand Look', default: true },
        ],
        editableFields: [
            { key: 'brand_look_html', label: 'Brand Look', dnaPath: 'personality.brand_look' },
        ],
    },
    'sec-visual': {
        contentToggles: [
            { key: 'show_attributes', label: 'Show Attributes', default: true },
        ],
        editableFields: [],
    },
    'sec-photography': {
        contentToggles: [],
        editableFields: [],
    },
    'sec-colors': {
        contentToggles: [
            { key: 'show_extended_palette', label: 'Show Extended Palette', default: true },
        ],
        extraControls: (ctx, sectionId) => <PaletteManager ctx={ctx} sectionId={sectionId} />,
        editableFields: [],
    },
    'sec-typography': {
        contentToggles: [],
        editableFields: [],
    },
    'sec-logo': {
        contentToggles: [
            { key: 'show_secondary_marks', label: 'Show Secondary Marks', default: true },
            { key: 'show_small_variants', label: 'Show Small Variants Row', default: true },
        ],
        extraControls: (ctx, sectionId) => <IdentityColorOverrides ctx={ctx} sectionId={sectionId} />,
        editableFields: [],
    },
    'sec-logo-standards': {
        contentToggles: [
            { key: 'show_visual_treatments', label: 'Show Visual Treatments', default: true },
            { key: 'show_best_practices', label: 'Show Best Practices', default: true },
            { key: 'show_avoid_section', label: 'Show "Avoid" Section', default: true },
        ],
        extraControls: (ctx) => <LogoStandardsLinks ctx={ctx} />,
        editableFields: [],
    },
}

export default function SectionEditor({ sectionId, sectionConfig }) {
    const ctx = useSidebarEditor()
    if (!ctx) return null

    const config = SECTION_CONFIGS[sectionId] || {}
    const modelPayload = ctx.modelPayload ?? {}
    const sectionOverrides = ctx.draftOverrides?.sections?.[sectionId] ?? {}
    const visible = sectionOverrides.visible !== false

    const updateField = (path, value) => {
        ctx.updateOverride(sectionId, path, value)
    }

    return (
        <div className="space-y-3">
            <ToggleControl
                label="Visible"
                value={visible}
                onChange={(v) => updateField('visible', v)}
            />

            {visible && (
                <>
                    <BackgroundControl
                        background={sectionOverrides.background || {}}
                        onChange={(bg) => updateField('background', bg)}
                        presetImages={ctx.backgroundImagePresets ?? []}
                        presentationStyle={ctx.draftPresentation?.style || 'clean'}
                        brandId={ctx.brandId ?? null}
                    />

                    {config.textControls && (
                        <div className="space-y-2 pt-1">
                            <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Text</div>
                            <SelectControl
                                label="Title Size"
                                value={sectionOverrides.text?.title_size || 'lg'}
                                onChange={(v) => updateField('text.title_size', v)}
                                options={TITLE_SIZE_OPTIONS}
                            />
                            <SelectControl
                                label="Title Style"
                                value={sectionOverrides.text?.title_style || 'default'}
                                onChange={(v) => updateField('text.title_style', v)}
                                options={TITLE_STYLE_OPTIONS}
                            />
                        </div>
                    )}

                    {(config.contentToggles?.length > 0 || config.extraControls) && (
                        <div className="space-y-2 pt-1">
                            <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Content</div>
                            {config.contentToggles?.map((toggle) => (
                                <ToggleControl
                                    key={toggle.key}
                                    label={toggle.label}
                                    value={sectionOverrides.content?.[toggle.key] ?? toggle.default}
                                    onChange={(v) => updateField(`content.${toggle.key}`, v)}
                                />
                            ))}
                            {config.extraControls?.(ctx, sectionId)}
                        </div>
                    )}

                    {config.editableFields?.length > 0 && (
                        <div className="space-y-3 pt-1">
                            <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">
                                Content Overrides
                            </div>
                            {config.editableFields.map((field) => (
                                <EditableWysiwygField
                                    key={field.key}
                                    ctx={ctx}
                                    sectionId={sectionId}
                                    field={field}
                                    modelPayload={modelPayload}
                                />
                            ))}
                        </div>
                    )}

                    <div className="pt-2 flex justify-end">
                        <button
                            type="button"
                            onClick={() => ctx.resetSection(sectionId)}
                            className="text-[10px] text-gray-400 hover:text-red-500 font-medium"
                        >
                            Reset Section
                        </button>
                    </div>
                </>
            )}
        </div>
    )
}
