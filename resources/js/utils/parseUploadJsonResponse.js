/**
 * Parse a fetch Response body as JSON when the server should return JSON.
 * Produces clear, actionable errors when the body is HTML (error page), XML (e.g. S3), or otherwise not JSON.
 */

/**
 * @param {Response} response
 * @param {string} context - Short label for messages (e.g. 'initiate-batch', 'finalize')
 * @returns {Promise<object>}
 */
export async function parseUploadJsonResponse(response, context = 'upload') {
    const raw = await response.text()
    const trimmed = raw.trim()

    if (!trimmed) {
        throw new Error(
            `Empty response from the server (${context}). Try refreshing the page and uploading again.`
        )
    }

    const head = trimmed.slice(0, 400).toLowerCase()
    const looksHtml =
        /^<!doctype\s+html/i.test(trimmed) ||
        /^<html[\s>]/i.test(trimmed) ||
        (trimmed.startsWith('<') && (head.includes('<html') || head.includes('<head') || head.includes('<body')))

    if (looksHtml) {
        throw new Error(
            'The server returned an error page instead of upload data. Refresh the page and try again. If you dragged a file from a ZIP archive without extracting it, extract the file to your computer first, then upload that file.'
        )
    }

    if (trimmed.startsWith('<?xml') || /<Error[\s>]/.test(trimmed)) {
        throw new Error(
            `Storage returned an error (${context}). If you dragged a file from inside a ZIP folder, extract it first, then try again.`
        )
    }

    try {
        return JSON.parse(trimmed)
    } catch {
        throw new Error(
            `Could not read the server response (${context}). If you dragged a file from a zipped folder, extract it first, then upload the extracted file. Otherwise refresh the page and try again.`
        )
    }
}
