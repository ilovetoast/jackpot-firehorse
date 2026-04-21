<?php

namespace App\Jobs;

use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Services\Privacy\UserPersonalDataAnonymizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnonymizeUserPersonalDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $dataSubjectRequestId,
        public int $processedByUserId
    ) {}

    public function handle(UserPersonalDataAnonymizer $anonymizer): void
    {
        $dsr = DataSubjectRequest::query()->find($this->dataSubjectRequestId);
        if (! $dsr || $dsr->type !== DataSubjectRequest::TYPE_ERASURE) {
            return;
        }

        if ($dsr->status !== DataSubjectRequest::STATUS_PENDING) {
            return;
        }

        $user = User::query()->find($dsr->user_id);
        if (! $user) {
            $dsr->update([
                'status' => DataSubjectRequest::STATUS_FAILED,
                'failure_reason' => 'User account not found.',
                'processed_at' => now(),
                'processed_by_user_id' => $this->processedByUserId,
            ]);

            return;
        }

        try {
            $dsr->update([
                'status' => DataSubjectRequest::STATUS_IN_PROGRESS,
                'processed_by_user_id' => $this->processedByUserId,
            ]);

            $anonymizer->anonymize($user);

            $dsr->update([
                'status' => DataSubjectRequest::STATUS_COMPLETED,
                'processed_at' => now(),
                'processed_by_user_id' => $this->processedByUserId,
                'failure_reason' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('[DSR] Erasure failed', [
                'dsr_id' => $dsr->id,
                'user_id' => $dsr->user_id,
                'error' => $e->getMessage(),
            ]);

            $dsr->update([
                'status' => DataSubjectRequest::STATUS_FAILED,
                'failure_reason' => Str::limit($e->getMessage(), 500),
                'processed_at' => now(),
                'processed_by_user_id' => $this->processedByUserId,
            ]);

            throw $e;
        }
    }
}
