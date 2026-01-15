/**
 * User Agent Parser
 * 
 * Parses user agent strings to extract browser, OS, and device information
 * in a human-readable format.
 */

/**
 * Parse user agent string to extract browser and OS information
 * @param {string} userAgent - The user agent string
 * @returns {Object} Parsed information with browser, os, and device
 */
export function parseUserAgent(userAgent) {
    if (!userAgent || typeof userAgent !== 'string') {
        return {
            browser: 'Unknown',
            os: 'Unknown',
            device: 'Unknown',
            full: userAgent || 'N/A',
        }
    }

    const ua = userAgent.toLowerCase()
    
    // Browser detection
    let browser = 'Unknown'
    let browserVersion = ''
    
    if (ua.includes('chrome') && !ua.includes('edg')) {
        browser = 'Chrome'
        const match = ua.match(/chrome\/([\d.]+)/)
        browserVersion = match ? match[1] : ''
    } else if (ua.includes('firefox')) {
        browser = 'Firefox'
        const match = ua.match(/firefox\/([\d.]+)/)
        browserVersion = match ? match[1] : ''
    } else if (ua.includes('safari') && !ua.includes('chrome')) {
        browser = 'Safari'
        const match = ua.match(/version\/([\d.]+)/)
        browserVersion = match ? match[1] : ''
    } else if (ua.includes('edg')) {
        browser = 'Edge'
        const match = ua.match(/edg\/([\d.]+)/)
        browserVersion = match ? match[1] : ''
    } else if (ua.includes('opera') || ua.includes('opr')) {
        browser = 'Opera'
        const match = ua.match(/(?:opera|opr)\/([\d.]+)/)
        browserVersion = match ? match[1] : ''
    }
    
    // OS detection
    let os = 'Unknown'
    let osVersion = ''
    
    if (ua.includes('windows')) {
        os = 'Windows'
        if (ua.includes('windows nt 10.0')) osVersion = '10/11'
        else if (ua.includes('windows nt 6.3')) osVersion = '8.1'
        else if (ua.includes('windows nt 6.2')) osVersion = '8'
        else if (ua.includes('windows nt 6.1')) osVersion = '7'
    } else if (ua.includes('mac os x') || ua.includes('macintosh')) {
        os = 'macOS'
        const match = ua.match(/mac os x ([\d_]+)/)
        if (match) {
            osVersion = match[1].replace(/_/g, '.')
        }
    } else if (ua.includes('linux')) {
        os = 'Linux'
    } else if (ua.includes('android')) {
        os = 'Android'
        const match = ua.match(/android ([\d.]+)/)
        osVersion = match ? match[1] : ''
    } else if (ua.includes('iphone') || ua.includes('ipad') || ua.includes('ipod')) {
        os = 'iOS'
        const match = ua.match(/os ([\d_]+)/)
        if (match) {
            osVersion = match[1].replace(/_/g, '.')
        }
    }
    
    // Device detection
    let device = 'Desktop'
    if (ua.includes('mobile') || ua.includes('android') || ua.includes('iphone')) {
        device = 'Mobile'
    } else if (ua.includes('tablet') || ua.includes('ipad')) {
        device = 'Tablet'
    }
    
    return {
        browser: browserVersion ? `${browser} ${browserVersion}` : browser,
        os: osVersion ? `${os} ${osVersion}` : os,
        device,
        full: userAgent,
    }
}

/**
 * Get browser icon name for display
 * @param {string} browser - Browser name
 * @returns {string} Icon identifier
 */
export function getBrowserIcon(browser) {
    const b = browser.toLowerCase()
    if (b.includes('chrome')) return 'chrome'
    if (b.includes('firefox')) return 'firefox'
    if (b.includes('safari')) return 'safari'
    if (b.includes('edge')) return 'edge'
    if (b.includes('opera')) return 'opera'
    return 'globe'
}

/**
 * Get OS icon name for display
 * @param {string} os - OS name
 * @returns {string} Icon identifier
 */
export function getOSIcon(os) {
    const o = os.toLowerCase()
    if (o.includes('windows')) return 'windows'
    if (o.includes('mac')) return 'apple'
    if (o.includes('linux')) return 'linux'
    if (o.includes('android')) return 'android'
    if (o.includes('ios')) return 'apple'
    return 'computer'
}
