<?php

namespace App\Mail;

use App\Enums\TicketCategory;
use App\Enums\TicketType;
use App\Models\NotificationTemplate;
use App\Support\TransactionalEmailHtml;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketCreated extends BaseMailable
{
    use Queueable, SerializesModels;

    protected string $emailType = 'system';

    public function __construct(
        public Ticket $ticket
    ) {}

    public function envelope(): Envelope
    {
        $template = NotificationTemplate::getByKey('support_ticket_created');
        $vars = $this->templateVariables();

        if ($template) {
            $rendered = $template->render($vars);

            return new Envelope(
                subject: $rendered['subject'],
            );
        }

        $isEng = $this->ticket->type === TicketType::INTERNAL;

        return new Envelope(
            subject: $isEng
                ? "New engineering ticket {$this->ticket->ticket_number}"
                : "New support ticket {$this->ticket->ticket_number}",
        );
    }

    public function content(): Content
    {
        $template = NotificationTemplate::getByKey('support_ticket_created');
        $vars = $this->templateVariables();

        if ($template) {
            $rendered = $template->render($vars);

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        return new Content(
            view: 'emails.support-ticket-created',
            with: [
                'ticketNumber'  => $vars['ticket_number'],
                'ticketSubject' => $vars['ticket_subject'],
                'categoryLabel' => $vars['category_label'],
                'tenantName'    => $vars['tenant_name'],
                'creatorName'   => $vars['creator_name'],
                'assigneeName'  => $vars['assignee_name'],
                'ticketUrl'     => $vars['ticket_url'],
                'flowLabel'     => $vars['flow_label'] ?? 'Support',
                'introLine'     => $vars['intro_line'] ?? 'A support ticket has been created and assigned to you.',
            ],
        );
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

        $tenantName = $ticket->tenant?->name ?? match ($ticket->type) {
            TicketType::TENANT_INTERNAL => 'Internal (tenant hidden)',
            TicketType::INTERNAL => '— (internal / engineering)',
            default => '—',
        };

        $creator = $ticket->createdBy;
        $creatorName = $creator ? trim("{$creator->first_name} {$creator->last_name}") : '—';

        $assignee = $ticket->assignedTo;
        $assigneeName = $assignee ? trim("{$assignee->first_name} {$assignee->last_name}") : '—';

        $ticketUrl = route('admin.support.tickets.show', $ticket);

        $isInternalEngineering = $ticket->type === TicketType::INTERNAL;

        return array_merge([
            'assignee_name' => $assigneeName,
            'ticket_number' => $ticket->ticket_number,
            'ticket_subject' => $subject,
            'category_label' => $categoryLabel,
            'tenant_name' => $tenantName,
            'creator_name' => $creatorName,
            'ticket_url' => $ticketUrl,
            'app_name' => config('app.name', 'Jackpot'),
            'app_url' => rtrim((string) config('app.url', url('/')), '/'),
            'flow_label' => $isInternalEngineering ? 'Engineering' : 'Support',
            'intro_line' => $isInternalEngineering
                ? 'An internal (engineering) ticket was created and assigned to you.'
                : 'A support ticket has been created and assigned to you.',
        ], TransactionalEmailHtml::transactionalCtaPlaceholdersForSystem());
    }
}
