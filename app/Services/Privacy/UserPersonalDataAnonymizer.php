<?php

namespace App\Services\Privacy;

use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Scrubs identifiable personal data for a user after an approved erasure request (Art. 17).
 * Does not delete the user row (preserves referential integrity); account is suspended and identifiers are one-way replaced.
 */
class UserPersonalDataAnonymizer
{
    public function anonymize(User $user): void
    {
        $uid = $user->id;
        $email = $user->email;

        DB::transaction(function () use ($user, $uid, $email) {
            DB::table('sessions')->where('user_id', $uid)->delete();

            $redactedMeta = json_encode(['redacted' => true]);

            DB::table('activity_events')
                ->where('actor_type', 'user')
                ->where('actor_id', $uid)
                ->update([
                    'metadata' => $redactedMeta,
                    'ip_address' => null,
                    'user_agent' => null,
                ]);

            DB::table('activity_events')
                ->where('subject_type', User::class)
                ->where('subject_id', $uid)
                ->update([
                    'metadata' => $redactedMeta,
                    'ip_address' => null,
                    'user_agent' => null,
                ]);

            if (Schema::hasTable('ai_agent_runs')) {
                DB::table('ai_agent_runs')
                    ->where('user_id', $uid)
                    ->update(['metadata' => null]);
            }

            if (Schema::hasTable('frontend_errors')) {
                DB::table('frontend_errors')
                    ->where('user_id', $uid)
                    ->update([
                        'message' => '[Redacted]',
                        'stack_trace' => null,
                        'metadata' => null,
                        'user_agent' => null,
                    ]);
            }

            if (Schema::hasTable('contact_leads') && $email) {
                $leadIds = DB::table('contact_leads')->where('email', $email)->pluck('id');
                foreach ($leadIds as $leadId) {
                    DB::table('contact_leads')->where('id', $leadId)->update([
                        'email' => 'erased.lead.'.$leadId.'.'.Str::lower(Str::random(10)).'@invalid.local',
                        'name' => 'Erased',
                        'phone' => null,
                        'job_title' => null,
                        'company' => null,
                        'company_website' => null,
                        'message' => '[Redacted]',
                        'details' => null,
                        'utm' => null,
                        'heard_from' => null,
                        'ip_address' => null,
                        'user_agent' => null,
                    ]);
                }
            }

            if (Schema::hasTable('asset_approval_comments')) {
                DB::table('asset_approval_comments')
                    ->where('user_id', $uid)
                    ->whereNotNull('comment')
                    ->update(['comment' => '[Redacted]']);
            }

            if (Schema::hasTable('consents')) {
                DB::table('consents')->where('user_id', $uid)->delete();
            }

            $newEmail = 'erased.'.$uid.'.'.Str::lower(Str::random(10)).'@invalid.local';

            $user->email = $newEmail;
            $user->first_name = 'Erased';
            $user->last_name = 'User';
            // Password cast is `hashed` — assign a long random string (not pre-hashed).
            $user->password = Str::random(64);
            $user->remember_token = null;
            $user->avatar_url = null;
            $user->address = null;
            $user->city = null;
            $user->state = null;
            $user->zip = null;
            $user->country = null;
            $user->notification_preferences = [];
            $user->push_enabled = false;
            $user->push_prompted_at = null;
            $user->suspended_at = now();
            $user->save();
        });
    }
}
