<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Enum;

enum DoiVerificationHistoryAction: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';
    case SKIPPED = 'skipped';

    public function eventType(): string
    {
        return match ($this) {
            self::SUCCESS => 'doi.verification.success',
            self::FAILURE => 'doi.verification.failure',
            self::SKIPPED => 'doi.verification.skipped',
        };
    }

    public function translationKey(): string
    {
        return match ($this) {
            self::SUCCESS => 'mautic.plugin.doi.timeline.verification.success',
            self::FAILURE => 'mautic.plugin.doi.timeline.verification.failure',
            self::SKIPPED => 'mautic.plugin.doi.timeline.verification.skipped',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SUCCESS => 'ri-mail-check-line',
            self::FAILURE => 'ri-mail-close-line',
            self::SKIPPED => 'ri-skip-forward-line',
        };
    }
}
