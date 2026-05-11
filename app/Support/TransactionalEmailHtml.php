<?php

namespace App\Support;

/**
 * Table-based, inline-styled HTML shells for DB-driven transactional email.
 *
 * Uses {{app_url}}, {{app_name}} placeholders — replaced by {@see \App\Models\NotificationTemplate::render()}.
 *
 * Design system: light card layout on #f5f6f8 background, white card with 3px solid accent rule,
 * clean typography, flat CTAs; colors from {@see transactionalCtaPlaceholdersForSystem()} or
 * {@see transactionalCtaPlaceholdersForBrand()} at send time.
 */
final class TransactionalEmailHtml
{
    private const FONT_STACK = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

    private static function primary(): string
    {
        return (string) config('mail.branding.primary', '#7c3aed');
    }

    /**
     * Normalize brand/UI colors to 6-digit RGB (no #). Supports #RGB shorthand and #RRGGBBAA (alpha stripped).
     */
    private static function normalizeHex6(string $color): ?string
    {
        $hex = ltrim(trim($color), '#');
        if ($hex === '') {
            return null;
        }

        // #RRGGBBAA — use opaque RGB for email clients
        if (preg_match('/^([0-9a-fA-F]{6})[0-9a-fA-F]{2}$/', $hex, $m)) {
            return strtolower($m[1]);
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return strtolower($hex);
        }

        // #RGB shorthand (allowed by brand settings validation)
        if (preg_match('/^([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $hex, $m)) {
            return strtolower($m[1].$m[1].$m[2].$m[2].$m[3].$m[3]);
        }

        return null;
    }

    /**
     * Return `#rrggbb` when parsable (3/6/8-digit forms); for email shells and decorative rules — no WCAG clamp.
     */
    public static function sanitizeHexColor(?string $candidateHex): ?string
    {
        $hex = self::normalizeHex6((string) $candidateHex);

        return $hex !== null ? '#'.$hex : null;
    }

    /**
     * Dark, readable link color on white/light body copy (WCAG-ish ≥ 4.5:1 vs white) from a brand hue seed.
     */
    public static function readableLinkHexOnWhite(?string $seedHex): string
    {
        $h = self::normalizeHex6((string) $seedHex);
        if ($h === null || self::isNearWhiteHex6($h)) {
            return '#0f172a';
        }

        return self::darkenHex6TowardBlackForTextOnWhite($h, 4.5);
    }

    private static function primaryNormalizedHex(): string
    {
        return self::normalizeHex6(self::primary()) ?? '7c3aed';
    }

    private static function linearizeSrgbChannel(float $c): float
    {
        return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    }

    /** WCAG relative luminance for #RRGGBB (no hash). */
    private static function relativeLuminanceFromNormalizedHex6(string $hex6): float
    {
        $r = hexdec(substr($hex6, 0, 2)) / 255;
        $g = hexdec(substr($hex6, 2, 2)) / 255;
        $b = hexdec(substr($hex6, 4, 2)) / 255;

        return 0.2126 * self::linearizeSrgbChannel($r)
            + 0.7152 * self::linearizeSrgbChannel($g)
            + 0.0722 * self::linearizeSrgbChannel($b);
    }

    /** Contrast of white (#fff) text over a solid background of luminance $bgLum (large text / UI: ≥ 3). */
    private static function contrastWhiteTextOnBackground(float $bgLum): float
    {
        return (1.05) / ($bgLum + 0.05);
    }

    /** Contrast of dark text (luminance $fgLum) on white (#fff) background (normal text: aim ≥ 4.5). */
    private static function contrastDarkTextOnWhite(float $fgLum): float
    {
        return (1.05) / ($fgLum + 0.05);
    }

    private static function isNearWhiteHex6(string $hex6): bool
    {
        $r = hexdec(substr($hex6, 0, 2));
        $g = hexdec(substr($hex6, 2, 2));
        $b = hexdec(substr($hex6, 4, 2));

        return $r >= 245 && $g >= 245 && $b >= 245;
    }

    /**
     * Darken RGB toward black until white-on-fill contrast ≥ $minRatio (preserves hue roughly).
     *
     * @param  string  $hex6  six hex chars, no #
     */
    private static function darkenHex6TowardBlackForWhiteText(string $hex6, float $minRatio = 3.0): string
    {
        $r = hexdec(substr($hex6, 0, 2));
        $g = hexdec(substr($hex6, 2, 2));
        $b = hexdec(substr($hex6, 4, 2));

        for ($i = 0; $i < 56; $i++) {
            $lum = self::relativeLuminanceFromNormalizedHex6(sprintf('%02x%02x%02x', $r, $g, $b));
            if (self::contrastWhiteTextOnBackground($lum) >= $minRatio) {
                return '#'.sprintf('%02x%02x%02x', $r, $g, $b);
            }
            $r = (int) max(0, (int) floor($r * 0.90));
            $g = (int) max(0, (int) floor($g * 0.90));
            $b = (int) max(0, (int) floor($b * 0.90));
        }

        return '#0f172a';
    }

    /**
     * Darken toward black until normal-sized text on white meets contrast (links in body copy).
     *
     * @param  string  $hex6  six hex chars, no #
     */
    private static function darkenHex6TowardBlackForTextOnWhite(string $hex6, float $minRatio = 4.5): string
    {
        $r = hexdec(substr($hex6, 0, 2));
        $g = hexdec(substr($hex6, 2, 2));
        $b = hexdec(substr($hex6, 4, 2));

        for ($i = 0; $i < 56; $i++) {
            $lum = self::relativeLuminanceFromNormalizedHex6(sprintf('%02x%02x%02x', $r, $g, $b));
            if (self::contrastDarkTextOnWhite($lum) >= $minRatio) {
                return '#'.sprintf('%02x%02x%02x', $r, $g, $b);
            }
            $r = (int) max(0, (int) floor($r * 0.90));
            $g = (int) max(0, (int) floor($g * 0.90));
            $b = (int) max(0, (int) floor($b * 0.90));
        }

        return '#0f172a';
    }

    /**
     * Raw fill color from {@see Brand::$workspace_button_style} (aligned with `getWorkspaceButtonColor` in the SPA).
     * Email CTAs always use white label text — “white” and near-white fills are returned as null to force a dark derived fill.
     */
    private static function rawWorkspaceButtonFillForEmail(\App\Models\Brand $brand): ?string
    {
        $settings = is_array($brand->settings) ? $brand->settings : [];
        $style = $brand->workspace_button_style ?? ($settings['button_style'] ?? 'primary');
        $style = is_string($style) ? strtolower(trim($style)) : 'primary';

        $primary = $brand->primary_color;
        $secondary = $brand->secondary_color;
        $accent = $brand->accent_color;

        return match ($style) {
            'context' => $primary,
            'primary' => $primary,
            'secondary' => $secondary ?: $primary,
            // Accent is often black; on white email cards users expect brand hue — still honor style, but
            // {@see transactionalCtaPlaceholdersForBrand()} darkens primary-family colors instead of swapping to accent when primary fails WCAG.
            'accent' => $accent ?: $primary,
            'white' => null,
            'black' => '#000000',
            default => $primary,
        };
    }

    /**
     * Solid hex for CTA buttons, links, and card top bar. Validates hex and WCAG contrast vs white text (ratio ≥ 3:1).
     * Empty or low-contrast brand colors fall back to {@see config('mail.branding.primary')}.
     */
    public static function safePrimaryButtonHex(?string $candidateHex): string
    {
        $fallback = '#'.self::primaryNormalizedHex();

        if ($candidateHex === null || trim($candidateHex) === '') {
            return $fallback;
        }

        $hex = self::normalizeHex6($candidateHex);
        if ($hex === null) {
            return $fallback;
        }

        $lum = self::relativeLuminanceFromNormalizedHex6($hex);
        $contrast = self::contrastWhiteTextOnBackground($lum);

        return $contrast >= 3.0 ? '#'.$hex : $fallback;
    }

    /**
     * Placeholders for DB templates using {@see systemShell()} — site primary (flat).
     *
     * @return array{primary_button_color: string, link_accent_color: string, card_accent_bar: string}
     */
    public static function transactionalCtaPlaceholdersForSystem(): array
    {
        $hex = self::safePrimaryButtonHex(self::primary());

        return [
            'primary_button_color' => $hex,
            'link_accent_color' => $hex,
            'card_accent_bar' => $hex,
        ];
    }

    /**
     * Placeholders for tenant/brand emails on light backgrounds:
     * - Button fill follows {@see Brand::$workspace_button_style} then is **darkened in-place** until white label text passes WCAG large-target contrast (≥ 3:1) — avoids jumping to accent black when primary orange is slightly too light.
     * - Card top bar uses **brand primary** when visible (not near-white); otherwise the button fill.
     * - Inline URLs use a **darkened primary** (or neutral) for readable normal text on white — not raw accent on white.
     *
     * @return array{primary_button_color: string, link_accent_color: string, card_accent_bar: string}
     */
    public static function transactionalCtaPlaceholdersForBrand(?\App\Models\Brand $brand): array
    {
        if ($brand === null) {
            return self::transactionalCtaPlaceholdersForSystem();
        }

        $primary6 = self::normalizeHex6((string) $brand->primary_color);
        $secondary6 = self::normalizeHex6((string) $brand->secondary_color);

        $rawFill = self::rawWorkspaceButtonFillForEmail($brand);
        $seed6 = self::normalizeHex6((string) $rawFill);

        if ($rawFill === null) {
            // “White” button style — cannot use white fill with white label in templates; derive from primary.
            $seed6 = $primary6 ?? self::primaryNormalizedHex();
        } elseif ($seed6 !== null && self::isNearWhiteHex6($seed6)) {
            // Near-white fill is invalid for white button labels — prefer primary, then site hue.
            $seed6 = $primary6 ?? self::primaryNormalizedHex();
        } elseif ($seed6 === null) {
            $seed6 = $primary6 ?? self::primaryNormalizedHex();
        }

        $primaryButtonColor = self::darkenHex6TowardBlackForWhiteText($seed6, 3.0);

        // Decorative bar: show true brand primary when it’s not a white line.
        $cardAccentBar = ($primary6 !== null && ! self::isNearWhiteHex6($primary6))
            ? '#'.$primary6
            : $primaryButtonColor;

        // Links in body copy sit on white — use primary family darkened for normal text (≥ 4.5:1), never light accent on white.
        $linkSeed = $primary6 ?? $secondary6;
        if ($linkSeed === null || self::isNearWhiteHex6($linkSeed)) {
            $linkAccentColor = '#0f172a';
        } else {
            $linkAccentColor = self::darkenHex6TowardBlackForTextOnWhite($linkSeed, 4.5);
        }

        return [
            'primary_button_color' => $primaryButtonColor,
            'link_accent_color' => $linkAccentColor,
            'card_accent_bar' => $cardAccentBar,
        ];
    }

    /**
     * System emails: Jackpot branding — cherry icon + wordmark header, solid accent bar (flat).
     */
    public static function systemShell(string $cardInnerHtml, ?string $copyrightYear = null): string
    {
        $y    = $copyrightYear ?? date('Y');
        $font = self::FONT_STACK;

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
              <tr><td style="height:3px;background-color:{{card_accent_bar}};font-size:0;line-height:0;">&nbsp;</td></tr>
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
        $y    = $copyrightYear ?? date('Y');
        $font = self::FONT_STACK;

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
              <tr><td style="height:3px;background-color:{{card_accent_bar}};font-size:0;line-height:0;">&nbsp;</td></tr>
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
