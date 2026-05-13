<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\AssetDeliveryService;
use App\Services\AssetVariantPathResolver;
use App\Services\CloudFrontSignedUrlService;
use App\Support\AssetVariant;
use App\Support\AuthenticatedCrossOriginDeliveryPolicy;
use App\Support\DeliveryContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Verify S3 object, resolved variant path, CloudFront signing config, and delivery URL shape for an asset.
 *
 * Usage: php artisan assets:diagnose-delivery 019e1d74-95f4-7342-900a-a42b6558cdd9 --variant=original
 */
class AssetsDiagnoseDeliveryCommand extends Command
{
    protected $signature = 'assets:diagnose-delivery
                            {asset_id : Asset UUID}
                            {--variant=original : AssetVariant value (e.g. original, preview_3d_glb, video_web, video_preview)}';

    protected $description = 'Diagnose CDN delivery: S3 key, HEAD/exists, CloudFront signing config, and AUTHENTICATED URL mode';

    public function handle(
        AssetVariantPathResolver $pathResolver,
        AssetDeliveryService $delivery,
        CloudFrontSignedUrlService $signedUrlService
    ): int {
        $id = (string) $this->argument('asset_id');
        $variantArg = strtolower(trim((string) $this->option('variant')));

        $asset = Asset::query()->find($id);
        if (! $asset) {
            $this->error("Asset not found: {$id}");

            return self::FAILURE;
        }

        $variantEnum = AssetVariant::tryFrom($variantArg);
        if (! $variantEnum) {
            $this->error("Unknown variant: {$variantArg}. Use an AssetVariant case value (e.g. original, preview_3d_glb).");

            return self::FAILURE;
        }

        $this->info("Asset {$asset->id}");
        $this->line('  mime_type: '.($asset->mime_type ?? '(null)'));
        $this->line('  original_filename: '.($asset->original_filename ?? '(null)'));
        $this->line('  storage_root_path: '.($asset->storage_root_path ?? '(null)'));
        $this->newLine();

        $path = $pathResolver->resolve($asset, $variantEnum->value, []);
        $this->line('Resolved object key: '.($path !== '' ? $path : '(empty)'));
        $this->line('Default filesystem disk: '.config('filesystems.default', 's3'));
        $this->line('CLOUDFRONT_DOMAIN: '.(config('cloudfront.domain') !== '' ? config('cloudfront.domain') : '(empty)'));

        $keyPath = config('cloudfront.private_key_path', '');
        $resolvedKey = str_starts_with((string) $keyPath, '/') ? $keyPath : base_path((string) $keyPath);
        $signingOk = config('cloudfront.domain') && config('cloudfront.key_pair_id') && $keyPath !== '' && is_file($resolvedKey);
        $this->line('CloudFront signing config OK: '.($signingOk ? 'yes' : 'no').' (private key: '.($keyPath !== '' ? $resolvedKey : 'unset').')');

        if ($path !== '') {
            try {
                $exists = Storage::disk('s3')->exists($path);
                $this->line('S3 exists('.$path.'): '.($exists ? 'yes' : 'no'));
            } catch (\Throwable $e) {
                $this->warn('S3 exists check failed: '.$e->getMessage());
            }
        }

        $crossOriginSigned = AuthenticatedCrossOriginDeliveryPolicy::requiresSignedCloudFrontUrl($variantEnum, $asset);
        $this->line('Authenticated cross-origin signed URL policy applies: '.($crossOriginSigned ? 'yes' : 'no'));
        $this->line('Cross-origin media TTL (seconds): '.$signedUrlService->getAuthenticatedCrossOriginMediaTtl());

        if (app()->environment(['local', 'testing'])) {
            $this->warn('APP_ENV is local/testing — AssetDeliveryService returns early (no CloudFront signing in this command\'s PHP env).');

            return self::SUCCESS;
        }

        try {
            $url = $delivery->url($asset, $variantEnum->value, DeliveryContext::AUTHENTICATED->value, []);
        } catch (\Throwable $e) {
            $this->error('delivery->url failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $looksSigned = str_contains($url, 'Signature=') || str_contains($url, 'Policy=') || str_contains($url, 'Key-Pair-Id=');
        $this->newLine();
        $this->line('AUTHENTICATED delivery URL (truncated):');
        $this->line('  '.substr($url, 0, 220).(strlen($url) > 220 ? '…' : ''));
        $this->line('  mode: '.($looksSigned ? 'signed_url' : 'plain_cdn (expect signed cookies in browser)'));

        $this->newLine();
        $this->line('Tip: CloudFront 403 with plain_cdn on GLB usually means signed cookies were not sent (e.g. CORS anonymous).');
        $this->line('Tip: CloudFront 403 on signed_url → key policy, OAC, or URL signature mismatch.');

        return self::SUCCESS;
    }
}
