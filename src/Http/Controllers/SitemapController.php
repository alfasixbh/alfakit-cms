<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Controllers;

use AlfaSix\AlfakitCms\Support\Site;
use AlfaSix\AlfakitCms\Support\Sitemap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SitemapController
{
    public function __invoke(Request $request): Response
    {
        $site = Site::forRequest($request);

        if ($site === null || ! $site->plugin->hasPublicRoutes() || ! $site->plugin->hasSitemap()) {
            abort(404);
        }

        return response(Sitemap::toXml($site), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
