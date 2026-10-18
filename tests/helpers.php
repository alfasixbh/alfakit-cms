<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function inertiaGet(string $url, array $headers = []): TestResponse
{
    return \Pest\Laravel\get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '', ...$headers]);
}

function cmsUser(string $name = 'Ada Admin'): TenantUser
{
    return TenantUser::query()->create(['name' => $name, 'email' => uniqid('cms', true).'@example.com', 'password' => 'password']);
}

function cmsPlugin(string $panel = 'cms'): CmsPlugin
{
    $plugin = alfakit()->getPanel($panel)->getPlugin(CmsPlugin::ID);

    return $plugin instanceof CmsPlugin ? $plugin : throw new RuntimeException('The CMS plugin is not registered.');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function cmsPage(string $title, array $attributes = []): Page
{
    return Page::query()->create([
        'title' => $title,
        'slug' => str($title)->slug()->toString(),
        'status' => PageStatus::Published,
        'content' => [['type' => 'rich-text', 'data' => ['content' => '<p>'.e($title).'</p>']]],
        ...$attributes,
    ]);
}

function cmsTeam(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

/**
 * Every component in a serialised schema tree, depth-first.
 *
 * @param  array<int|string, mixed>  $nodes
 * @return array<int, array<string, mixed>>
 */
function flattenSchema(array $nodes): array
{
    $flat = [];

    foreach ($nodes as $node) {
        if (! is_array($node)) {
            continue;
        }

        if (isset($node['type']) && is_string($node['type'])) {
            $flat[] = $node;
        }

        foreach (['components', 'schema', 'children', 'items', 'content'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                array_push($flat, ...flattenSchema(array_is_list($node[$key]) ? $node[$key] : [$node[$key]]));
            }
        }
    }

    return $flat;
}
