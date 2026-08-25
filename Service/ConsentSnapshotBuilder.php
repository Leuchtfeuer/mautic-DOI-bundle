<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use MauticPlugin\LeuchtfeuerDoiBundle\DoiEvents;
use MauticPlugin\LeuchtfeuerDoiBundle\Event\ResolveConsentSnapshotEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

final class ConsentSnapshotBuilder
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $submittedValues
     *
     * @return array<string, mixed>
     */
    public function build(Form $form, array $submittedValues, Request $request): array
    {
        $fields = [];

        foreach ($form->getFields() as $field) {
            if (!$field instanceof Field || 'checkboxgrp' !== $field->getType()) {
                continue;
            }

            $alias          = (string) $field->getAlias();
            $selectedValues = $this->normalizeSelectedValues($submittedValues[$alias] ?? null);
            if ([] === $selectedValues) {
                continue;
            }

            $sourceOptionLabels = $this->extractSourceOptionLabels($field);
            $selectedOptions    = [];
            foreach ($selectedValues as $selectedValue) {
                $selectedOptions[] = [
                    'value' => $selectedValue,
                    'label' => $sourceOptionLabels[$selectedValue] ?? null,
                ];
            }

            $fields[] = [
                'alias'            => $alias,
                'label'            => $field->getShowLabel() ? (string) $field->getLabel() : null,
                'selected_options' => $selectedOptions,
            ];
        }

        $sourceSnapshot = [
            'version' => 1,
            'fields'  => $fields,
        ];

        try {
            $event = new ResolveConsentSnapshotEvent($sourceSnapshot, $form, $request);
            $this->eventDispatcher->dispatch($event, DoiEvents::DOI_ON_RESOLVE_CONSENT_SNAPSHOT);

            return $event->getSnapshot();
        } catch (\Throwable $exception) {
            $this->logger->warning('DOI consent snapshot label resolution failed', [
                'form_id'   => $form->getId(),
                'exception' => $exception,
            ]);

            return $sourceSnapshot;
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeSelectedValues(mixed $value): array
    {
        if (null === $value || '' === $value) {
            return [];
        }

        $values           = is_array($value) ? $value : [$value];
        $normalizedValues = [];
        foreach ($values as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $item = (string) $item;
            if ('' !== $item) {
                $normalizedValues['value:'.$item] = $item;
            }
        }

        return array_values($normalizedValues);
    }

    /**
     * @return array<string, string|null>
     */
    private function extractSourceOptionLabels(Field $field): array
    {
        $properties = $field->getProperties();
        $options    = $properties['optionlist'] ?? $properties['list'] ?? [];
        $options    = is_array($options) && isset($options['list']) ? $options['list'] : $options;
        if (!is_array($options)) {
            return [];
        }

        $labels = [];
        foreach ($options as $value => $option) {
            if (is_array($option)) {
                if (!array_key_exists('value', $option)) {
                    continue;
                }

                $labels[(string) $option['value']] = isset($option['label']) ? (string) $option['label'] : null;
            } elseif (is_scalar($option)) {
                $labels[(string) $value] = (string) $option;
            }
        }

        return $labels;
    }
}
