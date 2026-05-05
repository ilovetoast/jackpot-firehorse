<?php

namespace App\Services;

use App\Enums\EventType;
use App\Mail\BaseMailable;
use App\Models\Asset;
use App\Models\Collection;
use App\Models\ContactLead;
use App\Models\Download;
use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Records {@see EventType::EMAIL_TRANSACTIONAL_SENT} after {@see BaseMailable} sends successfully.
 */
class TransactionalEmailActivityRecorder
{
    public static function record(BaseMailable $mailable): void
    {
        try {
            if (! $mailable->shouldRecordSendActivity()) {
                return;
            }

            $tenant = self::resolveTenant($mailable);
            if (! $tenant instanceof Tenant) {
                return;
            }

            $subject = self::resolveSubjectModel($mailable, $tenant);

            $metadata = [
                'mailable' => $mailable::class,
                'email_type' => $mailable->emailTypeForActivity(),
                'recipients' => array_slice($mailable->activityRecipientAddresses(), 0, 20),
            ];

            if ($subject instanceof User && isset($subject->name)) {
                $metadata['subject_name'] = $subject->name;
            } elseif ($subject instanceof Tenant && isset($subject->name)) {
                $metadata['subject_name'] = $subject->name;
            }

            $templateKey = self::templateKeyFromMailable($mailable);
            if ($templateKey !== null && $templateKey !== '') {
                $metadata['template_key'] = $templateKey;
            }

            ActivityRecorder::system(
                $tenant,
                EventType::EMAIL_TRANSACTIONAL_SENT,
                $subject,
                $metadata
            );
        } catch (\Throwable $e) {
            Log::warning('TransactionalEmailActivityRecorder: failed to record activity', [
                'mailable' => $mailable::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function resolveTenant(BaseMailable $mailable): ?Tenant
    {
        $direct = $mailable->tenant ?? null;
        if ($direct instanceof Tenant) {
            return $direct;
        }

        $collection = $mailable->collection ?? null;
        if ($collection instanceof Collection) {
            $collection->loadMissing('tenant');
            if ($collection->tenant_id) {
                return $collection->tenant ?? Tenant::query()->find($collection->tenant_id);
            }
        }

        $download = $mailable->download ?? null;
        if ($download instanceof Download) {
            if ($download->tenant_id) {
                return $download->tenant ?? Tenant::query()->find($download->tenant_id);
            }
        }

        $ticket = $mailable->ticket ?? null;
        if ($ticket instanceof Ticket && $ticket->tenant_id) {
            return $ticket->tenant ?? Tenant::query()->find($ticket->tenant_id);
        }

        $asset = $mailable->asset ?? null;
        if ($asset instanceof Asset && $asset->tenant_id) {
            return $asset->tenant ?? Tenant::query()->find($asset->tenant_id);
        }

        $lead = $mailable->lead ?? null;
        if ($lead instanceof ContactLead && $lead->converted_to_tenant_id) {
            return Tenant::query()->find($lead->converted_to_tenant_id);
        }

        foreach (['user', 'owner', 'inviter', 'sender'] as $key) {
            $user = $mailable->{$key} ?? null;
            if ($user instanceof User) {
                $tenant = $user->tenants()->orderBy('tenant_user.created_at')->first();
                if ($tenant) {
                    return $tenant;
                }
            }
        }

        return null;
    }

    private static function resolveSubjectModel(BaseMailable $mailable, Tenant $tenant): Model
    {
        foreach (['user', 'owner', 'inviter', 'sender'] as $key) {
            $model = $mailable->{$key} ?? null;
            if ($model instanceof User) {
                return $model;
            }
        }

        $direct = $mailable->tenant ?? null;
        if ($direct instanceof Tenant) {
            return $direct;
        }

        return $tenant;
    }

    private static function templateKeyFromMailable(BaseMailable $mailable): ?string
    {
        $tpl = $mailable->template ?? null;
        if ($tpl instanceof NotificationTemplate) {
            return $tpl->key ?? null;
        }

        return null;
    }
}
