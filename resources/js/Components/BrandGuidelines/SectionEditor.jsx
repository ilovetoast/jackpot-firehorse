import { useMemo } from 'react'
import { useSidebarEditor } from './SidebarEditorContext'
import ToggleControl from './controls/ToggleControl'
import BackgroundControl from './controls/BackgroundControl'
import SelectControl from './controls/SelectControl'
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

    return (
        <div className="space-y-1">
            <span className="text-xs text-gray-500 font-medium">{field.label}</span>
            <div className="border border-gray-200 rounded-md p-2 bg-white">
                <WysiwygField
                    value={value}
                    onChange={(html) => ctx.updateContent(sectionId, field.key, html)}
                    placeholder={`Edit ${field.label.toLowerCase()}...`}
                />
            </div>
            {ctx.editMode === 'content' && (
                <p className="text-[10px] text-amber-600">Edits will update Brand DNA</p>
            )}
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
        editableFields: [],
    },
    'sec-typography': {
        contentToggles: [],
        editableFields: [],
    },
    'sec-logo': {
        contentToggles: [],
        editableFields: [],
    },
    'sec-logo-standards': {
        contentToggles: [
            { key: 'show_visual_treatments', label: 'Show Visual Treatments', default: true },
        ],
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
                                {ctx.editMode === 'content' ? 'Edit DNA' : 'Content Overrides'}
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
