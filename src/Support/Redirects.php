<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\Redirect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Matching requests against the redirects of a tenant. The active redirects of each
 * tenant are cached (forever, flushed whenever one is saved or deleted), so the
 * middleware costs one cache read per request.
 */
final class Redirects
{
    private const CACHE_KEY = 'alfakit-cms.redirects.';

    /**
     * `/Old-Page/` → `/Old-Page`; full URLs keep their path only; a trailing `*` (prefix
     * match) is kept.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?? '/');
        }

        $wildcard = str_ends_with($path, '*');
        $path = rtrim($path, '*');
        $path = '/'.trim((string) preg_replace('#/+#', '/', $path), '/');

        if ($wildcard) {
            return ($path === '/' ? '/' : $path.'/').'*';
        }

        return $path;
    }

    public static function flush(int|string|null $tenantKey): void
    {
        Cache::forget(self::CACHE_KEY.($tenantKey ?? 'none'));
    }

    /**
     * The active redirects of a tenant: exact paths (lowercased) and prefixes, longest first.
     *
     * @return array{exact: array<string, array{id: int, to: string, status: int}>, prefix: array<int, array{id: int, from: string, to: string, status: int}>}
     */
    public static function map(?Model $tenant): array
    {
        $key = $tenant?->getKey();

        /** @var array{exact: array<string, array{id: int, to: string, status: int}>, prefix: array<int, array{id: int, from: string, to: string, status: int}>} */
        return Cache::rememberForever(self::CACHE_KEY.(is_scalar($key) ? (string) $key : 'none'), static function () use ($tenant): array {
            $exact = [];
            $prefix = [];

            Redirect::query()->forTenant($tenant)->where('is_active', true)->orderBy('id')->each(static function (Redirect $redirect) use (&$exact, &$prefix): void {
                $entry = ['id' => (int) $redirect->getKey(), 'to' => $redirect->to_url, 'status' => $redirect->status_code];

                if ($redirect->isWildcard()) {
                    $prefix[] = ['from' => mb_strtolower(rtrim($redirect->from_path, '*')), ...$entry];
                } else {
                    $exact[mb_strtolower($redirect->from_path)] ??= $entry;
                }
            });

            usort($prefix, static fn (array $a, array $b): int => mb_strlen($b['from']) <=> mb_strlen($a['from']));

            return ['exact' => $exact, 'prefix' => $prefix];
        });
    }

    /**
     * The redirect for a request path, with `*` in the target replaced by the rest of a
     * prefix match.
     *
     * @return array{id: int, to: string, status: int}|null
     */
    public static function match(string $path, ?Model $tenant): ?array
    {
        $path = mb_strtolower(self::normalizePath($path));
        $map = self::map($tenant);

        if (isset($map['exact'][$path])) {
            return $map['exact'][$path];
        }

        foreach ($map['prefix'] as $redirect) {
            $from = $redirect['from'];

            if ($from === '/' || str_starts_with($path.'/', $from)) {
                $rest = ltrim(mb_substr($path, mb_strlen(rtrim($from, '/'))), '/');

                return ['id' => $redirect['id'], 'to' => str_replace('*', $rest, $redirect['to']), 'status' => $redirect['status']];
            }
        }

        return null;
    }

    public static function recordHit(int $id): void
    {
        Redirect::query()->whereKey($id)->toBase()->increment('hits', 1, ['last_hit_at' => now()]);
    }

    /**
     * A published page moved from `$oldPath`: redirect the old URL to the new one, point
     * redirects that led to the old URL at the new one, and drop a redirect away from the
     * new URL (it would hide the page).
     */
    public static function pageMoved(Page $page, string $oldPath): void
    {
        $plugin = CmsPlugin::get();

        if (! $plugin->createsRedirectsOnPathChange() || $page->getOriginal('status') !== PageStatus::Published && $page->status !== PageStatus::Published) {
            return;
        }

        $site = Site::forPage($page);
        $from = self::normalizePath($site->pathFor($oldPath, $page->locale));
        $to = self::normalizePath($site->pathFor($page->path, $page->locale));

        if ($from === $to) {
            return;
        }

        $tenant = $page->tenant_id === null ? null : $page->tenant;

        Redirect::query()->forTenant($tenant)->where('from_path', $to)->get()->each->delete();
        Redirect::query()->forTenant($tenant)->where('to_url', $from)->get()->each(static function (Redirect $redirect) use ($to): void {
            $redirect->to_url = $to;
            $redirect->save();
        });

        $redirect = Redirect::query()->forTenant($tenant)->where('from_path', $from)->first() ?? new Redirect(['from_path' => $from, 'tenant_id' => $page->tenant_id]);
        $redirect->fill(['to_url' => $to, 'status_code' => 301, 'is_active' => true]);
        $redirect->save();
    }
}
