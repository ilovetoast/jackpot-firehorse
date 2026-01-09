<?php

namespace App\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * TicketAttachmentService
 *
 * Handles ticket attachment uploads with S3 storage and plan validation.
 * Files are stored in S3 with signed URLs for secure access.
 *
 * Storage Path: tickets/{tenant_id}/{ticket_id}/{filename}
 * All files use signed URLs - no public-read buckets.
 */
class TicketAttachmentService
{
    public function __construct(
        protected TicketPlanGate $planGate
    ) {
    }

    /**
     * Upload an attachment for a ticket.
     * Validates plan limits and stores file in S3.
     *
     * @param Ticket $ticket
     * @param UploadedFile $file
     * @param \App\Models\TicketMessage|null $message Optional message this attachment belongs to
     * @param bool $isInternal Whether this is an internal-only attachment
     * @return TicketAttachment
     * @throws \Exception
     */
    public function uploadAttachment(Ticket $ticket, UploadedFile $file, ?\App\Models\TicketMessage $message = null, bool $isInternal = false): TicketAttachment
    {
        $tenant = $ticket->tenant;
        if (!$tenant) {
            throw new \Exception('Ticket must have a tenant to upload attachments.');
        }

        // Validate plan limits
        $currentCount = $ticket->attachments()->count();
        $this->validateAttachmentPlan($tenant, $currentCount, $file->getSize());

        // Generate storage path
        $path = $this->generateStoragePath($ticket, $file->getClientOriginalName());

        // Store file in S3
        $storedPath = Storage::disk('s3')->putFileAs(
            dirname($path),
            $file,
            basename($path),
            'private' // Private visibility
        );

        // Create attachment record
        $attachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $message?->id,
            'user_id' => auth()->id(),
            'file_path' => $storedPath,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'is_internal' => $isInternal,
        ]);

        return $attachment;
    }

    /**
     * Generate signed URL for attachment download.
     * URLs expire after 1 hour for security.
     *
     * @param TicketAttachment $attachment
     * @return string Signed URL
     */
    public function getSignedUrl(TicketAttachment $attachment): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $attachment->file_path,
            now()->addHour()
        );
    }

    /**
     * Validate attachment against plan limits.
     *
     * @param Tenant $tenant
     * @param int $currentCount Current number of attachments on ticket
     * @param int $fileSize File size in bytes
     * @return void
     * @throws PlanLimitExceededException
     */
    public function validateAttachmentPlan(Tenant $tenant, int $currentCount, int $fileSize): void
    {
        $maxSize = $this->planGate->getMaxAttachmentSize($tenant);
        $maxCount = $this->planGate->getMaxAttachmentsPerTicket($tenant);

        if ($fileSize > $maxSize) {
            throw new PlanLimitExceededException(
                'attachment_size',
                $fileSize,
                $maxSize,
                "File size exceeds maximum of {$this->planGate->getMaxAttachmentSizeDisplay($tenant)} for your plan."
            );
        }

        if ($currentCount >= $maxCount) {
            throw new PlanLimitExceededException(
                'attachment_count',
                $currentCount,
                $maxCount,
                "Maximum of {$maxCount} attachments per ticket for your plan."
            );
        }
    }

    /**
     * Generate storage path for attachment.
     * Format: tickets/{tenant_id}/{ticket_id}/{filename}
     *
     * @param Ticket $ticket
     * @param string $originalFilename
     * @return string
     */
    protected function generateStoragePath(Ticket $ticket, string $originalFilename): string
    {
        $tenantId = $ticket->tenant_id;
        $ticketId = $ticket->id;
        $filename = time() . '_' . $originalFilename; // Add timestamp to avoid collisions

        return "tickets/{$tenantId}/{$ticketId}/{$filename}";
    }
}
