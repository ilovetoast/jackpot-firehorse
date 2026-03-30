import { useState, useCallback } from 'react'
import { ChevronDownIcon } from '@heroicons/react/24/outline'
import { useSidebarEditor } from './SidebarEditorContext'
import SectionEditor from './SectionEditor'
import SelectControl from './controls/SelectControl'

/** Shared accordion surface: collapsed vs expanded (indigo matches app primary actions). */
function accordionSurface(expanded, muted) {
    if (expanded) {
        return 'border-indigo-200 bg-indigo-50/70 shadow-sm ring-1 ring-indigo-100/80'
    }
    if (muted) {
        return 'border-gray-200 bg-gray-50/90 hover:border-gray-300'
    }
    return 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50/50'
}

const PRESENTATION_STYLES = [
    { value: 'clean', label: 'Clean' },
    { value: 'bold', label: 'Bold' },
    { value: 'textured', label: 'Textured' },
]

const SPACING_OPTIONS = [
    { value: 'compact', label: 'Compact' },
    { value: 'default', label: 'Default' },
    { value: 'generous', label: 'Generous' },
]

const LABEL_STYLES = [
    { value: 'uppercase', label: 'UPPERCASE' },
    { value: 'titlecase', label: 'Title Case' },
    { value: 'lowercase', label: 'lowercase' },
]

const RADIUS_OPTIONS = [
    { value: 'none', label: 'None' },
    { value: 'sm', label: 'Small' },
    { value: 'md', label: 'Medium' },
    { value: 'lg', label: 'Large' },
]

export default function SidebarEditor({ sections = [] }) {
    const ctx = useSidebarEditor()
    const [globalOpen, setGlobalOpen] = useState(true)
    const [openSections, setOpenSections] = useState({})

    const toggleSection = useCallback((id) => {
        setOpenSections((prev) => ({ ...prev, [id]: !prev[id] }))
    }, [])

    if (!ctx?.isEditing) return null

    const scrollToSection = (id) => {
        const el = document.getElementById(id)
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }

    return (
        <div className="fixed top-0 right-0 bottom-0 w-[340px] bg-white border-l border-gray-200 shadow-2xl z-[60] flex flex-col transform transition-transform duration-300 ease-out"
            style={{ transform: ctx.isEditing ? 'translateX(0)' : 'translateX(100%)' }}
        >
            {/* Header */}
            <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-gray-50/80">
                <div className="flex items-center gap-2">
                    <h2 className="text-sm font-semibold text-gray-900">Customize</h2>
                    {ctx.saving && <span className="text-[10px] text-gray-400 animate-pulse">Saving...</span>}
                    {!ctx.saving && ctx.lastSaved && !ctx.hasUnsavedChanges && (
                        <span className="text-[10px] text-green-500">Saved</span>
                    )}
                    {ctx.saveError && <span className="text-[10px] text-red-500">{ctx.saveError}</span>}
                </div>
                <div className="flex items-center gap-2">
                    {/* Edit mode toggle */}
                    <div className="flex items-center bg-gray-100 rounded-md p-0.5">
                        <button
                            type="button"
                            onClick={ctx.switchToLayoutMode}
                            className={`px-2 py-0.5 text-[10px] font-medium rounded transition-colors ${ctx.editMode === 'layout' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
                        >
                            Layout
                        </button>
                        <button
                            type="button"
                            onClick={ctx.requestContentMode}
                            className={`px-2 py-0.5 text-[10px] font-medium rounded transition-colors ${ctx.editMode === 'content' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
                        >
                            Content
                        </button>
                    </div>
                    <button type="button" onClick={ctx.closeEditor} className="p-1 text-gray-400 hover:text-gray-600 rounded-md hover:bg-gray-100">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>

            {/* Body */}
            <div className="flex-1 overflow-y-auto px-3 py-3 space-y-2">
                {/* Global Settings */}
                <div className={`rounded-lg border transition-all duration-200 ${accordionSurface(globalOpen, false)}`}>
                    <button
                        type="button"
                        onClick={() => setGlobalOpen(!globalOpen)}
                        aria-expanded={globalOpen}
                        className="w-full flex items-center justify-between gap-2 px-3 py-2.5 text-left rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        <span
                            className={`text-xs font-semibold uppercase tracking-wider ${globalOpen ? 'text-indigo-900' : 'text-gray-600'}`}
                        >
                            Global Settings
                        </span>
                        <ChevronDownIcon
                            className={`h-4 w-4 flex-shrink-0 transition-transform duration-200 ${globalOpen ? 'rotate-180 text-indigo-600' : 'text-gray-400'}`}
                            aria-hidden
                        />
                    </button>
                    {globalOpen && (
                        <div className="px-3 pb-3 pt-3 space-y-3 border-t border-indigo-100/90">
                            <SelectControl
                                label="Style"
                                value={ctx.draftPresentation?.style || 'clean'}
                                onChange={ctx.updatePresentationStyle}
                                options={PRESENTATION_STYLES}
                            />
                            <SelectControl
                                label="Spacing"
                                value={ctx.draftOverrides?.global?.spacing || 'default'}
                                onChange={(v) => ctx.updateOverride('global', 'spacing', v)}
                                options={SPACING_OPTIONS}
                            />
                            <SelectControl
                                label="Labels"
                                value={ctx.draftOverrides?.global?.section_label_style || 'uppercase'}
                                onChange={(v) => ctx.updateOverride('global', 'section_label_style', v)}
                                options={LABEL_STYLES}
                            />
                            <SelectControl
                                label="Corners"
                                value={ctx.draftOverrides?.global?.corner_radius || 'md'}
                                onChange={(v) => ctx.updateOverride('global', 'corner_radius', v)}
                                options={RADIUS_OPTIONS}
                            />
                        </div>
                    )}
                </div>

                {/* Section Editors */}
                {sections.map((sec) => {
                    const sectionHidden = ctx.draftOverrides?.sections?.[sec.id]?.visible === false
                    const isOpen = !!openSections[sec.id]
                    const surface = accordionSurface(isOpen, sectionHidden && !isOpen)
                    return (
                        <div key={sec.id} className={`rounded-lg border transition-all duration-200 ${surface}`}>
                            <button
                                type="button"
                                onClick={() => {
                                    toggleSection(sec.id)
                                    scrollToSection(sec.id)
                                }}
                                aria-expanded={isOpen}
                                className="w-full flex items-center justify-between gap-2 px-3 py-2.5 text-left rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                            >
                                <span className="truncate flex items-center gap-2 min-w-0">
                                    <span
                                        className={`text-xs font-medium ${isOpen ? 'text-indigo-950' : sectionHidden ? 'text-gray-600' : 'text-gray-800'}`}
                                    >
                                        {sec.label}
                                    </span>
                                    {sectionHidden && (
                                        <span className="flex-shrink-0 rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-amber-800 bg-amber-100 ring-1 ring-amber-200/80">
                                            Hidden
                                        </span>
                                    )}
                                </span>
                                <ChevronDownIcon
                                    className={`h-4 w-4 flex-shrink-0 transition-transform duration-200 ${isOpen ? 'rotate-180 text-indigo-600' : 'text-gray-400'}`}
                                    aria-hidden
                                />
                            </button>
                            {isOpen && (
                                <div className="px-3 pb-3 pt-3 border-t border-indigo-100/90">
                                    <SectionEditor sectionId={sec.id} sectionConfig={sec} />
                                </div>
                            )}
                        </div>
                    )
                })}
            </div>

            {/* Footer */}
            <div className="border-t border-gray-100 px-4 py-3 flex items-center justify-between bg-gray-50/80">
                <button
                    type="button"
                    onClick={ctx.resetAll}
                    className="text-xs text-gray-400 hover:text-red-500 font-medium"
                >
                    Reset All
                </button>
                <button
                    type="button"
                    onClick={ctx.saveNow}
                    disabled={ctx.saving || !ctx.hasUnsavedChanges}
                    className="px-3 py-1.5 text-xs font-semibold text-white bg-indigo-500 rounded-md hover:bg-indigo-600 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                >
                    {ctx.saving ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    )
}
