<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\AlfakitCms\CmsPlugin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Authorisation of every CMS ability for the panel's signed-in user: the plugin's
 * `authorize()` closure when set, else the model's policy. `publish` and
 * `restoreRevision` fall back to `update` when the policy doesn't define them; without a
 * policy everything is allowed unless the panel uses `strictAuthorization()`. Guests are
 * never allowed.
 */
final class CmsAuthorization
{
    private const FALLBACKS = ['publish' => 'update', 'restoreRevision' => 'update', 'submitForReview' => 'update'];

    /**
     * @param  Model|class-string<Model>  $model
     */
    public static function allows(string $ability, Model|string $model, ?Model $user = null): bool
    {
        $user ??= alfakit()->getCurrentOrDefaultPanel()->auth()->user();

        if (! $user instanceof Model) {
            return false;
        }

        $plugin = CmsPlugin::get();

        if ($plugin->getAuthorizeUsing() !== null) {
            return $plugin->checkAuthorization($ability, $model instanceof Model ? $model::class : $model, $model instanceof Model ? $model : null, $user);
        }

        $policy = Gate::getPolicyFor($model);

        if ($policy !== null && method_exists($policy, $ability)) {
            return Gate::forUser($user)->check($ability, $model);
        }

        if (isset(self::FALLBACKS[$ability])) {
            return self::allows(self::FALLBACKS[$ability], $model, $user);
        }

        return Authorization::allows($ability, $model);
    }
}
