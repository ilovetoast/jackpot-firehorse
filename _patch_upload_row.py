from pathlib import Path

p = Path("resources/js/Components/UploadItemRow.jsx")
t = p.read_text()
t = t.replace(
    "function UploadItemRow({ item, uploadManager, onRemove, onRetry, disabled = false, containPerformance = false }) {",
    "function UploadItemRow({ item, uploadManager, onRemove, onRetry, disabled = false, containPerformance = false, brandPrimary = null }) {",
    1,
)
old = """    // Phase 3.0: Enhanced progress bar color coding
    const getProgressBarColor = () => {
        switch (displayStatus) {
            case 'queued':
                return 'bg-gray-300';
            case 'initiating':
            case 'uploading':
                return 'bg-blue-600';
            case 'processing':
                return 'bg-indigo-600';
            case 'complete':
                return 'bg-green-600';
            case 'failed':
                return 'bg-red-600';
            default:
                return 'bg-gray-300';
        }
    };"""
new = """    // Phase 3.0: Progress fill — brand primary for active work when provided (Add Asset modal)
    const getProgressBarFill = () => {
        const base = 'h-full transition-[width] duration-300 rounded-full'
        switch (displayStatus) {
            case 'queued':
                return { className: f'{base} bg-gray-300', style: None }
            case 'initiating':
            case 'uploading':
            case 'processing':
                if (brandPrimary):
                    return { className: base, style: { backgroundColor: brandPrimary } }
                return { className: f'{base} bg-blue-600', style: None }
            case 'complete':
                return { className: f'{base} bg-green-600', style: None }
            case 'failed':
                return { className: f'{base} bg-red-600', style: None }
            default:
                return { className: f'{base} bg-gray-300', style: None }
        }
    };
    const progressBarFill = getProgressBarFill();"""
if old not in t:
    raise SystemExit("getProgressBarColor block not found")
t = t.replace(old, new, 1)
old_div = """                                            <div
                                                className={`h-full transition-[width] duration-300 ${getProgressBarColor()}`}
                                                style={{ width: `${getProgressPercentage()}%` }}
                                            />"""
new_div = """                                            <div
                                                className={progressBarFill.className}
                                                style={{
                                                    width: `${getProgressPercentage()}%`,
                                                    ...(progressBarFill.style || {}),
                                                }}
                                            />"""
if old_div not in t:
    raise SystemExit("progress div not found")
t = t.replace(old_div, new_div, 1)
# memo comparator
old_memo = """    if (prevProps.containPerformance !== nextProps.containPerformance) {
        return false;
    }"""
new_memo = """    if (prevProps.containPerformance !== nextProps.containPerformance) {
        return false;
    }
    if (prevProps.brandPrimary !== nextProps.brandPrimary) {
        return false;
    }"""
if old_memo not in t:
    raise SystemExit("memo block not found")
t = t.replace(old_memo, new_memo, 1)
p.write_text(t)
print("UploadItemRow ok")
