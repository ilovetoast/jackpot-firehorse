/**
 * Frame-addressable video sync inside the composition scene (no free-running RAF in export mode).
 * Targets elements marked with {@code data-jp-composition-scene-video}; editor videos also keep
 * {@code data-jp-editor-layer} for the playback bar.
 */
export function applyVideosCurrentTimeInContainer(
    container: HTMLElement | null,
    timeMs: number,
    durationMs: number,
): void {
    if (!container) {
        return
    }
    const t = Math.max(0, Math.min(timeMs / 1000, Math.max(0.001, durationMs) / 1000))
    for (const v of container.querySelectorAll<HTMLVideoElement>('[data-jp-composition-scene-video]')) {
        try {
            v.currentTime = t
        } catch {
            /* decode / range */
        }
    }
}
