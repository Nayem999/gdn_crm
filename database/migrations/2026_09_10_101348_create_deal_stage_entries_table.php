<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per *visit* a deal makes to a stage.
 *
 * A visit rather than a change, because a deal can come back to a stage it has
 * already been in — a renegotiation returning to Proposal is a second visit,
 * and its duration is its own.
 *
 * Not read from the audit log, which already records stage changes: that trail
 * is a credential-safe allowlist whose before/after values live in a JSON
 * column, so "average days in Negotiation" would mean unpacking JSON across
 * every row. Phase 10 asks exactly that question, and this table answers it
 * with an index.
 *
 * The stage's name and outcome are copied in rather than joined. A stage can be
 * renamed, or removed from its pipeline once nothing sits in it, and history
 * that changes retrospectively is not history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_stage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();

            // Which pipeline it was on at the time; a deal can be moved between
            // pipelines, and the visit belongs to the one it happened on.
            $table->foreignId('pipeline_id')->nullable()->constrained()->nullOnDelete();

            $table->string('stage_key', 64);
            $table->string('stage_name');
            $table->string('outcome', 10)->default('open');

            // dateTime, not timestamp, and this is load-bearing. MySQL and
            // MariaDB hand the first NOT NULL TIMESTAMP column in a table an
            // implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
            // unless explicit_defaults_for_timestamp is on. Closing a visit
            // UPDATEs the row to set left_at, and that silently rewrote
            // entered_at to the server clock — every duration wrong, with
            // nothing in the logs. DATETIME never gets that treatment.
            $table->dateTime('entered_at');
            // Null means this is where the deal is now. Exactly one open row
            // per deal, kept true by TracksStageHistory.
            $table->dateTime('left_at')->nullable();

            // Stored rather than derived on read: it is what Phase 10 averages,
            // and SUM over a column beats computing a difference per row. Null
            // while the visit is still open.
            $table->unsignedBigInteger('duration_seconds')->nullable();

            // Who moved it. Nullable because a queue or a console command has
            // nobody signed in.
            $table->foreignId('moved_by_id')->nullable()->constrained('users')->nullOnDelete();

            // No timestamps(): entered_at *is* when the row was created, and two
            // columns meaning the same thing invite them to disagree.

            // Reading one deal's history in order.
            $table->index(['deal_id', 'entered_at', 'id']);
            // Finding the open visit.
            $table->index(['deal_id', 'left_at']);
            // "How long does anything spend in this stage?"
            $table->index(['stage_key', 'entered_at']);
        });

        $this->backfill();
    }

    /**
     * Give deals that predate this table something to show.
     *
     * One open visit at the stage each deal is in now, starting when the deal
     * was created. It is deliberately a single synthetic visit: the moves those
     * deals actually made were never recorded, and inventing intermediate
     * timings would put numbers into reports that nobody measured.
     */
    private function backfill(): void
    {
        DB::table('deals')
            ->select('id', 'pipeline_id', 'stage', 'created_at', 'closed_at')
            ->orderBy('id')
            ->chunk(500, function ($deals) {
                $rows = [];

                foreach ($deals as $deal) {
                    // Read from the configured stage where it still exists, so
                    // the snapshot is right rather than guessed. A stage that
                    // has since been removed leaves the key standing in for its
                    // name and the visit counted as open.
                    $stage = DB::table('pipeline_stages')
                        ->where('pipeline_id', $deal->pipeline_id)
                        ->where('key', $deal->stage)
                        ->first(['name', 'outcome']);

                    $rows[] = [
                        'deal_id' => $deal->id,
                        'pipeline_id' => $deal->pipeline_id,
                        'stage_key' => (string) $deal->stage,
                        'stage_name' => (string) ($stage->name ?? $deal->stage),
                        'outcome' => (string) ($stage->outcome ?? 'open'),
                        'entered_at' => $deal->created_at ?? now(),
                        // The deal is sitting in this stage now, so the visit
                        // is still open even for one that has closed.
                        'left_at' => null,
                        'duration_seconds' => null,
                        'moved_by_id' => null,
                    ];
                }

                if ($rows !== []) {
                    DB::table('deal_stage_entries')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_entries');
    }
};
