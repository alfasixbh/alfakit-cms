<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Controllers;

use AlfaSix\AlfakitCms\Support\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RobotsController
{
    public function __invoke(Request $request): Response
    {
        $site = Site::forRequest($request);

        if ($site === null || ! $site->plugin->hasPublicRoutes() || ! $site->plugin->hasRobots()) {
            abort(404);
        }

        $plugin = $site->plugin;
        $using = $plugin->getRobotsUsing();

        if ($using !== null) {
            $custom = $plugin->evaluate($using, ['request' => $request, 'site' => $site], [Request::class => $request, Site::class => $site]);
            $text = is_array($custom) ? implode("\n", array_map(static fn (mixed $line): string => is_scalar($line) ? (string) $line : '', $custom)) : (is_string($custom) ? $custom : '');
        } else {
            $lines = ['User-agent: *', 'Disallow: '.$site->rootPath('_cms/')];

            if ($plugin->hasSitemap()) {
                $lines[] = '';
                $lines[] = 'Sitemap: '.url($site->rootPath('sitemap.xml'));
            }

            $text = implode("\n", $lines);
        }

        return response(rtrim($text)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
