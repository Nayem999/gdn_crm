<?php

namespace App\Console\Commands;

use App\Domain\Leads\Actions\EscalateLeadAssignmentsAction;
use Illuminate\Console\Command;

class EscalateLeadAssignments extends Command
{
    protected $signature = 'leads:escalate-assignments';

    protected $description = "Move a lead's escalation ladder along when its current priority tier has gone quiet too long";

    public function handle(EscalateLeadAssignmentsAction $escalate): int
    {
        $count = $escalate();

        $this->info($count === 1 ? '1 lead escalated.' : $count.' leads escalated.');

        return self::SUCCESS;
    }
}
