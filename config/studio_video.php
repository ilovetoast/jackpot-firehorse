<?php

return [

    'export_enabled' => (bool) env('STUDIO_VIDEO_EXPORT_ENABLED', true),

    /**
     * Allowed video layer count per composition export (V1: typically 1 base + image overlays).
     */
    'export_max_video_layers' => (int) env('STUDIO_VIDEO_EXPORT_MAX_VIDEO_LAYERS', 3),

    /**
     * FFmpeg binary; must exist on workers running {@see \App\Jobs\ProcessStudioCompositionVideoExportJob}.
     */
    'ffmpeg_binary' => env('STUDIO_VIDEO_FFMPEG_BINARY', 'ffmpeg'),

    /** Probing duration and streams; must exist on workers running video export. */
    'ffprobe_binary' => env('STUDIO_VIDEO_FFPROBE_BINARY', 'ffprobe'),
];
