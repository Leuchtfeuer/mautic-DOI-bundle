<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Event;

use Mautic\FormBundle\Entity\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

final class ResolveConsentSnapshotEvent extends Event
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        private array $snapshot,
        private Form $form,
        private Request $request,
    ) {
    }

    public function getForm(): Form
    {
        return $this->form;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * @param callable(string): string $transformer
     */
    public function transformLabels(callable $transformer): void
    {
        foreach ($this->snapshot['fields'] as &$field) {
            if (null !== $field['label']) {
                $field['label'] = $transformer($field['label']);
            }

            foreach ($field['selected_options'] as &$selectedOption) {
                if (null !== $selectedOption['label']) {
                    $selectedOption['label'] = $transformer($selectedOption['label']);
                }
            }
            unset($selectedOption);
        }
        unset($field);
    }
}
