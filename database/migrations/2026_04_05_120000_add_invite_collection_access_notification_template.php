<?php

use App\Models\NotificationTemplate;
use App\Support\TransactionalEmailHtml;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        NotificationTemplate::updateOrCreate(
            ['key' => 'invite_collection_access'],
            [
                'name' => 'Invite Collection Access',
                'category' => 'tenant',
                'subject' => 'You\'re invited to a collection — {{collection_name}}',
                'body_html' => TransactionalEmailHtml::tenantShell(<<<'HTML'
<h2 style="margin:0 0 16px;font-size:22px;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">You're invited to a collection</h2>
<p style="margin:0 0 16px;">Hi there,</p>
<p style="margin:0 0 20px;"><strong>{{inviter_name}}</strong> has invited you to view the collection <strong>{{collection_name}}</strong> for <strong>{{brand_name}}</strong> on {{app_name}}.</p>
<p style="margin:0 0 12px;">Click the button below to accept and sign in (or create your account):</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr><td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);"><a href="{{invite_url}}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">View collection invitation</a></td></tr></table>
<p style="margin:20px 0 0;font-size:13px;color:#64748b;">Or copy and paste this link into your browser:<br><a href="{{invite_url}}" style="color:#4f46e5;word-break:break-all;">{{invite_url}}</a></p>
<p style="margin:24px 0 0;font-size:13px;color:#94a3b8;">If you didn't expect this invitation, you can safely ignore this email.</p>
HTML, null, 'Collection access · {{brand_name}}'),
                'body_text' => "You're invited to a collection\n\nHi there,\n\n{{inviter_name}} has invited you to view the collection \"{{collection_name}}\" for {{brand_name}} on {{app_name}}.\n\nOpen the invitation: {{invite_url}}\n\nIf you didn't expect this invitation, you can safely ignore this email.",
                'variables' => ['tenant_name', 'brand_name', 'collection_name', 'inviter_name', 'invite_url', 'app_name', 'app_url', 'tenant_logo_block'],
                'is_active' => true,
            ]
        );
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'invite_collection_access')->delete();
    }
};
