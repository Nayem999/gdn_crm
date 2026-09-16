<?php

namespace App\Console\Commands;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Social\Actions\ImportMessengerHistoryAction;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bring a Page's existing Messenger conversations into the inbox.
 *
 * Run once when a Page is first connected. Webhooks only deliver what arrives
 * after they are subscribed, so without this a business that has been talking
 * to customers for years opens the inbox and sees nothing.
 *
 * There is deliberately no WhatsApp equivalent: Meta publishes no endpoint for
 * WhatsApp message history, so there is nothing to read.
 */
class ImportMessengerHistory extends Command
{
    protected $signature = 'meta:import-messenger {--page= : The Facebook Page id, when more than one is connected} {--create-leads= : Also create a lead for each conversation, owned by this email address}';

    protected $description = 'Import a Page\'s past Messenger conversations into the chat inbox';

    public function handle(ImportMessengerHistoryAction $import): int
    {
        $pages = MetaPage::query()
            ->when($this->option('page'), fn ($query, $id) => $query->where('page_id', $id))
            ->get();

        if ($pages->isEmpty()) {
            $this->components->error('No Facebook Page is connected. Connect one under Settings → Meta connection.');

            return self::FAILURE;
        }

        // Off unless asked for: importing years of history is not the same as
        // receiving that many new enquiries, and 124 leads nobody asked for is
        // harder to undo than to skip.
        $owner = $this->option('create-leads') === null
            ? null
            : User::query()->where('email', $this->option('create-leads'))->first();

        if ($this->option('create-leads') !== null && $owner === null) {
            $this->components->error('No user with that email address, so there is nobody to own the leads.');

            return self::FAILURE;
        }

        foreach ($pages as $page) {
            try {
                $counts = $import($page, $owner);
            } catch (MetaApiException $exception) {
                $this->components->error($page->name.': '.$exception->userMessage());

                continue;
            }

            $this->components->info(sprintf(
                '%s — %d conversation%s, %d message%s imported%s.',
                $page->name,
                $counts['conversations'],
                $counts['conversations'] === 1 ? '' : 's',
                $counts['messages'],
                $counts['messages'] === 1 ? '' : 's',
                $counts['skipped'] > 0 ? ', '.$counts['skipped'].' skipped' : '',
            ));
        }

        return self::SUCCESS;
    }
}
