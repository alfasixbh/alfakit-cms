<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms;

use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\BlockContext;
use AlfaSix\AlfakitCms\Support\BlockRenderer;
use AlfaSix\AlfakitCms\Support\Menus;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use AlfaSix\AlfakitCms\Support\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Helpers for your own controllers and Inertia pages.
 *
 * ```php
 * // AppServiceProvider::boot(): every Inertia page gets `cmsMenus.main` and `cmsMenus.footer`.
 * Cms::shareMenus(['main', 'footer']);
 *
 * // A controller: one menu, a page's URL, blocks of any model's Builder content.
 * $menu = Cms::menu('main');
 * $url = Cms::url($page);
 * $blocks = Cms::blocks($product->content);
 * ```
 */
final class Cms
{
    /**
     * The public site of the current request (tenant and locale resolved).
     */
    public static function site(?Request $request = null): ?Site
    {
        return Site::forRequest($request ?? request());
    }

    /**
     * A menu with its live links, `current` / `active` marked for the current path.
     *
     * @return array{handle: string, name: string, items: array<int, array<string, mixed>>}|null
     */
    public static function menu(string $handle, ?string $locale = null, ?Request $request = null): ?array
    {
        $request ??= request();
        $site = Site::forRequest($request);

        if ($site === null) {
            return null;
        }

        return Menus::resolve($handle, $locale === null ? $site : $site->withLocale($locale), '/'.ltrim($request->path(), '/'));
    }

    /**
     * Share menus with every Inertia response as the `cmsMenus` prop (resolved lazily).
     *
     * @param  array<int, string>  $handles
     */
    public static function shareMenus(array $handles, string $prop = 'cmsMenus'): void
    {
        Inertia::share($prop, static function () use ($handles): object {
            $request = request();
            $site = Site::forRequest($request);

            return (object) ($site === null ? [] : Menus::resolveMany($handles, $site, '/'.ltrim($request->path(), '/')));
        });
    }

    /**
     * The public URL of a page.
     */
    public static function url(Page $page): string
    {
        return Site::forPage($page)->pageUrl($page);
    }

    /**
     * A signed preview link of a page (`CmsPlugin::previewLinkLifetime()` minutes).
     */
    public static function previewUrl(Page $page): string
    {
        return PreviewLinks::url($page);
    }

    /**
     * Render-ready blocks of stored Builder content, for the `<CmsBlocks>` renderer.
     *
     * @return array<int, array{type: string, id: string, data: array<string, mixed>}>
     */
    public static function blocks(mixed $content, ?Page $page = null): array
    {
        $site = $page === null ? Site::forRequest(request()) : Site::forPage($page);

        return BlockRenderer::render($content, new BlockContext($site === null ? CmsPlugin::get() : $site->plugin, $page, $site));
    }
}
