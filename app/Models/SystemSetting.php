<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type', 'label'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('cohers.settings'));
        static::deleted(fn () => Cache::forget('cohers.settings'));
    }

    /** All settings keyed by name. Secrets stay encrypted here (and in the cache). */
    public static function all_cached(): array
    {
        $cached = Cache::get('cohers.settings');
        if (is_array($cached)) {
            return $cached;
        }
        if (! Schema::hasTable('system_settings')) {
            return []; // during migrations: never cache an empty result
        }

        $all = static::query()->get()->mapWithKeys(fn ($s) => [$s->key => $s->castValue()])->all();
        Cache::forever('cohers.settings', $all);

        return $all;
    }

    /** Runtime value: DB setting overrides config/cohers.php. */
    public static function get_value(string $key, mixed $default = null): mixed
    {
        $all = static::all_cached();

        return array_key_exists($key, $all) ? $all[$key] : config('cohers.'.$key, $default);
    }

    /** Store a secret encrypted with APP_KEY; an empty value removes the override. */
    public static function putSecret(string $key, ?string $plain, string $group): void
    {
        if ($plain === null || $plain === '') {
            static::where('key', $key)->get()->each->delete();

            return;
        }
        static::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($plain), 'type' => 'secret', 'group' => $group]);
    }

    /**
     * Push DB overrides into config('cohers.*') so every service that reads
     * config — Gemini, Telegram, targets — picks up admin changes without
     * editing .env. Called once per request/command from AppServiceProvider.
     */
    public static function applyToConfig(): void
    {
        try {
            foreach (static::all_cached() as $key => $value) {
                if (str_starts_with($key, 'msg.') || $value === null || $value === '') {
                    continue;
                }
                if (is_string($value) && str_starts_with($value, 'secret:')) {
                    try {
                        $value = Crypt::decryptString(substr($value, 7));
                    } catch (Throwable) {
                        continue; // APP_KEY changed: ignore unreadable secret
                    }
                }
                config(['cohers.'.$key => $value]);
            }
        } catch (Throwable) {
            // Database or cache not ready (fresh install, migrations): keep .env values.
        }
    }

    public function castValue(): mixed
    {
        return match ($this->type) {
            'int' => (int) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'float' => (float) $this->value,
            'secret' => 'secret:'.$this->value,
            default => $this->value,
        };
    }
}
