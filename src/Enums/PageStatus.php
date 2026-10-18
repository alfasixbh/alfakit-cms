<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Enums;

/**
 * The stored status of a page. A published page with a future `publish_at` is
 * "scheduled" ({@see PageState}): it goes live at that time without a job.
 */
enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
