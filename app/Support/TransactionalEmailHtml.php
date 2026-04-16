<?php

namespace App\Support;

/**
 * Table-based, inline-styled HTML shells for DB-driven transactional email.
 *
 * Uses {{app_url}}, {{app_name}} placeholders — replaced by {@see \App\Models\NotificationTemplate::render()}.
 *
 * Design system: light card layout on #f5f6f8 background, white card with 3px accent rule,
 * clean typography, indigo/violet accent (system) or tenant color (tenant mode).
 */
final class TransactionalEmailHtml
{
    private const FONT_STACK = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
    private const INDIGO     = '#4f46e5';
    private const GRADIENT   = 'linear-gradient(90deg,#4f46e5 0%,#7c3aed 50%,#06b6d4 100%)';

    /**
     * System emails: Jackpot branding — cherry icon + wordmark header, gradient accent.
     */
    public static function systemShell(string $cardInnerHtml, ?string $copyrightYear = null): string
    {
        $y    = $copyrightYear ?? date('Y');
        $font = self::FONT_STACK;
        $grad = self::GRADIENT;

        return <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f5f6f8;margin:0;padding:0;font-family:{$font};">
  <tr>
    <td align="center" style="padding:32px 16px;">

      <!-- Header -->
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
        <tr>
          <td style="padding:0 0 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="vertical-align:middle;padding-right:10px;">
                  <img src="{{app_url}}/icons/pwa-192.png" alt="" width="28" height="28" style="display:block;width:28px;height:28px;border-radius:6px;border:0;" />
                </td>
                <td style="vertical-align:middle;">
                  <span style="font-size:15px;font-weight:700;color:#111827;letter-spacing:-0.01em;">{{app_name}}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <!-- Card -->
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
        <tr>
          <td>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
              <tr><td style="height:3px;background:{$grad};font-size:0;line-height:0;">&nbsp;</td></tr>
              <tr>
                <td style="padding:36px 40px 40px;color:#374151;font-size:15px;line-height:1.65;">
                  {$cardInnerHtml}
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <!-- Footer -->
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
        <tr>
          <td style="padding:24px 4px 0;text-align:center;">
            <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;line-height:1.5;">&copy; {$y} {{app_name}}. All rights reserved.</p>
            <p style="margin:0;font-size:12px;"><a href="{{app_url}}" style="color:#6b7280;text-decoration:none;">{{app_name}}</a></p>
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>
HTML;
    }

    /**
     * Tenant-scoped emails: tenant identity first, "via Jackpot" treatment, powered-by footer.
     */
    public static function tenantShell(string $cardInnerHtml, ?string $copyrightYear = null, ?string $headerCaptionLine = null): string
    {
        $y       = $copyrightYear ?? date('Y');
        $font    = self::FONT_STACK;
        $indigo  = self::INDIGO;

        return <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f5f6f8;margin:0;padding:0;font-family:{$font};">
  <tr>
    <td align="center" style="padding:32px 16px;">

      <!-- Tenant Header -->
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
        <tr>
          <td style="padding:0 0 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                {{tenant_logo_block}}
                <td style="vertical-align:middle;text-align:right;">
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table;">
                    <tr>
                      <td style="vertical-align:middle;padding-right:6px;">
                        <img src="{{app_url}}/icons/pwa-192.png" alt="" width="18" height="18" style="display:block;width:18px;height:18px;border-radius:4px;border:0;" />
                      </td>
                      <td style="vertical-align:middle;">
                        <span style="font-size:11px;color:#9ca3af;font-weight:500;">via {{app_name}}</span>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <!-- Card -->
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
        <tr>
          <td>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
              <tr><td style="height:3px;background:{$indigo};font-size:0;line-height:0;">&nbsp;</td></tr>
              <tr>
                <td style="padding:36px 40px 40px;color:#374151;font-size:15px;line-height:1.65;">
                  {$cardInnerHtml}
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <!-- Footer -->
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;">
        <tr>
          <td style="padding:24px 4px 0;text-align:center;">
            <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;line-height:1.5;">Sent via <a href="{{app_url}}" style="color:#6b7280;text-decoration:none;">{{app_name}}</a></p>
            <p style="margin:0;font-size:11px;color:#d1d5db;">&copy; {$y} {{tenant_name}}</p>
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>
HTML;
    }

    /**
     * Tenant logo block: returns `<td>` cell(s) for insertion into a `<tr>` alongside "via Jackpot".
     * Used as {{tenant_logo_block}} in DB-stored notification templates.
     */
    public static function tenantLogoBlockFromBrand(?\App\Models\Brand $brand): string
    {
        if ($brand === null) {
            return '<td style="vertical-align:middle;"></td>';
        }

        $url = $brand->logoUrlForTransactionalEmail();
        if ($url !== null && $url !== '') {
            $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $alt  = htmlspecialchars($brand->name, ENT_QUOTES, 'UTF-8');
            $img  = '<img src="'.$safe.'" alt="'.$alt.'" width="140" height="36" style="display:block;max-height:36px;max-width:160px;width:auto;height:auto;border:0;" />';

            return '<td style="vertical-align:middle;">'.$img.'</td>';
        }

        $name = trim((string) $brand->name);
        if ($name === '') {
            return '<td style="vertical-align:middle;"></td>';
        }

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $wordmark = '<span style="font-size:16px;font-weight:700;color:#111827;letter-spacing:-0.01em;line-height:1.2;">'.$safeName.'</span>';

        return '<td style="vertical-align:middle;">'.$wordmark.'</td>';
    }
}
