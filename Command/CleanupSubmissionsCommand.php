<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Command;

use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiConfig;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\SubmissionCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'leuchtfeuer:doi:cleanup-submissions',
    description: 'Delete timed-out DOI form submissions after the configured retention period'
)]
class CleanupSubmissionsCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly SubmissionCleanupService $cleanupService,
        private readonly Config $pluginConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum total submissions to process (no limit if not specified)'
            )
            ->addOption(
                'batch',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of submissions to process in each batch',
                self::DEFAULT_BATCH_SIZE
            )
            ->addOption(
                'form-id',
                'f',
                InputOption::VALUE_REQUIRED,
                'Limit cleanup to a specific form ID'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $errorIo = $output instanceof ConsoleOutputInterface
            ? new SymfonyStyle($input, $output->getErrorOutput())
            : $io;

        if (!$this->pluginConfig->isPublished()) {
            $errorIo->error('DOI plugin is disabled');

            return Command::FAILURE;
        }

        $limitOption    = $input->getOption('limit');
        $totalLimit     = null !== $limitOption ? (int) $limitOption : null;
        $batchLimit     = (int) $input->getOption('batch');
        $formIdOption   = $input->getOption('form-id');
        $filterFormId   = null !== $formIdOption ? (int) $formIdOption : null;

        // Validate numeric options
        if ($batchLimit <= 0) {
            $errorIo->error('Batch size must be a positive integer.');

            return Command::FAILURE;
        }

        if (null !== $totalLimit && $totalLimit <= 0) {
            $errorIo->error('Limit must be a positive integer.');

            return Command::FAILURE;
        }

        if (null !== $filterFormId && $filterFormId <= 0) {
            $errorIo->error('Form ID must be a positive integer.');

            return Command::FAILURE;
        }

        $configs = $this->cleanupService->getConfigsWithCleanupEnabled();

        if ([] === $configs) {
            $io->info('No forms have cleanup enabled.');

            return Command::SUCCESS;
        }

        // Filter to specific form if requested
        if (null !== $filterFormId) {
            $configs = array_filter(
                $configs,
                fn (FormDoiConfig $c): bool => $c->getForm()?->getId() === $filterFormId
            );

            if ([] === $configs) {
                $errorIo->error(sprintf('Form ID %d does not have DOI cleanup enabled.', $filterFormId));

                return Command::FAILURE;
            }
        }

        $totalProcessed     = 0;
        $totalDeleted       = 0;
        $formsWithDeletions = 0;
        $collectIds         = $output->isVerbose();
        $deletedIds         = $collectIds ? [] : null;

        foreach ($configs as $config) {
            $form = $config->getForm();
            if (null === $form) {
                continue;
            }

            $formId   = $form->getId();
            $formName = $form->getName();

            $io->text(sprintf(
                'Processing form "%s" (ID: %d, cleanup enabled)...',
                $formName,
                $formId
            ));

            $formDeleted = 0;

            do {
                // Calculate remaining batch considering total limit
                $remainingTotal = null !== $totalLimit ? $totalLimit - $totalProcessed : null;
                $remainingBatch = null !== $remainingTotal
                    ? min($batchLimit, $remainingTotal)
                    : $batchLimit;

                if ($remainingBatch <= 0) {
                    break;
                }

                $submissions    = $this->cleanupService->findSubmissionsForCleanup($config, $remainingBatch);
                $batchProcessed = 0;

                foreach ($submissions as $submission) {
                    ++$batchProcessed;
                    $submissionId = $submission->getId();

                    if ($this->cleanupService->deleteSubmission($submission)) {
                        ++$formDeleted;
                        if ($collectIds && null !== $submissionId) {
                            $deletedIds[] = $submissionId;
                        }

                        if ($output->isVeryVerbose()) {
                            $io->text(sprintf(
                                '  Deleted DOI submission ID %s',
                                null !== $submissionId ? (string) $submissionId : 'n/a'
                            ));
                        }
                    } elseif ($output->isVeryVerbose()) {
                        $io->text(sprintf(
                            '  Skipped DOI submission ID %s',
                            null !== $submissionId ? (string) $submissionId : 'n/a'
                        ));
                    }
                }

                $totalProcessed += $batchProcessed;

                // Clear EntityManager to free memory after each batch
                $this->cleanupService->clearEntityManager();
            } while ($batchProcessed > 0 && (null === $totalLimit || $totalProcessed < $totalLimit));

            $totalDeleted += $formDeleted;

            $io->text(sprintf(
                '  Deleted: %d submissions',
                $formDeleted
            ));

            if ($formDeleted > 0) {
                ++$formsWithDeletions;
            }

            // Check if we've reached the total limit
            if (null !== $totalLimit && $totalProcessed >= $totalLimit) {
                break;
            }
        }

        $io->newLine();
        $io->section('Summary');
        $io->writeln(sprintf('Forms with deletions: %d', $formsWithDeletions));
        $io->writeln(sprintf('Submissions deleted: %d', $totalDeleted));

        if (null !== $deletedIds && [] !== $deletedIds) {
            $deletedIds = array_values(array_unique($deletedIds));
            $io->writeln(sprintf(
                'Deleted DOI submission IDs: %s',
                implode(', ', $deletedIds)
            ));
        }

        $io->success(sprintf(
            'Cleanup complete. %d submissions deleted from %d forms.',
            $totalDeleted,
            $formsWithDeletions
        ));

        return Command::SUCCESS;
    }
}
