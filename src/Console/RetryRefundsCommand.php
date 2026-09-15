<?php

namespace Ygpynet\Giveaways\Console;

use Symfony\Component\Console\Command\Command;
use Ygpynet\Giveaways\GiveawayRefund;
use Ygpynet\Giveaways\RefundService;
use Flarum\Console\AbstractCommand;

/**
 * Drains the giveaway_refunds queue: every point refund that failed while
 * cancelling/refunding (points system down, transient errors) is retried
 * here. Safe to run repeatedly and from cron; already-refunded rows are
 * never touched, permanently failing rows stay queued with attempts/error
 * recorded for audit.
 */
class RetryRefundsCommand extends AbstractCommand
{
    public function __construct(protected RefundService $refunds)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('giveaways:retry-refunds')
            ->setDescription('Retry point refunds that failed during giveaway cancellation/refund.');
    }

    protected function fire(): int
    {
        $pending = GiveawayRefund::pending()->get();

        if ($pending->isEmpty()) {
            $this->info('No pending refunds.');
            return Command::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($pending as $refund) {
            if ($this->refunds->retry($refund)) {
                $ok++;
                $this->output->writeln("Refund #{$refund->id}: refunded {$refund->amount} points to user #{$refund->user_id}.");
            } else {
                $failed++;
                $this->error("Refund #{$refund->id} (attempt {$refund->attempts}): {$refund->last_error}");
            }
        }

        $this->info("Done. Refunded: {$ok}, still pending: {$failed}.");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
