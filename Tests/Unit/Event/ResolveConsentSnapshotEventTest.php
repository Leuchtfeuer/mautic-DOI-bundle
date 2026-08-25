<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Tests\Unit\Event;

use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\Event\ResolveConsentSnapshotEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ResolveConsentSnapshotEventTest extends TestCase
{
    public function testTransformLabelsSkipsNullLabels(): void
    {
        $snapshot = [
            'version' => 1,
            'fields'  => [[
                'alias'            => 'consent',
                'label'            => null,
                'selected_options' => [
                    ['value' => 'yes', 'label' => 'consent.yes.token'],
                    ['value' => 'unknown', 'label' => null],
                ],
            ]],
        ];
        $transformedLabels = [];
        $event             = new ResolveConsentSnapshotEvent($snapshot, new Form(), new Request());

        $event->transformLabels(static function (string $label) use (&$transformedLabels): string {
            $transformedLabels[] = $label;

            return 'resolved '.$label;
        });

        self::assertSame(['consent.yes.token'], $transformedLabels);
        self::assertSame([
            'version' => 1,
            'fields'  => [[
                'alias'            => 'consent',
                'label'            => null,
                'selected_options' => [
                    ['value' => 'yes', 'label' => 'resolved consent.yes.token'],
                    ['value' => 'unknown', 'label' => null],
                ],
            ]],
        ], $event->getSnapshot());
    }
}
