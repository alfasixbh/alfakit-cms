<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Enums;

/**
 * What visitors see of a page right now.
 */
enum PageState: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    /** Published, with saved changes that aren't live yet. */
    case ChangesPending = 'changes_pending';

    public function getLabel(): string
    {
        return __('alfakit-cms::cms.states.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'warning',
            self::Published => 'success',
            self::ChangesPending => 'info',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach ([self::Draft, self::Scheduled, self::Published] as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
