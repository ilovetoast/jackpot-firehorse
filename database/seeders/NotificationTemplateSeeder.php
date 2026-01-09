<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
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
                'subject' => 'You\'ve been invited to join {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0;">You\'ve been invited!</h2>
            
            <p>Hi there,</p>
            
            <p>
                <strong>{{inviter_name}}</strong> has invited you to join 
                <strong>{{tenant_name}}</strong> on {{app_name}}.
            </p>
            
            <p>
                Click the button below to accept the invitation and create your account:
            </p>
            
            <div style="text-align: center;">
                <a href="{{invite_url}}" style="display: inline-block; padding: 12px 24px; background-color: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500; margin: 16px 0;">Accept Invitation</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                Or copy and paste this link into your browser:<br>
                <a href="{{invite_url}}" style="color: #6366f1; word-break: break-all;">{{invite_url}}</a>
            </p>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                If you didn\'t expect this invitation, you can safely ignore this email.
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
                'body_text' => "You've been invited!\n\nHi there,\n\n{{inviter_name}} has invited you to join {{tenant_name}} on {{app_name}}.\n\nAccept your invitation by visiting: {{invite_url}}\n\nIf you didn't expect this invitation, you can safely ignore this email.",
                'variables' => ['tenant_name', 'inviter_name', 'invite_url', 'app_name', 'app_url'],
                'is_active' => true,
            ],
            [
                'key' => 'account_canceled',
                'name' => 'Account Canceled',
                'subject' => 'Your account has been canceled - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #ea580c;">Account Canceled</h2>
            
            <p>Hi {{user_name}},</p>
            
            <p>
                Your account has been canceled and removed from <strong>{{tenant_name}}</strong> on {{app_name}}.
            </p>
            
            <p>
                This means you no longer have access to {{tenant_name}}\'s workspace, but your account remains active 
                and you can still log in if you have access to other organizations.
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fff7ed; border-left: 4px solid #f97316; border-radius: 4px;">
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
                to {{tenant_name}}'s workspace.
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
                'subject' => 'Confirm Ownership Transfer - {{tenant_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0; color: #d97706;">Confirm Ownership Transfer</h2>
            
            <p>Hi {{current_owner_name}},</p>
            
            <p>
                You have initiated a request to transfer ownership of <strong>{{tenant_name}}</strong> to 
                <strong>{{new_owner_name}}</strong> ({{new_owner_email}}).
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 4px;">
                <strong>Important:</strong> This action will transfer all ownership rights and responsibilities to {{new_owner_name}}. 
                You will be downgraded to an Admin role after the transfer is completed.
            </p>
            
            <p>
                Click the button below to confirm this transfer:
            </p>
            
            <div style="text-align: center;">
                <a href="{{confirmation_url}}" style="display: inline-block; padding: 12px 24px; background-color: #f59e0b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500; margin: 16px 0;">Confirm Transfer</a>
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
                'subject' => 'Reset Your Password - {{app_name}}',
                'body_html' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0;">
    <div style="background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #ffffff; padding: 24px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 600;">{{app_name}}</h1>
        </div>
        <div style="padding: 32px 24px;">
            <h2 style="margin-top: 0;">Reset Your Password</h2>
            
            <p>Hi {{user_name}},</p>
            
            <p>
                We received a request to reset your password for your account. If you didn\'t make this request, you can safely ignore this email.
            </p>
            
            <p>
                Click the button below to reset your password:
            </p>
            
            <div style="text-align: center;">
                <a href="{{reset_url}}" style="display: inline-block; padding: 12px 24px; background-color: #6366f1; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 500; margin: 16px 0;">Reset Password</a>
            </div>
            
            <p style="margin-top: 24px; font-size: 14px; color: #6b7280;">
                Or copy and paste this link into your browser:<br>
                <a href="{{reset_url}}" style="color: #6366f1; word-break: break-all;">{{reset_url}}</a>
            </p>
            
            <p style="margin-top: 24px; padding: 16px; background-color: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px; font-size: 14px;">
                <strong>Security Note:</strong> This password reset link will expire in 60 minutes. If you didn\'t request a password reset, please ignore this email and your password will remain unchanged.
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
                'body_text' => "Reset Your Password\n\nHi {{user_name}},\n\nWe received a request to reset your password for your account. If you didn't make this request, you can safely ignore this email.\n\nReset your password by visiting: {{reset_url}}\n\nSecurity Note: This password reset link will expire in 60 minutes. If you didn't request a password reset, please ignore this email and your password will remain unchanged.",
                'variables' => ['user_name', 'user_email', 'reset_url', 'app_name', 'app_url'],
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
