<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerDoiBundle\Command;

use MauticPlugin\LeuchtfeuerDoiBundle\Entity\FormDoiSubmissionRepository;
use MauticPlugin\LeuchtfeuerDoiBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerDoiBundle\Service\FollowUpSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'leuchtfeuer:doi:send-followup', description: 'Send DOI follow-up emails')]
class SendFollowUpCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly FormDoiSubmissionRepository $submissionRepo,
        private readonly FollowUpSender $followUpSender,
        private readonly Config $pluginConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', '-l', InputOption::VALUE_REQUIRED, 'Max submissions to process (no limit if not specified)')
            ->addOption(
                'batch',
                '-b',
                InputOption::VALUE_REQUIRED,
                'Number of submissions to process in each batch',
                self::DEFAULT_BATCH_SIZE
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->pluginConfig->isPublished()) {
            $output->writeln('DOI plugin is disabled');

            return Command::FAILURE;
        }

        $limitOption      = $input->getOption('limit');
        $totalLimit       = null !== $limitOption ? (int) $limitOption : null;
        $batchLimit       = (int) $input->getOption('batch');

        $waitHours = $this->pluginConfig->getFollowUpWaitTime();
        $threshold = new \DateTimeImmutable(sprintf('-%d hours', $waitHours), new \DateTimeZone('UTC'));

        $processed           = 0;
        $sent                = 0;
        $processedContactIds = [];
        $sentContactIds      = [];

        // Process submissions in batches until the total limit is reached or no more submissions
        do {
            $remainingBatch = null !== $totalLimit ? min($batchLimit, $totalLimit - $processed) : $batchLimit;
            $submissions    = $this->submissionRepo->findPendingDueForFollowup($threshold, $remainingBatch);

            $batchProcessed = 0;
            $batchSent      = 0;

            foreach ($submissions as $submission) {
                ++$batchProcessed;

                $lead   = $submission->getLead();
                $leadId = null !== $lead ? $lead->getId() : null;
                if (null !== $leadId) {
                    $processedContactIds[] = (int) $leadId;
                }

                if ($this->followUpSender->send($submission)) {
                    ++$batchSent;
                    if (null !== $leadId) {
                        $sentContactIds[] = (int) $leadId;
                    }
                    if ($output->isVeryVerbose()) {
                        $output->writeln(sprintf('Follow-up sent to contact ID %s', null !== $leadId ? (string) $leadId : 'n/a'));
                    }
                } elseif ($output->isVeryVerbose()) {
                    $output->writeln(sprintf('Follow-up not sent to contact ID %s', null !== $leadId ? (string) $leadId : 'n/a'));
                }
            }

            $processed += $batchProcessed;
            $sent += $batchSent;

            // Continue only if submissions were processed and limit not reached
        } while ($batchProcessed > 0 && (null === $totalLimit || $processed < $totalLimit));

        $output->writeln(sprintf('Processed: %d | Sent: %d', $processed, $sent));
        if ($output->isVerbose()) {
            $processedContactIds = array_values(array_unique($processedContactIds));
            $sentContactIds      = array_values(array_unique($sentContactIds));
            $output->writeln(sprintf('Processed contact IDs: %s', $processedContactIds ? implode(', ', $processedContactIds) : '-'));
            $output->writeln(sprintf('Sent contact IDs: %s', $sentContactIds ? implode(', ', $sentContactIds) : '-'));
        }

        return Command::SUCCESS;
    }
}
