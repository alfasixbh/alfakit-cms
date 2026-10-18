<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Controllers\Api;

use AlfaSix\AlfakitCms\Support\Menus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/cms/menus/{handle}?locale=en`: a menu with its live links, for headless frontends.
 */
class MenuApiController
{
    public function __invoke(Request $request, string $handle): JsonResponse
    {
        $site = PageApiController::site($request);

        if (! $site->plugin->hasMenus()) {
            abort(404);
        }

        $menu = Menus::resolve($handle, $site);

        if ($menu === null) {
            return response()->json(['message' => __('alfakit-cms::cms.api.not_found')], 404);
        }

        return response()->json(['data' => $menu]);
    }
}
