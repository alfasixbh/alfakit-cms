<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\Alfakit\Support\Url;
use AlfaSix\AlfakitCms\Models\Menu;
use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Support\Collection;

/**
 * Menus as the frontend draws them: nested `{ id, label, url, newTab, current, active,
 * children }` items. Page links resolve to the page's current URL; links to pages that
 * aren't live are left out (with their children). `current` marks the item of the
 * requested path, `active` it and its ancestors.
 */
final class Menus
{
    public const MAX_DEPTH = 3;

    /**
     * The menu of a site by handle: the one in the site's locale, else one without a locale.
     *
     * @return array{handle: string, name: string, items: array<int, array<string, mixed>>}|null
     */
    public static function resolve(string $handle, Site $site, ?string $currentPath = null): ?array
    {
        $menu = Menu::query()
            ->forTenant($site->tenant)
            ->where('handle', $handle)
            ->when($site->plugin->isMultilingual(), static fn ($query) => $query
                ->where(static fn ($query) => $query->where('locale', $site->locale)->orWhereNull('locale'))
                ->orderByRaw('case when locale is null then 1 else 0 end'))
            ->first();

        if (! $menu instanceof Menu) {
            return null;
        }

        $items = is_array($menu->items) ? $menu->items : [];
        $pages = self::pages($items, $site);
        $current = $currentPath === null ? null : self::normalize($currentPath);

        return [
            'handle' => $menu->handle,
            'name' => $menu->name,
            'items' => self::items($items, $pages, $site, $current, 'm'.$menu->getKey(), 1),
        ];
    }

    /**
     * Several menus keyed by handle (missing ones are left out).
     *
     * @param  array<int, string>  $handles
     * @return array<string, array{handle: string, name: string, items: array<int, array<string, mixed>>}>
     */
    public static function resolveMany(array $handles, Site $site, ?string $currentPath = null): array
    {
        $menus = [];

        foreach ($handles as $handle) {
            $menu = self::resolve($handle, $site, $currentPath);

            if ($menu !== null) {
                $menus[$handle] = $menu;
            }
        }

        return $menus;
    }

    /**
     * Live pages linked from the items, keyed by id.
     *
     * @param  array<int, mixed>  $items
     * @return Collection<int, Page>
     */
    private static function pages(array $items, Site $site): Collection
    {
        $ids = [];

        $collect = static function (array $items, int $depth) use (&$collect, &$ids): void {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (($item['type'] ?? 'url') === 'page' && is_numeric($item['page_id'] ?? null)) {
                    $ids[] = (int) $item['page_id'];
                }

                if ($depth < self::MAX_DEPTH && is_array($item['children'] ?? null)) {
                    $collect($item['children'], $depth + 1);
                }
            }
        };

        $collect($items, 1);

        if ($ids === []) {
            return new Collection;
        }

        return Page::query()->forTenant($site->tenant)->published()->whereKey(array_unique($ids))->get()->keyBy('id');
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  Collection<int, Page>  $pages
     * @return array<int, array<string, mixed>>
     */
    private static function items(array $items, Collection $pages, Site $site, ?string $current, string $prefix, int $depth): array
    {
        $resolved = [];

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = is_scalar($item['label'] ?? null) ? trim((string) $item['label']) : '';

            if (($item['type'] ?? 'url') === 'page') {
                $page = is_numeric($item['page_id'] ?? null) ? $pages->get((int) $item['page_id']) : null;

                if (! $page instanceof Page) {
                    continue;
                }

                $url = $site->pageUrl($page);
                $label = $label === '' ? $page->title : $label;
            } else {
                $url = Url::sanitize($item['url'] ?? null);
            }

            if ($label === '') {
                continue;
            }

            $id = $prefix.'-'.($index + 1);
            $children = $depth < self::MAX_DEPTH && is_array($item['children'] ?? null)
                ? self::items($item['children'], $pages, $site, $current, $id, $depth + 1)
                : [];

            $isCurrent = $url !== null && $current !== null && self::normalize($url) === $current;

            $resolved[] = [
                'id' => $id,
                'label' => mb_substr($label, 0, 120),
                'url' => $url,
                'newTab' => (bool) ($item['new_tab'] ?? false),
                'current' => $isCurrent,
                'active' => $isCurrent || in_array(true, array_column($children, 'active'), true),
                'children' => $children,
            ];
        }

        return $resolved;
    }

    private static function normalize(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return '/'.trim(is_string($path) ? $path : '', '/');
    }
}
