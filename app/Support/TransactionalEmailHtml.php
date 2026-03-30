<?php

namespace App\Support;

/**
 * Table-based, inline-styled HTML shells for transactional email (Stripe-like layout).
 * Uses {{app_url}}, {{app_name}} placeholders — replaced by {@see NotificationTemplate::render()}.
 */
final class TransactionalEmailHtml
{
    /**
     * System emails: light header + Jackpot logo + gradient accent stripe (readable in all clients).
     *
     * @param  string  $cardInnerHtml  Body HTML inside the white card (headings, copy, CTA)
     */
    public static function systemShell(string $cardInnerHtml, ?string $copyrightYear = null): string
    {
        $y = $copyrightYear ?? date('Y');

        return <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f5f7;margin:0;padding:24px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
  <tr>
    <td align="center">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);border:1px solid #e8e8e8;">
        <tr>
          <td style="background:#ffffff;padding:0;border-bottom:1px solid #e8e8e8;">
            <div style="height:3px;background:linear-gradient(90deg,#4f46e5 0%,#7c3aed 50%,#06b6d4 100%);"></div>
            <div style="padding:24px 28px 20px;">
              <img src="{{app_url}}/jp-logo.svg" alt="Jackpot" width="132" height="32" style="display:block;height:32px;width:auto;max-width:100%;border:0;outline:none;" />
            </div>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 32px 36px;color:#425466;font-size:15px;line-height:1.6;">
            {$cardInnerHtml}
          </td>
        </tr>
      </table>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;">
        <tr>
          <td style="padding:20px 8px 8px;font-size:12px;line-height:1.5;color:#8898aa;text-align:center;">
            <p style="margin:0 0 8px;">© {$y} {{app_name}}. All rights reserved.</p>
            <p style="margin:0;"><a href="{{app_url}}" style="color:#556cd6;text-decoration:none;">{{app_name}}</a></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;
    }

    /**
     * Tenant-scoped emails (invite): white header + Jackpot + optional tenant logo cells from {@see tenantLogoBlockFromBrand()}.
     *
     * @param  string  $cardInnerHtml  Body below the dual-logo row
     */
    public static function tenantShell(string $cardInnerHtml, ?string $copyrightYear = null): string
    {
        $y = $copyrightYear ?? date('Y');

        return <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f5f7;margin:0;padding:24px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
  <tr>
    <td align="center">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);border:1px solid #e8e8e8;">
        <tr>
          <td style="background:#ffffff;padding:0;border-bottom:1px solid #e8e8e8;">
            <div style="height:3px;background:linear-gradient(90deg,#4f46e5 0%,#7c3aed 50%,#06b6d4 100%);"></div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td style="padding:20px 24px 16px;vertical-align:middle;">
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td style="vertical-align:middle;padding-right:16px;">
                        <img src="{{app_url}}/jp-logo.svg" alt="Jackpot" width="120" height="28" style="display:block;height:28px;width:auto;max-width:100%;border:0;" />
                      </td>
                      {{tenant_logo_block}}
                    </tr>
                  </table>
                  <p style="margin:12px 0 0;font-size:11px;letter-spacing:0.06em;text-transform:uppercase;color:#64748b;">Invitation · {{tenant_name}}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 32px 36px;color:#425466;font-size:15px;line-height:1.6;">
            {$cardInnerHtml}
          </td>
        </tr>
      </table>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;">
        <tr>
          <td style="padding:20px 8px 8px;font-size:12px;line-height:1.5;color:#8898aa;text-align:center;">
            <p style="margin:0 0 8px;">© {$y} {{app_name}}. All rights reserved.</p>
            <p style="margin:0;">This message was sent by {{app_name}} on behalf of {{tenant_name}}.</p>
            <p style="margin:8px 0 0;"><a href="{{app_url}}" style="color:#556cd6;text-decoration:none;">Visit {{app_name}}</a></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;
    }

    /**
     * Extra table cells after Jackpot logo: thin divider + tenant logo (or empty string).
     */
    public static function tenantLogoBlockFromBrand(?\App\Models\Brand $brand): string
    {
        if ($brand === null) {
            return '';
        }

        $url = $brand->logoUrlForGuest(false);
        if ($url === null || $url === '') {
            return '';
        }

        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $img = '<img src="'.$safe.'" alt="" width="140" height="40" style="display:inline-block;max-height:40px;max-width:160px;width:auto;height:auto;vertical-align:middle;border-radius:6px;border:1px solid rgba(15,23,42,0.1);" />';

        return '<td style="width:1px;background:#e5e7eb;font-size:0;line-height:0;">&nbsp;</td>'
            .'<td style="vertical-align:middle;padding-left:16px;">'.$img.'</td>';
    }
}
