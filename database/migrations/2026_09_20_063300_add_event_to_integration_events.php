<?php

use App\Domain\Ingestion\WebhookEventName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of delivery each row was.
 *
 * The log could say who sent something, when, and whether it worked, but not
 * what it was — and that is the column somebody scans. On a live WhatsApp
 * number most deliveries are status receipts; the incoming messages are the
 * handful being looked for, and until now they were indistinguishable without
 * opening each payload.
 *
 * Stored rather than derived on read: it is filtered, sorted and exported, and
 * parsing a longText column per row to sort a page is not a list, it is a
 * table scan with extra steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_events', function (Blueprint $table) {
            // Nullable, because it is the sender's word and some senders do not
            // give one. A null means "did not say", which is a different fact
            // from any name this application could invent for it.
            $table->string('event', WebhookEventName::MAX)->nullable()->after('status');

            // The log reads one source at a time; narrowing that to one kind of
            // event is the query this column exists for.
            $table->index(['data_source_id', 'event']);
        });

        $this->backfill();
    }

    /**
     * Fill the column in for deliveries that arrived before it existed.
     *
     * The payloads are already stored, so the answer is already here — and a
     * log whose newest rows are labelled and whose older ones are blank reads
     * as though the old ones were of some unknown kind.
     *
     * Chunked by id rather than a single UPDATE: payloads are longText and a
     * busy installation has a great many of them, so this walks the table in
     * bounded memory instead of loading it.
     */
    private function backfill(): void
    {
        $lastId = 0;

        do {
            /** @var array<int, object{id: int, payload: ?string}> $rows */
            $rows = DB::table('integration_events')
                ->select('id', 'payload')
                ->where('id', '>', $lastId)
                ->whereNull('event')
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->all();

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $event = WebhookEventName::fromPayload($row->payload);

                if ($event === null) {
                    continue;
                }

                DB::table('integration_events')->where('id', $row->id)->update(['event' => $event]);
            }
        } while ($rows !== []);
    }

    public function down(): void
    {
        Schema::table('integration_events', function (Blueprint $table) {
            $table->dropIndex(['data_source_id', 'event']);
            $table->dropColumn('event');
        });
    }
};
