<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Tenancy\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSettingController extends Controller
{
    /** @var array<string, list<string>> */
    private const EDITABLE_RULES = [
        'billing.default_promotional_days' => ['required', 'integer', 'between:0,3650'],
    ];

    public function index(): JsonResponse
    {
        return response()->json(['data' => Setting::query()->orderBy('namespace')->orderBy('key')->get()
            ->map(fn (Setting $setting) => $this->projection($setting))]);
    }

    public function update(Request $request, string $namespace, string $key, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate(['value' => ['present'], 'version' => ['required', 'integer', 'min:1']]);
        $setting = Setting::query()->where('namespace', $namespace)->where('key', $key)->firstOrFail();
        if ($setting->is_secret) {
            throw new ApiException('SECRET_SETTING_MANAGED_EXTERNALLY', 'Secret settings are managed by the deployment secret manager.');
        }
        $rules = self::EDITABLE_RULES[$setting->namespace.'.'.$setting->key] ?? null;
        if ($rules === null) {
            throw new ApiException('SETTING_NOT_EDITABLE', 'This setting is not editable through the general administration endpoint.');
        }
        $request->validate(['value' => $rules]);
        if ((int) $setting->version !== (int) $validated['version']) {
            throw new ApiException('SETTING_VERSION_CONFLICT', 'The setting changed since it was loaded.', 409, ['current_version' => (int) $setting->version]);
        }
        DB::transaction(function () use ($request, $audit, $setting, $validated): void {
            $locked = Setting::query()->whereKey($setting->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->version !== (int) $validated['version']) {
                throw new ApiException('SETTING_VERSION_CONFLICT', 'The setting changed since it was loaded.', 409, ['current_version' => (int) $locked->version]);
            }
            $before = ['version' => (int) $locked->version, 'value' => $locked->value];
            $locked->value = $validated['value'];
            $locked->version++;
            $locked->save();
            $audit->recordEntity($request, 'setting.updated', 'setting', $locked->id, $before, ['version' => (int) $locked->version, 'value' => $locked->value]);
        });

        return response()->json(['data' => $this->projection($setting->refresh())]);
    }

    /** @return array<string, mixed> */
    private function projection(Setting $setting): array
    {
        return [
            'id' => $setting->id, 'namespace' => $setting->namespace, 'key' => $setting->key,
            'value' => $setting->is_secret ? null : $setting->value, 'secret' => (bool) $setting->is_secret,
            'version' => (int) $setting->version,
        ];
    }
}
