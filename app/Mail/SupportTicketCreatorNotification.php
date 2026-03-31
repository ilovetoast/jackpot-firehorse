<?php

namespace App\Mail;

use App\Enums\TicketCategory;
use App\Enums\TicketType;
use App\Models\NotificationTemplate;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class SupportTicketCreatorNotification extends BaseMailable
{
    use Queueable, SerializesModels;

    /**
     * @param  'receipt'|'reply'|'terminal'  $kind
     */
    public function __construct(
        public Ticket $ticket,
        public string $kind,
        public ?TicketMessage $message = null,
        public ?string $terminalStatusLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        $template = NotificationTemplate::getByKey($this->templateKey());
        $vars = $this->templateVariables();

        if ($template) {
            $rendered = $template->render($vars);

            return new Envelope(
                subject: $rendered['subject'],
            );
        }

        return new Envelope(
            subject: "Support ticket {$this->ticket->ticket_number}",
        );
    }

    public function content(): Content
    {
        $template = NotificationTemplate::getByKey($this->templateKey());
        $vars = $this->templateVariables();

        if ($template) {
            $rendered = $template->render($vars);

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        $subjectLine = $vars['ticket_subject'];

        return new Content(
            htmlString: '<p style="margin:0 0 12px;">Update regarding your support ticket.</p>'
                . '<p style="margin:0;"><strong>' . e($subjectLine) . '</strong></p>',
        );
    }

    public function templateKey(): string
    {
        return match ($this->kind) {
            'receipt' => 'support_ticket_creator_receipt',
            'reply' => 'support_ticket_creator_reply',
            'terminal' => 'support_ticket_creator_resolved',
            default => 'support_ticket_creator_receipt',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function templateVariables(): array
    {
        $ticket = $this->ticket;
        $meta = $ticket->metadata ?? [];
        $subject = is_string($meta['subject'] ?? null) ? $meta['subject'] : '';
        $subject = $subject !== '' ? $subject : '—';

        $rawCategory = $meta['category'] ?? null;
        $categoryLabel = '—';
        if (is_string($rawCategory) && $rawCategory !== '') {
            $cat = TicketCategory::tryFrom($rawCategory);
            $categoryLabel = $cat ? $cat->label() : $rawCategory;
        }

        $tenantName = $ticket->tenant?->name ?? (
            $ticket->type === TicketType::TENANT_INTERNAL ? 'Internal (tenant hidden)' : '—'
        );

        $creator = $ticket->createdBy;
        $recipientName = $creator ? trim("{$creator->first_name} {$creator->last_name}") : '—';

        $ticketUrl = $this->tenantTicketShowUrl($ticket);

        $vars = [
            'recipient_name' => $recipientName !== '' ? $recipientName : '—',
            'ticket_number' => $ticket->ticket_number,
            'ticket_subject' => $subject,
            'category_label' => $categoryLabel,
            'tenant_name' => $tenantName,
            'ticket_url' => $ticketUrl,
            'app_name' => config('app.name', 'Jackpot'),
            'app_url' => rtrim((string) config('app.url', url('/')), '/'),
        ];

        if ($this->kind === 'reply' && $this->message) {
            $author = $this->message->user;
            $replierName = $author ? trim("{$author->first_name} {$author->last_name}") : 'Support';
            $replyExcerpt = Str::limit(trim(strip_tags((string) $this->message->body)), 400, '…');

            $vars['replier_name'] = $replierName !== '' ? $replierName : 'Support';
            $vars['reply_excerpt'] = $replyExcerpt !== '' ? $replyExcerpt : '—';
        }

        if ($this->kind === 'terminal') {
            $vars['status_label'] = $this->terminalStatusLabel ?? '—';
        }

        return $vars;
    }

    protected function tenantTicketShowUrl(Ticket $ticket): string
    {
        if (Route::has('support.tickets.show')) {
            return route('support.tickets.show', $ticket, true);
        }

        return url('/app/support/tickets/'.$ticket->id);
    }
}
