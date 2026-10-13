<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Service;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\LeuchtfeuerDoiBundle\Enum\LastDoiDateField;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LastDoiDateFieldInstaller
{
    public function __construct(
        private FieldModel $fieldModel,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public static function install(FieldModel $fieldModel, TranslatorInterface $translator, LoggerInterface $logger): void
    {
        (new self($fieldModel, $translator, $logger))->installIfMissing();
    }

    public function installIfMissing(): void
    {
        try {
            if ($this->fieldModel->getEntityByAlias(LastDoiDateField::ALIAS) instanceof LeadField) {
                return;
            }

            $field = new LeadField();
            $field->setLabel($this->translator->trans(LastDoiDateField::LABEL_TRANSLATION_KEY));
            $field->setAlias(LastDoiDateField::ALIAS);
            $field->setType('datetime');
            $field->setObject('lead');
            $field->setGroup('professional');
            $field->setIsListable(true);
            $field->setIsVisible(true);

            $this->fieldModel->saveEntity($field);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to install last_doi_date contact field', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
