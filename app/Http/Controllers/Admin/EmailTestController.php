<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InviteMember;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class EmailTestController extends Controller
{
    /**
     * Display the email testing page.
     */
    public function index(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        // Get mail configuration
        $mailConfig = [
            'driver' => config('mail.default'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];

        // Determine local dev email location
        $devEmailLocation = null;
        $isLocal = app()->environment('local');
        
        if ($isLocal) {
            // Check for Mailpit (common in Laravel Sail/Docker)
            $mailpitPort = env('FORWARD_MAILPIT_DASHBOARD_PORT', '8025');
            if ($mailpitPort) {
                $devEmailLocation = "http://localhost:{$mailpitPort}";
            } else {
                // Check for MailHog (alternative)
                $mailhogPort = env('MAILHOG_PORT', '8025');
                if ($mailhogPort) {
                    $devEmailLocation = "http://localhost:{$mailhogPort}";
                } else {
                    // Default Mailpit port
                    $devEmailLocation = "http://localhost:8025";
                }
            }
        }

        // Get recent emails from log (if using log driver)
        $recentEmails = [];
        if (config('mail.default') === 'log') {
            $logPath = storage_path('logs/laravel.log');
            if (file_exists($logPath)) {
                // Read last 50 lines to find email logs
                $lines = file($logPath);
                $recentLines = array_slice($lines, -50);
                foreach ($recentLines as $line) {
                    if (strpos($line, 'Message-ID:') !== false || strpos($line, 'To:') !== false) {
                        $recentEmails[] = $line;
                    }
                }
            }
        }

        return Inertia::render('Admin/EmailTest', [
            'mail_config' => $mailConfig,
            'recent_emails' => array_slice($recentEmails, -10), // Last 10
            'laravel_log_url' => route('admin.email-test.log'),
            'is_local' => $isLocal,
            'dev_email_location' => $devEmailLocation,
        ]);
    }

    /**
     * Send a test email.
     */
    public function send(Request $request)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $validated = $request->validate([
            'email' => 'required|email',
            'template' => 'required|string|in:invite_member',
        ]);

        try {
            $user = Auth::user();
            $tenant = $user->tenants()->first() ?? Tenant::first();

            if (!$tenant) {
                return back()->withErrors(['error' => 'No tenant found to send test email.']);
            }

            $inviteUrl = route('billing') . '?invite_token=test_token_123';

            Mail::to($validated['email'])->send(new InviteMember($tenant, $user, $inviteUrl));

            return back()->with('success', 'Test email sent successfully to ' . $validated['email']);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to send test email: ' . $e->getMessage()]);
        }
    }

    /**
     * View Laravel log file (for email debugging).
     */
    public function log()
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $logPath = storage_path('logs/laravel.log');
        
        if (!file_exists($logPath)) {
            return response('Log file not found.', 404);
        }

        // Read last 1000 lines
        $lines = file($logPath);
        $recentLines = array_slice($lines, -1000);
        
        return response(implode('', $recentLines), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
