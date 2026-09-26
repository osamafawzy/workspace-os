<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings that are changed from the panel rather than from code.
 *
 * Read on nearly every request (the sidebar and the brand come from here), so
 * the whole table is loaded once into the cache and served from memory after
 * that. Writes clear it.
 *
 * Reading never throws: before the first migration has run — a fresh install,
 * `artisan migrate` itself — there is no table yet, and the answer is simply
 * the default.
 */
class Settings
{
    protected const CACHE_KEY = 'app.settings';

    /** @var array<string, mixed>|null */
    protected ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );

        $this->flush();
    }

    public function forget(string $key): void
    {
        DB::table('settings')->where('key', $key)->delete();

        $this->flush();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->loaded ??= $this->load();
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    protected function load(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => DB::table('settings')
                ->pluck('value', 'key')
                ->map(fn (?string $value): mixed => $value === null ? null : json_decode($value, true))
                ->all());
        } catch (QueryException) {
            return [];
        }
    }
}
