<?php

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Symfony\Component\HttpFoundation\Request;

class SubmissionEventRecreator
{
    /**
     * @return array<string, string>
     */
    public function getServerData(Submission $formSubmission): array
    {
        return [
            'HTTP_REFERER' => $formSubmission->getReferer() ?: '',
            'REMOTE_ADDR'  => $formSubmission->getIpAddress()?->getIpAddress() ?: '',
        ];
    }

    /**
     * Create a minimal request object.
     *
     * @param array<string, string> $results
     * @param array<string, string> $serverData
     */
    public function getRequest(array $results, array $serverData): Request
    {
        return new Request($results, [], [], [], [], $serverData);
    }

    /**
     * Originally the mapped field values for multiselect fields or checkboxgrp are passed as array
     * In this method we want to recreate the original form.
     *
     * @param array<string, mixed> $results
     *
     * @return array<string, mixed>
     */
    public function getContactFieldMatches(Form $form, array $results): array
    {
        $leadFieldMatches = [];
        foreach ($form->getFields() as $f) {
            $mappedField     = $f->getMappedField();
            $fieldProperties = $f->getProperties() ?? [];

            if (!empty($mappedField) && in_array($f->getMappedObject(), ['company', 'contact'])) {
                $leadValue  = $results[$f->getAlias()] ?? '';
                $finalValue = $leadValue;

                if ((!empty($fieldProperties['multiple']) || 'checkboxgrp' === $f->getType())
                    && is_string($leadValue) && '' !== $leadValue) {
                    // The list of available options can be under the 'list' for select or 'optionlist' for checkboxgrp.
                    $optionsList = $fieldProperties['list']['list'] ?? $fieldProperties['optionlist']['list'] ?? null;

                    if (is_array($optionsList)) {
                        $validOptionValues = array_column($optionsList, 'value');

                        // Sort valid options by string length, descending.
                        usort($validOptionValues, fn ($a, $b): int => strlen($b) <=> strlen($a));

                        $matchedValues   = [];
                        $remainingStr    = $leadValue;

                        foreach ($validOptionValues as $option) {
                            if (str_contains($remainingStr, $option)) {
                                $matchedValues[] = $option;
                                // Remove the found option so it can't be part of another match.
                                $remainingStr = str_replace($option, '', $remainingStr);
                            }
                        }

                        // @phpstan-ignore-next-line Re-index keys to be sequential (0, 1, 2...).
                        $finalValue = array_values($matchedValues);
                    }
                }

                $leadFieldMatches[$mappedField] = $finalValue;
            }
        }

        return $leadFieldMatches;
    }
}
