<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;
use SplFileObject;

/**
 * Admin Log Viewer Controller
 *
 * Web/worker: Redis-backed log viewer (last 50 entries).
 * Deploy: tail of deploy script log file (filesystem, last N lines).
 */
class AdminLogController extends Controller
{
    /**
     * Display the admin log viewer page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);

        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }

        return Inertia::render('Admin/LogViewer', []);
    }

    /**
     * API: Web/worker logs from Redis, or deploy log tail from disk.
     */
    public function api(string $stream): JsonResponse
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);

        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }

        if (! in_array($stream, ['web', 'worker', 'deploy'], true)) {
            abort(404);
        }

        if ($stream === 'deploy') {
            return $this->deployLogResponse();
        }

        try {
            $logs = Redis::lrange("admin_logs:{$stream}", 0, 49);
        } catch (\Throwable $e) {
            // Local may not have Redis
            return response()->json(['kind' => 'redis', 'logs' => []]);
        }

        $decoded = collect($logs)->map(fn ($l) => json_decode($l, true))->filter()->values()->all();

        return response()->json(['kind' => 'redis', 'logs' => $decoded]);
    }

    private function deployLogResponse(): JsonResponse
    {
        $path = (string) config('admin_logs.deploy_log_path', '/var/www/jackpot/deploy/deploy.log');
        $maxLines = max(1, min(500, (int) config('admin_logs.deploy_log_tail_lines', 100)));

        if ($path === '' || ! is_readable($path)) {
            return response()->json([
                'kind' => 'deploy',
                'path' => $path,
                'lines' => [],
                'error' => 'Log file not found or not readable by the application.',
            ]);
        }

        if (filesize($path) === 0) {
            return response()->json([
                'kind' => 'deploy',
                'path' => $path,
                'lines' => [],
                'error' => null,
            ]);
        }

        try {
            $lines = $this->tailFileLines($path, $maxLines);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'kind' => 'deploy',
                'path' => $path,
                'lines' => [],
                'error' => 'Unable to read deploy log.',
            ]);
        }

        return response()->json([
            'kind' => 'deploy',
            'path' => $path,
            'lines' => $lines,
            'error' => null,
        ]);
    }

    /**
     * Last N lines of a text file without loading the whole file into memory.
     *
     * @return list<string>
     */
    private function tailFileLines(string $path, int $maxLines): array
    {
        $file = new SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastIndex = $file->key();
        $start = max(0, $lastIndex - $maxLines + 1);
        $file->seek($start);
        $out = [];
        while (! $file->eof()) {
            $line = $file->current();
            if ($line !== false) {
                $out[] = rtrim((string) $line, "\r\n");
            }
            $file->next();
        }

        return $out;
    }
}
