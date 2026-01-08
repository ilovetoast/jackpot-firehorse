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
        ];

        foreach ($templates as $template) {
            NotificationTemplate::updateOrCreate(
                ['key' => $template['key']],
                $template
            );
        }
    }
}
