<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmissionRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'leuchtfeuer:doi:update-timeout',
    description: 'Mark expired pending DOI submissions as timed out'
)]
class UpdateTimeoutCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly FormDoiSubmissionRepository $submissionRepo,
        private readonly EntityManagerInterface $em,
        private readonly Config $pluginConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max submissions to process (no limit if not specified)')
            ->addOption(
                'batch',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of submissions to fetch in each batch',
                self::DEFAULT_BATCH_SIZE
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

        $limitOption = $input->getOption('limit');
        $totalLimit  = null !== $limitOption ? (int) $limitOption : null;
        $batchLimit  = (int) $input->getOption('batch');

        $timeoutHours = $this->pluginConfig->getDoiLinkTimeout();
        $threshold    = new \DateTimeImmutable(sprintf('-%d hours', $timeoutHours), new \DateTimeZone('UTC'));

        $processed    = 0;
        $updated      = 0;
        $processedIds = [];
        $updatedIds   = [];

        do {
            $remainingBatch = null !== $totalLimit ? min($batchLimit, $totalLimit - $processed) : $batchLimit;
            $submissions    = $this->submissionRepo->findPendingExpired($threshold, $remainingBatch);

            $batchProcessed = 0;

            foreach ($submissions as $submission) {
                ++$batchProcessed;

                $submissionId = $submission->getId();
                if (null !== $submissionId) {
                    $processedIds[] = $submissionId;
                }

                $submission->timeout();
                $this->em->persist($submission);

                if (null !== $submissionId) {
                    $updatedIds[] = $submissionId;
                }
                ++$updated;

                if ($output->isVeryVerbose()) {
                    $io->text(sprintf('Marked submission ID %s as timed out', null !== $submissionId ? (string) $submissionId : 'n/a'));
                }
            }

            // Flush after each batch to ensure status changes are persisted
            // before the next query (otherwise the same submissions would be returned)
            if ($batchProcessed > 0) {
                $this->em->flush();
            }

            $processed += $batchProcessed;
        } while ($batchProcessed > 0 && (null === $totalLimit || $processed < $totalLimit));

        $io->text(sprintf('Processed: %d | Updated: %d', $processed, $updated));

        if ($output->isVerbose()) {
            $processedIds = array_values(array_unique($processedIds));
            $updatedIds   = array_values(array_unique($updatedIds));
            $io->text(sprintf('Processed submission IDs: %s', $processedIds ? implode(', ', $processedIds) : '-'));
            $io->text(sprintf('Updated submission IDs: %s', $updatedIds ? implode(', ', $updatedIds) : '-'));
        }

        return Command::SUCCESS;
    }
}
