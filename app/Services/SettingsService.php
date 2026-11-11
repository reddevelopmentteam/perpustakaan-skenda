<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Akses terpusat ke tabel `settings` dengan caching (§26).
 *
 * Semua pembacaan setting lewat service ini agar tidak terjadi
 * query berulang per komponen. Nilai di-cache untuk menghindari
 * N+1 pada setting yang sama.
 */
class SettingsService
{
    /**
     * Ambil nilai setting dengan fallback default.
     *
     * @param  string  $key
     * @param  string|null  $default
     * @return string|null
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember("settings.{$key}", 3600, fn () => Setting::where('key', $key)->value('value')) ?? $default;
    }

    /**
     * Simpan setting dan invalidasi cache.
     *
     * @param  string  $key
     * @param  string|null  $value
     * @return void
     */
    public static function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("settings.{$key}");
    }

    /**
     * Hapus setting dan invalidasi cache.
     */
    public static function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        Cache::forget("settings.{$key}");
    }

    /**
     * Ambil beberapa setting sekaligus sebagai array.
     *
     * @param  array<string, string>  $defaults  key => default
     * @return array<string, string|null>
     */
    public static function getMany(array $defaults): array
    {
        $result = [];

        foreach ($defaults as $key => $default) {
            $result[$key] = self::get($key, $default);
        }

        return $result;
    }

    /**
     * Ambil nilai numerik (integer) dari setting.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }
}
