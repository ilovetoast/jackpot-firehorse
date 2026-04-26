<?php

namespace App\Mail;

use App\Services\EmailGate;
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

    public function shouldSend(): bool
    {
        return app(EmailGate::class)->canSend($this->emailType);
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
    }
}
