<?php

namespace Ygpynet\Giveaways\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Ygpynet\Giveaways\Giveaway;
use Ygpynet\Giveaways\Support\DrawVerifier;
use Flarum\Console\AbstractCommand;
use Illuminate\Support\Arr;

/**
 * Re-runs the published algorithm for a drawn giveaway and compares the
 * result against what's stored - the CLI twin of the fairness panel on the
 * giveaway page. Exit code 0 = verified, 1 = mismatch or bad input.
 */
class VerifyCommand extends AbstractCommand
{
    public function __construct(protected DrawVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('giveaways:verify')
            ->setDescription('Recompute a drawn giveaway from its published seed and verify the winners.')
            ->addArgument('id', InputArgument::REQUIRED, 'The id of a drawn giveaway to verify.');
    }

    protected function fire(): int
    {
        $id = (int) Arr::get($this->input->getArguments(), 'id');

        $giveaway = Giveaway::query()->find($id);

        if (! $giveaway) {
            $this->error("Giveaway #{$id} not found.");
            return Command::FAILURE;
        }
        if ($giveaway->status !== Giveaway::STATUS_DRAWN) {
            $this->error("Giveaway #{$id} is not drawn (status: {$giveaway->status}); nothing to verify.");
            return Command::FAILURE;
        }
        if (! $giveaway->draw_seed || ! $giveaway->entrant_hash) {
            $this->error("Giveaway #{$id} has no published seed/hash - it cannot be verified.");
            return Command::FAILURE;
        }

        $report = $this->verifier->verify($giveaway);

        $this->info("Giveaway #{$id}: {$giveaway->title}");
        $this->output->writeln('  entrant hash (published):   ' . $giveaway->entrant_hash);
        $this->output->writeln('  entrant hash (recomputed):  ' . $report['recomputed_hash']
            . ($report['hash_ok'] ? '  [MATCH]' : '  [MISMATCH]'));
        $this->output->writeln('  winners (recorded):         ' . $this->fmtIds($report['published_winners'], 'user_id'));
        $this->output->writeln('  winners (from seed):        ' . implode(', ', $report['recomputed_winners'] ?: ['-']));

        if ($report['ok']) {
            $this->info('VERIFIED: the stored winners are exactly what the published seed produces.');
            return Command::SUCCESS;
        }

        foreach ($report['problems'] as $problem) {
            $this->error('TAMPER/INTEGRITY: ' . $problem);
        }

        return Command::FAILURE;
    }

    private function fmtIds(array $rows, string $key): string
    {
        return implode(', ', array_map(fn ($r) => (string) $r[$key], $rows)) ?: '-';
    }
}
