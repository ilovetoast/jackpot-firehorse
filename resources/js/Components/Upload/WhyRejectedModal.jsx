import React from 'react'
import { ShieldExclamationIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { getRegisteredTypesForHelp } from '../../utils/damFileTypes'

/**
 * Phase 7: Plain-English explainer for an `upload_blocked` rejection.
 *
 * The backend returns a structured error code + message (e.g.
 * `blocked_executable`, `blocked_double_extension`, `unsupported_type`,
 * `coming_soon_type`, `content_blocked_executable`). We map those codes
 * to a longer "why" + a shortlist of "what to do instead" suggestions
 * (often: "convert to PNG / PDF / share via Drive instead of zipping it").
 *
 * The component is dumb — pass the file name + error code + message and
 * it figures out the rest. Designed to be opened from anywhere a
 * rejection chip is shown (drag-drop area, queue row, batch summary toast).
 */
const POLICY = {
    blocked_executable: {
        title: 'Executables are blocked for safety',
        body:
            'Programs and scripts cannot be uploaded to brand asset libraries because they can run unwanted code on anyone who downloads them. This is enforced for every brand on Jackpot.',
        suggestions: [
            'If this is a packaged install, send it via a sharing link (e.g. Google Drive, Dropbox).',
            'If you need to deliver this to a customer, use a download bucket on a different page rather than the asset library.',
        ],
    },
    blocked_script: {
        title: 'Scripts and code files are blocked',
        body:
            'Source code, shell scripts, and macro-enabled documents are common phishing payloads. They cannot be uploaded as brand assets.',
        suggestions: [
            'If you intend to share read-only code, paste it into a Notion / Confluence / GitHub link instead.',
            'For documentation, export the file as PDF before uploading.',
        ],
    },
    blocked_archive: {
        title: 'Compressed archives are blocked',
        body:
            'ZIP, RAR, 7z and similar archives can hide unsafe content (scripts, executables, malware). Uploading the archive contents directly lets us validate every file individually.',
        suggestions: [
            'Extract the archive on your computer and drag the individual files into Jackpot.',
            'If you specifically need to deliver a packaged ZIP to a customer, use a separate download bucket — not the brand asset library.',
        ],
    },
    blocked_double_extension: {
        title: 'Filename has a suspicious double extension',
        body:
            'A filename like "logo.exe.png" is a classic trick to disguise an executable as an image. The actual extension Windows / macOS treats this file as is the LAST one (e.g. .exe), which is blocked.',
        suggestions: [
            'Rename the file so it has only one real extension that matches the file type (e.g. "logo.png").',
            'If the dot in the middle of the name is intentional (e.g. "v1.2.png"), this is fine — only blocked extensions before the final one trigger this rule.',
        ],
    },
    unsupported_type: {
        title: 'This file type isn’t in our brand asset registry',
        body:
            'Jackpot only ingests file types that fit a brand asset library — images, vectors, video, audio, design files, and a few documents. Anything outside that list is rejected at the door.',
        suggestions: [
            'If this is a graphic, export it as PNG, JPG, SVG, or PDF.',
            'If this is a document, export it as PDF.',
            'If this is a font file, use the Brand Guidelines section instead of a regular asset upload.',
        ],
    },
    coming_soon_type: {
        title: 'This file type is on the roadmap',
        body:
            'We don’t ingest this format yet, but it’s on the supported types list as "coming soon". Watch the changelog or ask your account manager for the timeline.',
        suggestions: [
            'For now, export to a currently supported format (e.g. PNG, JPG, MP4, PDF) and re-upload.',
        ],
    },
    invalid_filename: {
        title: 'The filename has invalid characters',
        body:
            'Control characters, slashes, and certain reserved names (NUL, CON, AUX, PRN, …) are stripped from filenames so the file can be safely stored across S3 and downloaded by every operating system.',
        suggestions: [
            'Rename the file using letters, numbers, dashes, underscores, dots, and spaces only.',
            'Avoid leading dots, trailing dots, and Windows reserved names like "CON" or "PRN".',
        ],
    },
    content_blocked_executable: {
        title: 'The file’s actual contents are an executable',
        body:
            'Even though the filename ended in an allowed extension, the bytes inside the file are an executable. The original file has already been deleted from our servers — nothing was ingested.',
        suggestions: [
            'If the file was renamed by mistake, restore the original extension and choose a different distribution method.',
            'If this was unexpected, scan your computer for malware — something may have replaced an asset on disk.',
        ],
    },
    file_size_limit: {
        title: 'The file exceeds your plan’s upload size limit',
        body:
            'Each plan caps single-file uploads to keep brand libraries snappy. This file is larger than your plan’s allowance.',
        suggestions: [
            'Compress / re-encode the file (e.g. lower video bitrate, smaller export resolution).',
            'Upgrade your workspace plan if you frequently upload files this large.',
        ],
    },
    plan_cap_exceeded: {
        title: 'The file exceeds your plan’s per-type cap',
        body:
            'Beyond the global upload cap, each plan also caps individual asset types (e.g. video). This file is larger than your plan’s cap for this type.',
        suggestions: [
            'Compress the file (lower bitrate, smaller resolution).',
            'Upgrade to a plan with higher per-type caps.',
        ],
    },
}

function fallbackPolicy(message) {
    return {
        title: 'This file was blocked',
        body:
            message ||
            'The upload pipeline rejected this file. The most common reasons are unsupported file types, blocked executables, or filenames with invalid characters.',
        suggestions: [
            'Check that the file type is in the supported list (Help → Supported file types).',
            'Re-export the file in a supported format if needed.',
        ],
    }
}

function policyForCode(code, message) {
    if (!code) return fallbackPolicy(message)
    if (POLICY[code]) return POLICY[code]
    // Map content-sniff codes (e.g. content_blocked_executable) to their
    // base equivalents so the operator-facing copy stays consistent.
    if (code.startsWith('content_')) {
        const base = code.slice('content_'.length)
        if (POLICY[base]) return POLICY[base]
    }
    return fallbackPolicy(message)
}

export default function WhyRejectedModal({
    open,
    onClose,
    fileName = '',
    errorCode = '',
    errorMessage = '',
    extension = '',
    primaryColor = '#f97316',
}) {
    if (!open) return null

    const policy = policyForCode(errorCode, errorMessage)

    // If the rejected extension corresponds to a "coming soon" type, surface
    // the registry record so the user sees its precise roadmap state.
    const helpData = getRegisteredTypesForHelp() || {}
    const comingSoon = (helpData.coming_soon || []).find(
        (entry) => Array.isArray(entry?.extensions) && entry.extensions.includes(extension.toLowerCase()),
    )

    return (
        <div
            className="fixed inset-0 z-[10100] flex items-center justify-center bg-black/55 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="why-rejected-title"
            onClick={onClose}
        >
            <div
                className="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl ring-1 ring-slate-900/10"
                onClick={(e) => e.stopPropagation()}
            >
                <button
                    type="button"
                    onClick={onClose}
                    className="absolute right-3 top-3 rounded-full p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                    aria-label="Close"
                >
                    <XMarkIcon className="h-5 w-5" />
                </button>

                <div className="flex items-start gap-3">
                    <div
                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                        style={{ backgroundColor: `${primaryColor}1f`, color: primaryColor }}
                    >
                        <ShieldExclamationIcon className="h-6 w-6" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h2 id="why-rejected-title" className="text-base font-semibold text-slate-900">
                            {policy.title}
                        </h2>
                        {fileName && (
                            <p className="mt-0.5 truncate text-xs text-slate-500" title={fileName}>
                                {fileName}
                            </p>
                        )}
                    </div>
                </div>

                <p className="mt-4 text-sm leading-relaxed text-slate-700">{policy.body}</p>

                {policy.suggestions && policy.suggestions.length > 0 && (
                    <div className="mt-4 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            What you can do
                        </p>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
                            {policy.suggestions.map((s, i) => (
                                <li key={i}>{s}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {comingSoon && (
                    <p className="mt-3 rounded-md bg-violet-50 px-3 py-2 text-xs text-violet-800 ring-1 ring-violet-100">
                        Roadmap note: <strong className="font-semibold">{comingSoon.label}</strong>{' '}
                        {comingSoon.note ? `— ${comingSoon.note}` : ''}
                    </p>
                )}

                {(errorCode || errorMessage) && (
                    <details className="mt-4 text-xs text-slate-500">
                        <summary className="cursor-pointer select-none font-medium text-slate-500 hover:text-slate-700">
                            Technical details
                        </summary>
                        <dl className="mt-2 grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1 font-mono">
                            {errorCode && (
                                <>
                                    <dt className="text-slate-400">code</dt>
                                    <dd className="break-all text-slate-700">{errorCode}</dd>
                                </>
                            )}
                            {errorMessage && (
                                <>
                                    <dt className="text-slate-400">message</dt>
                                    <dd className="break-all text-slate-700">{errorMessage}</dd>
                                </>
                            )}
                            {extension && (
                                <>
                                    <dt className="text-slate-400">extension</dt>
                                    <dd className="break-all text-slate-700">.{extension.toLowerCase()}</dd>
                                </>
                            )}
                        </dl>
                    </details>
                )}

                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-800"
                    >
                        Got it
                    </button>
                </div>
            </div>
        </div>
    )
}
