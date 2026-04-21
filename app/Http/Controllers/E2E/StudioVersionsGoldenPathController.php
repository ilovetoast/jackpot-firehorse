<?php

namespace App\Http\Controllers\E2E;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandOnboardingProgress;
use App\Models\Composition;
use App\Models\CompositionVersion;
use App\Models\CreativeSet;
use App\Models\CreativeSetVariant;
use App\Models\GenerationJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a minimal tenant / brand / composition in a Versions set and logs in for Playwright.
 *
 * @see config/e2e_studio_versions.php
 */
final class StudioVersionsGoldenPathController extends Controller
{
    private const TENANT_SLUG = 'e2e-studio-versions';

    private const SET_NAME = '__e2e_studio_versions__';

    private const USER_EMAIL = 'e2e-studio-versions@jackpot.test';

    public function bootstrap(Request $request): RedirectResponse
    {
        abort_unless(config('e2e_studio_versions.enabled'), 404);

        $expected = (string) config('e2e_studio_versions.token');
        abort_unless($expected !== '', 404);

        $token = (string) $request->query('token', '');
        abort_unless(hash_equals($expected, $token), 403);

        abort_unless(in_array(app()->environment(), ['local', 'testing'], true), 404);

        [$compositionId] = DB::transaction(function (): array {
            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => self::TENANT_SLUG],
                [
                    'name' => 'E2E Studio Versions',
                    'uuid' => (string) Str::uuid(),
                ],
            );
            if ($tenant->uuid === null || $tenant->uuid === '') {
                $tenant->forceFill(['uuid' => (string) Str::uuid()])->save();
            }

            $brand = Brand::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => 'e2e-sv-brand',
                ],
                [
                    'name' => 'E2E SV Brand',
                ],
            );

            BrandOnboardingProgress::query()->updateOrCreate(
                ['brand_id' => $brand->id],
                [
                    'tenant_id' => $tenant->id,
                    'current_step' => 'complete',
                    'activated_at' => now(),
                ],
            );

            $user = User::query()->firstOrCreate(
                ['email' => self::USER_EMAIL],
                [
                    'first_name' => 'E2E',
                    'last_name' => 'Studio',
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ],
            );

            if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
                $user->tenants()->attach($tenant->id, ['role' => 'owner']);
            }
            if (! $user->brands()->where('brands.id', $brand->id)->exists()) {
                $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
            }

            $existing = CreativeSet::query()->where('tenant_id', $tenant->id)->where('name', self::SET_NAME)->first();
            if ($existing instanceof CreativeSet) {
                GenerationJob::query()->where('creative_set_id', $existing->id)->delete();
                $compositionIds = $existing->variants()->pluck('composition_id')->all();
                $existing->delete();
                if ($compositionIds !== []) {
                    Composition::query()->whereIn('id', $compositionIds)->delete();
                }
            }

            $doc = [
                'width' => 1080,
                'height' => 1080,
                'layers' => [],
            ];

            $composition = Composition::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'visibility' => Composition::VISIBILITY_SHARED,
                'name' => 'E2E Product Ad',
                'document_json' => $doc,
            ]);

            CompositionVersion::query()->create([
                'composition_id' => $composition->id,
                'document_json' => $doc,
                'label' => null,
                'kind' => CompositionVersion::KIND_MANUAL,
                'created_at' => now(),
            ]);

            $set = CreativeSet::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'name' => self::SET_NAME,
                'status' => CreativeSet::STATUS_ACTIVE,
            ]);

            CreativeSetVariant::query()->create([
                'creative_set_id' => $set->id,
                'composition_id' => $composition->id,
                'sort_order' => 0,
                'label' => 'Base',
                'status' => CreativeSetVariant::STATUS_READY,
                'axis' => null,
            ]);

            return [(int) $composition->id];
        });

        Auth::login(User::query()->where('email', self::USER_EMAIL)->firstOrFail(), true);

        $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->firstOrFail();
        $brand = Brand::query()->where('tenant_id', $tenant->id)->where('slug', 'e2e-sv-brand')->firstOrFail();

        $request->session()->put('tenant_id', $tenant->id);
        $request->session()->put('brand_id', $brand->id);

        return redirect()->to('/app/generative?composition='.$compositionId);
    }
}
