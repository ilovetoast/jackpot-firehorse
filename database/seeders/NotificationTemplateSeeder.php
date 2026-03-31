<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use App\Support\TransactionalEmailHtml;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'key' => 'invite_member',
                'name' => 'Invite Member',
                'category' => 'tenant',
                'subject' => 'You\'ve been invited to join {{tenant_name}}',
                'body_html' => TransactionalEmailHtml::tenantShell(<<<'HTML'
<h2 style="margin:0 0 16px;font-size:22px;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">You've been invited!</h2>
<p style="margin:0 0 16px;">Hi there,</p>
<p style="margin:0 0 20px;"><strong>{{inviter_name}}</strong> has invited you to join <strong>{{tenant_name}}</strong> on {{app_name}}.</p>
<p style="margin:0 0 12px;">Click the button below to accept the invitation and create your account:</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr><td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);"><a href="{{invite_url}}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">Accept invitation</a></td></tr></table>
<p style="margin:20px 0 0;font-size:13px;color:#64748b;">Or copy and paste this link into your browser:<br><a href="{{invite_url}}" style="color:#4f46e5;word-break:break-all;">{{invite_url}}</a></p>
<p style="margin:24px 0 0;font-size:13px;color:#94a3b8;">If you didn't expect this invitation, you can safely ignore this email.</p>
HTML),
                'body_text' => "You've been invited!\n\nHi there,\n\n{{inviter_name}} has invited you to join {{tenant_name}} on {{app_name}}.\n\nAccept your invitation by visiting: {{invite_url}}\n\nIf you didn't expect this invitation, you can safely ignore this email.",
                'variables' => ['tenant_name', 'inviter_name', 'invite_url', 'app_name', 'app_url', 'tenant_logo_block'],
                'is_active' => true,
            ],
            [
                'key' => 'account_canceled',
                'name' => 'Account Canceled',
                'category' => 'tenant',
                'subject' => 'Your account has been canceled - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, {{primary_color}} 0%, {{primary_color_dark}} 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: {{primary_color}};">Account Canceled</h2>
            
            <p>Hi {{user_name}},</p>
            
            <p>
                Your account has been canceled and removed from <strong>{{tenant_name}}</strong> on {{app_name}}.
            </p>
            
            <p>
                This means you no longer have access to {{tenant_name}}\'s workspace, but your account remains active 
                and you can still log in if you have access to other organizations.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: {{primary_color_light}}; border-left: 4px solid {{primary_color}}; border-radius: 4px;">
                <strong>Important:</strong> Your account credentials remain active. If you believe this was done in error, 
                please contact {{admin_name}} ({{admin_email}}) or reach out to support.
            </p>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                If you have any questions or concerns about this action, please don\'t hesitate to contact us.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Account Canceled\n\nHi {{user_name}},\n\nYour account has been canceled and removed from {{tenant_name}} on {{app_name}}.\n\nThis means you no longer have access to {{tenant_name}}'s workspace, but your account remains active and you can still log in if you have access to other organizations.\n\nImportant: Your account credentials remain active. If you believe this was done in error, please contact {{admin_name}} ({{admin_email}}) or reach out to support.\n\nIf you have any questions or concerns about this action, please don't hesitate to contact us.",
                'variables' => ['tenant_name', 'user_name', 'user_email', 'admin_name', 'admin_email', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'account_deleted',
                'name' => 'Account Deleted',
                'category' => 'tenant',
                'subject' => 'Your account has been deleted - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #dc2626;">Account Deleted</h2>
            
            <p>Hi {{user_name}},</p>
            
            <p>
                We are writing to inform you that your account has been <strong>permanently deleted</strong> 
                from {{tenant_name}} on {{app_name}}.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px;">
                <strong>Important:</strong> This action is permanent and cannot be undone. Your account and all 
                associated data have been completely removed from the system. You will no longer be able to log in 
                to {{tenant_name}}\'s workspace.
            </p>
            
            <p>
                This action was performed by {{admin_name}} ({{admin_email}}). If you believe this was done in error, 
                please contact them immediately or reach out to support.
            </p>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                If you have any questions or concerns about this action, please don\'t hesitate to contact us.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Account Deleted\n\nHi {{user_name}},\n\nWe are writing to inform you that your account has been permanently deleted from {{tenant_name}} on {{app_name}}.\n\nImportant: This action is permanent and cannot be undone. Your account and all associated data have been completely removed from the system. You will no longer be able to log in to {{tenant_name}}'s workspace.\n\nThis action was performed by {{admin_name}} ({{admin_email}}). If you believe this was done in error, please contact them immediately or reach out to support.\n\nIf you have any questions or concerns about this action, please don't hesitate to contact us.",
                'variables' => ['tenant_name', 'user_name', 'user_email', 'admin_name', 'admin_email', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'account_suspended',
                'name' => 'Account Suspended',
                'subject' => 'Your account has been suspended',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #dc2626;">Account Suspended</h2>
            
            <p>Hi {{user_name}},</p>
            
            <p>
                We are writing to inform you that your account has been <strong>suspended</strong> and you no longer have access to {{app_name}}.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px;">
                <strong>Important:</strong> Your account access has been temporarily blocked. You will not be able to log in or access any pages until your account is reactivated by an administrator.
            </p>
            
            <p>
                This action was performed by {{admin_name}} ({{admin_email}}). If you believe this was done in error, please contact them immediately or submit a support ticket.
            </p>
            
            <div style="text-align: center; margin: 24px 0;">
                <a href="{{support_url}}" style="display: inline-block; padding: 12px 24px; background-color: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500;">Submit Support Ticket</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                If you have any questions or concerns about this action, please don\'t hesitate to contact us through our support system.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Account Suspended\n\nHi {{user_name}},\n\nWe are writing to inform you that your account has been suspended and you no longer have access to {{app_name}}.\n\nImportant: Your account access has been temporarily blocked. You will not be able to log in or access any pages until your account is reactivated by an administrator.\n\nThis action was performed by {{admin_name}} ({{admin_email}}). If you believe this was done in error, please contact them immediately or submit a support ticket at {{support_url}}.\n\nIf you have any questions or concerns about this action, please don't hesitate to contact us through our support system.",
                'variables' => ['tenant_name', 'user_name', 'user_email', 'admin_name', 'admin_email', 'app_name', 'app_url', 'support_url'],
                'is_active' => true,
            ],
            [
                'key' => 'tenant.owner_transfer_requested',
                'name' => 'Ownership Transfer Requested',
                'subject' => 'Ownership Transfer Request - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0;">Ownership Transfer Request</h2>
            
            <p>Hi {{new_owner_name}},</p>
            
            <p>
                <strong>{{current_owner_name}}</strong> ({{current_owner_email}}) has initiated a request to transfer 
                ownership of <strong>{{tenant_name}}</strong> to you.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #f0f9ff; border-left: 4px solid #6366f1; border-radius: 4px;">
                <strong>What happens next:</strong><br>
                1. The current owner must confirm this transfer via email<br>
                2. Once confirmed, you will receive an acceptance email<br>
                3. After you accept, ownership will be transferred
            </p>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                You will receive another email once the current owner confirms the transfer. 
                If you did not expect this request, you can safely ignore this email.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Ownership Transfer Request\n\nHi {{new_owner_name}},\n\n{{current_owner_name}} ({{current_owner_email}}) has initiated a request to transfer ownership of {{tenant_name}} to you.\n\nWhat happens next:\n1. The current owner must confirm this transfer via email\n2. Once confirmed, you will receive an acceptance email\n3. After you accept, ownership will be transferred\n\nYou will receive another email once the current owner confirms the transfer. If you did not expect this request, you can safely ignore this email.",
                'variables' => ['tenant_name', 'current_owner_name', 'current_owner_email', 'new_owner_name', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'tenant.owner_transfer_confirm',
                'name' => 'Ownership Transfer Confirmation',
                'category' => 'tenant',
                'subject' => 'Confirm Ownership Transfer - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, {{primary_color}} 0%, {{primary_color_dark}} 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: {{primary_color}};">Confirm Ownership Transfer</h2>
            
            <p>Hi {{current_owner_name}},</p>
            
            <p>
                You have initiated a request to transfer ownership of <strong>{{tenant_name}}</strong> to 
                <strong>{{new_owner_name}}</strong> ({{new_owner_email}}).
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: {{primary_color_light}}; border-left: 4px solid {{primary_color}}; border-radius: 4px;">
                <strong>Important:</strong> This action will transfer all ownership rights and responsibilities to {{new_owner_name}}. 
                You will be downgraded to an Admin role after the transfer is completed.
            </p>
            
            <p>
                Click the button below to confirm this transfer:
            </p>
            
            <div style="text-align: center;">
                <a href="{{confirmation_url}}" style="display: inline-block; padding: 12px 24px; background-color: {{primary_color}}; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500; margin: 16px 0;">Confirm Transfer</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                Or copy and paste this link into your browser:<br>
                <a href="{{confirmation_url}}" style="color: #6366f1; word-break: break-all;">{{confirmation_url}}</a>
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px; font-size: 14px;">
                <strong>Security Note:</strong> This link will expire in 7 days. If you did not initiate this transfer, 
                please contact support immediately.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Confirm Ownership Transfer\n\nHi {{current_owner_name}},\n\nYou have initiated a request to transfer ownership of {{tenant_name}} to {{new_owner_name}} ({{new_owner_email}}).\n\nImportant: This action will transfer all ownership rights and responsibilities to {{new_owner_name}}. You will be downgraded to an Admin role after the transfer is completed.\n\nConfirm this transfer by visiting: {{confirmation_url}}\n\nSecurity Note: This link will expire in 7 days. If you did not initiate this transfer, please contact support immediately.",
                'variables' => ['tenant_name', 'current_owner_name', 'new_owner_name', 'new_owner_email', 'confirmation_url', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'tenant.owner_transfer_accept',
                'name' => 'Ownership Transfer Acceptance',
                'subject' => 'Accept Ownership Transfer - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #059669;">Accept Ownership Transfer</h2>
            
            <p>Hi {{new_owner_name}},</p>
            
            <p>
                <strong>{{current_owner_name}}</strong> ({{current_owner_email}}) has confirmed the transfer of ownership 
                of <strong>{{tenant_name}}</strong> to you.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #ecfdf5; border-left: 4px solid #10b981; border-radius: 4px;">
                <strong>What this means:</strong><br>
                • You will become the owner of {{tenant_name}}<br>
                • You will have full administrative control<br>
                • {{current_owner_name}} will be downgraded to Admin role
            </p>
            
            <p>
                Click the button below to accept this ownership transfer:
            </p>
            
            <div style="text-align: center;">
                <a href="{{acceptance_url}}" style="display: inline-block; padding: 12px 24px; background-color: #10b981; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500; margin: 16px 0;">Accept Ownership</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                Or copy and paste this link into your browser:<br>
                <a href="{{acceptance_url}}" style="color: #6366f1; word-break: break-all;">{{acceptance_url}}</a>
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px; font-size: 14px;">
                <strong>Security Note:</strong> This link will expire in 7 days. If you did not expect this transfer, 
                please contact support immediately.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Accept Ownership Transfer\n\nHi {{new_owner_name}},\n\n{{current_owner_name}} ({{current_owner_email}}) has confirmed the transfer of ownership of {{tenant_name}} to you.\n\nWhat this means:\n• You will become the owner of {{tenant_name}}\n• You will have full administrative control\n• {{current_owner_name}} will be downgraded to Admin role\n\nAccept this ownership transfer by visiting: {{acceptance_url}}\n\nSecurity Note: This link will expire in 7 days. If you did not expect this transfer, please contact support immediately.",
                'variables' => ['tenant_name', 'current_owner_name', 'current_owner_email', 'new_owner_name', 'acceptance_url', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'tenant.owner_transfer_completed',
                'name' => 'Ownership Transfer Completed',
                'subject' => 'Ownership Transfer Completed - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
            <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #059669;">Ownership Transfer Complete</h2>
            
            <p>Hi {{recipient_name}},</p>
            
            <p>
                The ownership transfer of <strong>{{tenant_name}}</strong> has been completed.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #ecfdf5; border-left: 4px solid #10b981; border-radius: 4px;">
                <strong>Transfer Details:</strong><br>
                • Previous Owner: {{previous_owner_name}} ({{previous_owner_email}})<br>
                • New Owner: {{new_owner_name}} ({{new_owner_email}})<br>
                • Previous owner has been downgraded to Admin role<br>
                • New owner now has full administrative control
            </p>
            
            <div style="text-align: center; margin: 24px 0;">
                <a href="{{app_url}}/app/companies/settings" style="display: inline-block; padding: 12px 24px; background-color: #10b981; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500;">Access Company Settings</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                This transfer has been logged in the activity history for audit purposes.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Ownership Transfer Complete\n\nHi {{recipient_name}},\n\nThe ownership transfer of {{tenant_name}} has been completed.\n\nTransfer Details:\n• Previous Owner: {{previous_owner_name}} ({{previous_owner_email}})\n• New Owner: {{new_owner_name}} ({{new_owner_email}})\n• Previous owner has been downgraded to Admin role\n• New owner now has full administrative control\n\nAccess your company settings at: {{app_url}}/app/companies/settings\n\nThis transfer has been logged in the activity history for audit purposes.",
                'variables' => ['tenant_name', 'recipient_name', 'previous_owner_name', 'previous_owner_email', 'new_owner_name', 'new_owner_email', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'password_reset',
                'name' => 'Password Reset',
                'category' => 'system',
                'subject' => 'Reset Your Password - {{app_name}}',
                'body_html' => TransactionalEmailHtml::systemShell(<<<'HTML'
<h2 style="margin:0 0 16px;font-size:24px;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">Reset your password</h2>
<p style="margin:0 0 16px;">Hi {{user_name}},</p>
<p style="margin:0 0 20px;">We received a request to reset your password for your account. If you didn't make this request, you can safely ignore this email.</p>
<p style="margin:0 0 12px;">Click the button below to reset your password:</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr><td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);"><a href="{{reset_url}}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">Reset password</a></td></tr></table>
<p style="margin:20px 0 0;font-size:13px;color:#64748b;">Or copy and paste this link into your browser:<br><a href="{{reset_url}}" style="color:#4f46e5;word-break:break-all;">{{reset_url}}</a></p>
<div style="margin-top:24px;padding:14px 16px;background-color:#fef2f2;border-left:4px solid #dc2626;border-radius:6px;font-size:13px;color:#64748b;">
<strong style="color:#991b1b;">Security</strong> &mdash; This password reset link expires in 60 minutes. If you didn't request a reset, ignore this email; your password will stay the same.
</div>
HTML),
                'body_text' => "Reset Your Password\n\nHi {{user_name}},\n\nWe received a request to reset your password for your account. If you didn't make this request, you can safely ignore this email.\n\nReset your password by visiting: {{reset_url}}\n\nSecurity Note: This password reset link will expire in 60 minutes. If you didn't request a password reset, please ignore this email and your password will remain unchanged.",
                'variables' => ['user_name', 'user_email', 'reset_url', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'billing_trial_expiring_warning',
                'name' => 'Billing Trial Expiring Warning',
                'category' => 'tenant',
                'subject' => 'Your trial expires in {{days_until_expiration}} days - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, {{primary_color}} 0%, {{primary_color_dark}} 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: {{primary_color}};">Trial Expiring Soon</h2>
            
            <p>Hi {{owner_name}},</p>
            
            <p>
                Your trial period for <strong>{{tenant_name}}</strong> will expire in <strong>{{days_until_expiration}} day(s)</strong> on {{expiration_date}}.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 4px;">
                <strong>What happens next:</strong><br>
                When your trial expires, your plan will automatically downgrade to the Free plan. 
                To continue with full access, please upgrade to a paid plan before {{expiration_date}}.
            </p>
            
            <div style="text-align: center; margin: 24px 0;">
                <a href="{{billing_url}}" style="display: inline-block; padding: 12px 24px; background-color: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500;">Upgrade Plan</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                If you have any questions about your trial or need assistance, please don\'t hesitate to contact our support team.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Trial Expiring Soon\n\nHi {{owner_name}},\n\nYour trial period for {{tenant_name}} will expire in {{days_until_expiration}} day(s) on {{expiration_date}}.\n\nWhat happens next:\nWhen your trial expires, your plan will automatically downgrade to the Free plan. To continue with full access, please upgrade to a paid plan before {{expiration_date}}.\n\nUpgrade your plan: {{billing_url}}\n\nIf you have any questions about your trial or need assistance, please don't hesitate to contact our support team.",
                'variables' => ['tenant_name', 'owner_name', 'owner_email', 'expiration_date', 'days_until_expiration', 'app_name', 'app_url', 'billing_url'],
                'is_active' => true,
            ],
            [
                'key' => 'billing_trial_expired',
                'name' => 'Billing Trial Expired',
                'category' => 'tenant',
                'subject' => 'Your trial has expired - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, {{primary_color}} 0%, {{primary_color_dark}} 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: {{primary_color}};">Trial Expired</h2>
            
            <p>Hi {{owner_name}},</p>
            
            <p>
                Your trial period for <strong>{{tenant_name}}</strong> has expired.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fff7ed; border-left: 4px solid #f97316; border-radius: 4px;">
                <strong>What happened:</strong><br>
                Your plan has been automatically downgraded to the Free plan. You still have access to basic features, 
                but some advanced features may be limited.
            </p>
            
            <p>
                To restore full access, please upgrade to a paid plan:
            </p>
            
            <div style="text-align: center; margin: 24px 0;">
                <a href="{{billing_url}}" style="display: inline-block; padding: 12px 24px; background-color: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500;">Upgrade Plan</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                If you have any questions or need assistance, please don\'t hesitate to contact our support team.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Trial Expired\n\nHi {{owner_name}},\n\nYour trial period for {{tenant_name}} has expired.\n\nWhat happened:\nYour plan has been automatically downgraded to the Free plan. You still have access to basic features, but some advanced features may be limited.\n\nTo restore full access, please upgrade to a paid plan: {{billing_url}}\n\nIf you have any questions or need assistance, please don't hesitate to contact our support team.",
                'variables' => ['tenant_name', 'owner_name', 'owner_email', 'expiration_date', 'app_name', 'app_url', 'billing_url'],
                'is_active' => true,
            ],
            [
                'key' => 'billing_comped_expiring_warning',
                'name' => 'Billing Comped Expiring Warning',
                'category' => 'tenant',
                'subject' => 'Your complimentary plan expires in {{days_until_expiration}} days - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, {{primary_color}} 0%, {{primary_color_dark}} 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: {{primary_color}};">Complimentary Plan Expiring</h2>
            
            <p>Hi {{owner_name}},</p>
            
            <p>
                Your complimentary plan for <strong>{{tenant_name}}</strong> will expire in <strong>{{days_until_expiration}} day(s)</strong> on {{expiration_date}}.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: {{primary_color_light}}; border-left: 4px solid {{primary_color}}; border-radius: 4px;">
                <strong>What happens next:</strong><br>
                When your complimentary plan expires, your account will automatically downgrade to the Free plan. 
                To continue with your current features, please upgrade to a paid plan before {{expiration_date}}.
            </p>
            
            <div style="text-align: center; margin: 24px 0;">
                <a href="{{billing_url}}" style="display: inline-block; padding: 12px 24px; background-color: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500;">Upgrade Plan</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                If you have any questions or need assistance, please don\'t hesitate to contact our support team.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Complimentary Plan Expiring\n\nHi {{owner_name}},\n\nYour complimentary plan for {{tenant_name}} will expire in {{days_until_expiration}} day(s) on {{expiration_date}}.\n\nWhat happens next:\nWhen your complimentary plan expires, your account will automatically downgrade to the Free plan. To continue with your current features, please upgrade to a paid plan before {{expiration_date}}.\n\nUpgrade your plan: {{billing_url}}\n\nIf you have any questions or need assistance, please don't hesitate to contact our support team.",
                'variables' => ['tenant_name', 'owner_name', 'owner_email', 'expiration_date', 'days_until_expiration', 'app_name', 'app_url', 'billing_url'],
                'is_active' => true,
            ],
            [
                'key' => 'billing_comped_expired',
                'name' => 'Billing Comped Expired',
                'subject' => 'Your complimentary plan has expired - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #7c3aed;">Complimentary Plan Expired</h2>
            
            <p>Hi {{owner_name}},</p>
            
            <p>
                Your complimentary plan for <strong>{{tenant_name}}</strong> has expired.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: {{primary_color_light}}; border-left: 4px solid {{primary_color}}; border-radius: 4px;">
                <strong>What happened:</strong><br>
                Your account has been automatically downgraded to the Free plan. You still have access to basic features, 
                but some advanced features may be limited.
            </p>
            
            <p>
                To restore full access, please upgrade to a paid plan:
            </p>
            
            <div style="text-align: center; margin: 24px 0;">
                <a href="{{billing_url}}" style="display: inline-block; padding: 12px 24px; background-color: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500;">Upgrade Plan</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                If you have any questions or need assistance, please don\'t hesitate to contact our support team.
            </p>
        </div>
        <div style="background-color: #f9fafb; padding: 24px; text-align: center; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280;">
            <p>© ' . date('Y') . ' {{app_name}}. All rights reserved.</p>
            <p style="margin-top: 8px;">
                <a href="{{app_url}}" style="color: #6366f1; text-decoration: none;">Visit our website</a>
            </p>
        </div>
    </div>
</div>',
                'body_text' => "Complimentary Plan Expired\n\nHi {{owner_name}},\n\nYour complimentary plan for {{tenant_name}} has expired.\n\nWhat happened:\nYour account has been automatically downgraded to the Free plan. You still have access to basic features, but some advanced features may be limited.\n\nTo restore full access, please upgrade to a paid plan: {{billing_url}}\n\nIf you have any questions or need assistance, please don't hesitate to contact our support team.",
                'variables' => ['tenant_name', 'owner_name', 'owner_email', 'expiration_date', 'app_name', 'app_url', 'billing_url'],
                'is_active' => true,
            ],
            [
                'key' => 'support_ticket_created',
                'name' => 'Support Ticket Created (assigned staff)',
                'category' => 'system',
                'subject' => 'New support ticket {{ticket_number}} — {{ticket_subject}}',
                'body_html' => TransactionalEmailHtml::systemShell(<<<'HTML'
<h2 style="margin:0 0 16px;font-size:22px;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">New ticket assigned to you</h2>
<p style="margin:0 0 16px;">Hi {{assignee_name}},</p>
<p style="margin:0 0 20px;">A new support ticket was created and assigned to you via round-robin or default routing.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 20px;border-collapse:collapse;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
  <tr><td style="padding:14px 16px;font-size:14px;"><strong style="color:#0f172a;">Ticket</strong><br /><span style="color:#334155;">{{ticket_number}}</span></td></tr>
  <tr><td style="padding:0 16px 14px;font-size:14px;"><strong style="color:#0f172a;">Subject</strong><br /><span style="color:#334155;">{{ticket_subject}}</span></td></tr>
  <tr><td style="padding:0 16px 14px;font-size:14px;"><strong style="color:#0f172a;">Category</strong><br /><span style="color:#334155;">{{category_label}}</span></td></tr>
  <tr><td style="padding:0 16px 14px;font-size:14px;"><strong style="color:#0f172a;">Tenant</strong><br /><span style="color:#334155;">{{tenant_name}}</span></td></tr>
  <tr><td style="padding:0 16px 16px;font-size:14px;"><strong style="color:#0f172a;">Created by</strong><br /><span style="color:#334155;">{{creator_name}}</span></td></tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr><td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);"><a href="{{ticket_url}}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">Open in admin</a></td></tr></table>
<p style="margin:20px 0 0;font-size:13px;color:#64748b;">Or copy this link:<br /><a href="{{ticket_url}}" style="color:#4f46e5;word-break:break-all;">{{ticket_url}}</a></p>
HTML),
                'body_text' => "New ticket assigned to you\n\nHi {{assignee_name}},\n\nA new support ticket was created and assigned to you.\n\nTicket: {{ticket_number}}\nSubject: {{ticket_subject}}\nCategory: {{category_label}}\nTenant: {{tenant_name}}\nCreated by: {{creator_name}}\n\nOpen: {{ticket_url}}",
                'variables' => ['assignee_name', 'ticket_number', 'ticket_subject', 'category_label', 'tenant_name', 'creator_name', 'ticket_url', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'support_ticket_creator_receipt',
                'name' => 'Support ticket received (creator)',
                'category' => 'tenant',
                'subject' => 'We received your request — {{ticket_number}}',
                'body_html' => TransactionalEmailHtml::systemShell(<<<'HTML'
<h2 style="margin:0 0 16px;font-size:22px;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">Your support ticket was created</h2>
<p style="margin:0 0 16px;">Hi {{recipient_name}},</p>
<p style="margin:0 0 20px;">Thanks for reaching out. We received your request and our team will review it shortly.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 20px;border-collapse:collapse;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
  <tr><td style="padding:14px 16px;font-size:14px;"><strong style="color:#0f172a;">Ticket</strong><br /><span style="color:#334155;">{{ticket_number}}</span></td></tr>
  <tr><td style="padding:0 16px 14px;font-size:14px;"><strong style="color:#0f172a;">Subject</strong><br /><span style="color:#334155;">{{ticket_subject}}</span></td></tr>
  <tr><td style="padding:0 16px 16px;font-size:14px;"><strong style="color:#0f172a;">Company</strong><br /><span style="color:#334155;">{{tenant_name}}</span></td></tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr><td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);"><a href="{{ticket_url}}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">View your ticket</a></td></tr></table>
<p style="margin:20px 0 0;font-size:13px;color:#64748b;">Or copy this link:<br /><a href="{{ticket_url}}" style="color:#4f46e5;word-break:break-all;">{{ticket_url}}</a></p>
HTML),
                'body_text' => "Your support ticket was created\n\nHi {{recipient_name}},\n\nWe received your request — {{ticket_number}}.\nSubject: {{ticket_subject}}\nCompany: {{tenant_name}}\n\nView ticket: {{ticket_url}}",
                'variables' => ['recipient_name', 'ticket_number', 'ticket_subject', 'category_label', 'tenant_name', 'ticket_url', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'support_ticket_creator_reply',
                'name' => 'Support ticket new reply (creator)',
                'category' => 'tenant',
                'subject' => 'New reply on {{ticket_number}} — {{ticket_subject}}',
                'body_html' => TransactionalEmailHtml::systemShell(<<<'HTML'
<h2 style="margin:0 0 16px;font-size:22px;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">There is a new reply on your ticket</h2>
<p style="margin:0 0 16px;">Hi {{recipient_name}},</p>
<p style="margin:0 0 12px;"><strong>{{replier_name}}</strong> added a message to <strong>{{ticket_number}}</strong>:</p>
<p style="margin:0 0 20px;padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;color:#334155;font-size:14px;line-height:1.5;">{{reply_excerpt}}</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr><td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);"><a href="{{ticket_url}}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">View conversation</a></td></tr></table>
<p style="margin:20px 0 0;font-size:13px;color:#64748b;">Or copy this link:<br /><a href="{{ticket_url}}" style="color:#4f46e5;word-break:break-all;">{{ticket_url}}</a></p>
HTML),
                'body_text' => "New reply on your ticket\n\nHi {{recipient_name}},\n\n{{replier_name}} replied to {{ticket_number}}:\n\n{{reply_excerpt}}\n\nView: {{ticket_url}}",
                'variables' => ['recipient_name', 'replier_name', 'reply_excerpt', 'ticket_number', 'ticket_subject', 'category_label', 'tenant_name', 'ticket_url', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'support_ticket_creator_resolved',
                'name' => 'Support ticket resolved / closed (creator)',
                'category' => 'tenant',
                'subject' => 'Ticket {{ticket_number}} — {{status_label}}',
                'body_html' => TransactionalEmailHtml::systemShell(<<<'HTML'
<h2 style="margin:0 0 16px;font-size:22px;font-weight:600;color:#0f172a;letter-spacing:-0.02em;">Your ticket was {{status_label}}</h2>
<p style="margin:0 0 16px;">Hi {{recipient_name}},</p>
<p style="margin:0 0 20px;">Your support ticket <strong>{{ticket_number}}</strong> has been marked <strong>{{status_label}}</strong>.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 20px;border-collapse:collapse;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
  <tr><td style="padding:14px 16px;font-size:14px;"><strong style="color:#0f172a;">Subject</strong><br /><span style="color:#334155;">{{ticket_subject}}</span></td></tr>
  <tr><td style="padding:0 16px 16px;font-size:14px;"><strong style="color:#0f172a;">Company</strong><br /><span style="color:#334155;">{{tenant_name}}</span></td></tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;"><tr><td align="left" style="border-radius:9999px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);box-shadow:0 1px 2px rgba(0,0,0,0.08);"><a href="{{ticket_url}}" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:9999px;">View your ticket</a></td></tr></table>
<p style="margin:20px 0 0;font-size:13px;color:#64748b;">Or copy this link:<br /><a href="{{ticket_url}}" style="color:#4f46e5;word-break:break-all;">{{ticket_url}}</a></p>
HTML),
                'body_text' => "Ticket {{ticket_number}} — {{status_label}}\n\nHi {{recipient_name}},\n\nYour ticket was {{status_label}}.\nSubject: {{ticket_subject}}\nCompany: {{tenant_name}}\n\nView: {{ticket_url}}",
                'variables' => ['recipient_name', 'status_label', 'ticket_number', 'ticket_subject', 'category_label', 'tenant_name', 'ticket_url', 'app_name', 'app_url'],
                'is_active' => true,
            ],
        ];

        foreach ($templates as $template) {
            NotificationTemplate::updateOrCreate(
                ['key' => $template['key']],
                $template
            );
        }
    }
}
