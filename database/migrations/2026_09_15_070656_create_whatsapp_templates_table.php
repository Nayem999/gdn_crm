<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The messages Meta has approved this business to send.
 *
 * A cache of somebody else's decisions, and the only thing that makes replying
 * outside the 24-hour window possible at all. It is read rather than authored:
 * templates are written and submitted in Meta's own Business Manager, reviewed
 * by Meta, and can be paused or disabled by Meta days later without anybody
 * here being told — so what this table holds is **what was true at
 * `synced_at`**, and the send path checks the stored status rather than assuming
 * approval is permanent.
 *
 * Unique on `(name, language)` because that pair is what Meta identifies a
 * template by: one template name exists in as many languages as it has been
 * translated into, each approved separately, and sending the English copy to a
 * Bengali customer is a different message with a different approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();

            // Which business account's templates these are. A string rather than
            // a foreign key for the reason the rest of this phase joins on
            // Meta's ids: a template can be read before its account row exists,
            // and losing the template would be worse than a dangling reference.
            $table->string('waba_id', 64)->nullable()->index();

            // 191 rather than the 512 Meta permits: this is half of a unique
            // index, and the pair has to fit inside the index-length limit on
            // every engine this runs against — MariaDB 10.4 included. A real
            // template name is a short snake_case handle; nothing near the
            // ceiling exists in practice.
            $table->string('name', 191);
            // Meta's own locale codes — en_GB, bn_BD — not ours.
            $table->string('language', 16);

            // MARKETING, UTILITY, AUTHENTICATION. What Meta charges for and what
            // it holds the template to: a utility template used for marketing is
            // how an account gets its quality rating cut.
            $table->string('category', 40)->nullable();

            // APPROVED, PENDING, REJECTED, PAUSED, DISABLED. Stored because it
            // changes at Meta's end without warning, and a send against a
            // template that stopped being approved is refused by Meta with a
            // reason nobody here would otherwise understand.
            $table->string('status', 24)->default('PENDING');

            // The body as Meta holds it, `{{1}}` placeholders and all, so the
            // screen can show what will actually be sent.
            $table->text('body')->nullable();
            $table->string('header')->nullable();
            $table->string('footer')->nullable();

            // What has to be supplied at send time, in order. JSON because it is
            // a list that is only ever read whole, and never filtered on.
            $table->json('variables')->nullable();

            // Why Meta refused it, when it did. The one thing that tells
            // somebody what to change before resubmitting.
            $table->text('rejection_reason')->nullable();

            $table->dateTime('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['name', 'language'], 'whatsapp_templates_name_language_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
