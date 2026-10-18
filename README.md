# AlfaKit CMS

<p><picture><source media="(prefers-color-scheme: dark)" srcset="../../../art/alfasix/alfa-six-micro-white.svg"><img src="../../../art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

Run your public site from your [AlfaKit](https://alfakit.dev) panel: block-based pages with drafts, autosave and a live preview, scheduling, a review workflow, revisions with plain-language compare, page templates and reusable sections, an SEO helper, `sitemap.xml`, `robots.txt`, redirects and menus. Pages render through Inertia with the same React, Vue or Svelte renderer as the panel, and a read-only JSON API serves headless frontends.

## Installation

```bash
composer require alfasix/alfakit-cms
php artisan vendor:publish --tag=alfakit-cms-migrations
php artisan migrate
php artisan storage:link
```

## Register

```php
use AlfaSix\AlfakitCms\CmsPlugin;

$panel->plugin(
    CmsPlugin::make()
        ->siteName('Acme')
        ->publicRoutes()   // serve published pages, sitemap.xml and robots.txt
        ->adminBar()       // "Edit this page" on the public site for editors
        ->api(),           // read-only JSON API
);
```

Register the public page component in your site's Inertia app:

```tsx
import { CmsPage, createAlfakit } from '@alfakit/react'

createAlfakit({ pages: { 'Alfakit/CmsPage': CmsPage } })
```

See the [documentation](https://alfakit.dev/docs/plugins/cms) for custom blocks, multilingual and multi-tenant sites, the review workflow and every option, and its "For content editors" section for a plain-language guide.

## License

MIT. See [LICENSE.md](LICENSE.md).
