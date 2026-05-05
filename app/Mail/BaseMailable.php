<?php

namespace App\Mail;

use App\Services\EmailGate;
use App\Services\TransactionalEmailActivityRecorder;
use Illuminate\Mail\Mailable as IlluminateMailable;
use Illuminate\Support\Facades\Log;

/**
 * Base class for all application mailables.
 *
 * Do not extend {@see IlluminateMailable} directly — always use BaseMailable so
 * {@see EmailGate} classification and staging safety apply.
 */
abstract class BaseMailable extends IlluminateMailable
{
    /**
     * {@see EmailGate::TYPE_USER} = direct user action; always allowed.
     * {@see EmailGate::TYPE_SYSTEM} = jobs/schedules/automation; gated by config.
     * {@see EmailGate::TYPE_OPERATIONS} = site-operator alerts (e.g. AI quota); always allowed.
     */
    protected string $emailType = 'user';

    /**
     * When false, a successful send does not create an {@see \App\Enums\EventType::EMAIL_TRANSACTIONAL_SENT} row
     * (e.g. team invite already logs {@see \App\Enums\EventType::USER_INVITED}).
     */
    protected bool $recordSendActivity = true;

    public function shouldSend(): bool
    {
        return app(EmailGate::class)->canSend($this->emailType);
    }

    public function shouldRecordSendActivity(): bool
    {
        return $this->recordSendActivity;
    }

    /**
     * @internal Used for activity metadata; mirrors {@see $emailType}.
     */
    public function emailTypeForActivity(): string
    {
        return $this->emailType;
    }

    /**
     * @return list<string>
     */
    public function activityRecipientAddresses(): array
    {
        $emails = [];
        foreach (['to', 'cc', 'bcc'] as $kind) {
            foreach ($this->{$kind} ?? [] as $recipient) {
                $addr = $recipient['address'] ?? null;
                if (is_string($addr) && $addr !== '') {
                    $emails[] = $addr;
                }
            }
        }

        return array_values(array_unique($emails));
    }

    public function send($mailer)
    {
        if (! $this->shouldSend()) {
            Log::info('[EmailBlocked] System email prevented', [
                'mailable' => static::class,
                'email_type' => $this->emailType,
            ]);

            return;
        }

        parent::send($mailer);

        TransactionalEmailActivityRecorder::record($this);
    }
}
