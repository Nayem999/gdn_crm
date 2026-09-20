<?php

namespace App\Console\Commands;

use App\Domain\Meta\Auth\MetaTokenAudit;
use App\Domain\Meta\Models\MetaAccount;
use Illuminate\Console\Command;

/**
 * Asks Meta whether the tokens this installation holds still work.
 *
 * Daily, because the failure it catches is silent and slow: a token revoked in
 * Business Settings breaks nothing until somebody tries to send a message, and
 * the message they get back names an app id and reads like a misconfiguration.
 * Asking on a schedule turns that into a line on the connection screen that was
 * already there when they went looking.
 *
 * Run here rather than queued. It is a handful of calls against one account,
 * and a token check that sits behind a stopped worker is a check that reports
 * exactly when it is least able to.
 */
class CheckMetaTokens extends Command
{
    protected $signature = 'meta:check-tokens';

    protected $description = 'Ask Meta whether each stored Meta token is still accepted';

    public function handle(MetaTokenAudit $audit): int
    {
        $accounts = MetaAccount::query()->orderBy('id')->get();

        if ($accounts->isEmpty()) {
            $this->info('No Meta account is connected.');

            return self::SUCCESS;
        }

        $refused = 0;

        foreach ($accounts as $account) {
            foreach ($audit->run($account) as $result) {
                if ($result['valid']) {
                    $this->line('  <fg=green>ok</> '.$result['label'].' — '.$result['detail']);

                    continue;
                }

                $refused++;
                $this->line('  <fg=red>no</> '.$result['label'].' — '.$result['detail']);
            }
        }

        // Success either way: a revoked token is a finding, not a failure of
        // the check. A non-zero exit would put this in a monitoring alert
        // every night for something the screen already says plainly.
        $this->newLine();
        $this->info($refused === 0
            ? 'Meta accepts every stored token.'
            : $refused.' '.str('token')->plural($refused).' Meta will not accept.');

        return self::SUCCESS;
    }
}
