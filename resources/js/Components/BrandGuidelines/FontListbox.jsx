/**
 * Font selector using Headless UI Listbox.
 * Dark dropdown with font preview in options.
 */
import { Listbox, Transition } from '@headlessui/react'
import { ChevronUpDownIcon, CheckIcon } from '@heroicons/react/24/outline'

const FONT_OPTIONS = ['Inter', 'Georgia', 'Helvetica', 'Arial', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins']

export default function FontListbox({ value, onChange, options = [], label, placeholder = '— Select —' }) {
    const allFonts = [...new Set([...options, ...FONT_OPTIONS])]
    const displayValue = value || placeholder

    return (
        <div>
            <label className="block text-xs text-white/60 mb-1">{label}</label>
            <Listbox value={value || ''} onChange={(v) => onChange(v || null)}>
                <div className="relative">
                    <Listbox.Button className="relative w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-left text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30 focus:border-white/30">
                        <span
                            className="block truncate"
                            style={value ? { fontFamily: `${value}, system-ui, sans-serif` } : {}}
                        >
                            {displayValue}
                        </span>
                        <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                            <ChevronUpDownIcon className="h-5 w-5 text-white/50" aria-hidden="true" />
                        </span>
                    </Listbox.Button>
                    <Transition
                        leave="transition ease-in duration-100"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                        className="absolute z-10 mt-1 w-full rounded-lg border border-white/20 bg-[#1a1920] shadow-xl"
                    >
                        <Listbox.Options className="max-h-60 overflow-auto py-1 focus:outline-none">
                            <Listbox.Option
                                value=""
                                className="relative cursor-pointer select-none py-2 pl-3 pr-9 text-white/70 hover:bg-white/10"
                            >
                                {({ selected }) => (
                                    <>
                                        <span className="block truncate">{placeholder}</span>
                                        {selected && (
                                            <span className="absolute inset-y-0 right-0 flex items-center pr-3">
                                                <CheckIcon className="h-5 w-5 text-indigo-400" aria-hidden="true" />
                                            </span>
                                        )}
                                    </>
                                )}
                            </Listbox.Option>
                            {allFonts.map((font) => (
                                <Listbox.Option
                                    key={font}
                                    value={font}
                                    className="relative cursor-pointer select-none py-2 pl-3 pr-9 text-white hover:bg-white/10"
                                >
                                    {({ selected }) => (
                                        <>
                                            <span
                                                className="block truncate"
                                                style={{ fontFamily: `${font}, system-ui, sans-serif` }}
                                            >
                                                {font}
                                            </span>
                                            {selected && (
                                                <span className="absolute inset-y-0 right-0 flex items-center pr-3">
                                                    <CheckIcon className="h-5 w-5 text-indigo-400" aria-hidden="true" />
                                                </span>
                                            )}
                                        </>
                                    )}
                                </Listbox.Option>
                            ))}
                        </Listbox.Options>
                    </Transition>
                </div>
            </Listbox>
        </div>
    )
}
