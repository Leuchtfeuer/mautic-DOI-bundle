<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\DTO;

final readonly class DoiTokenData
{
    public function __construct(
        public int $formId,
        public string $hash,
    ) {
        if ($formId <= 0) {
            throw new \InvalidArgumentException('Form ID must be positive');
        }

        if ('' === trim($hash)) {
            throw new \InvalidArgumentException('Hash cannot be empty');
        }
    }
}
