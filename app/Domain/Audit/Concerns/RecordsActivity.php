<?php

namespace App\Domain\Audit\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Records create, update and delete activity for a model.
 *
 * Attributes are opt-in through activityAttributes() rather than logged
 * wholesale: the audit trail must never capture a password hash, a two-factor
 * secret, a remember token or any other credential. Adding a sensitive column
 * to a model therefore does nothing until it is explicitly listed.
 */
trait RecordsActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('audit')
            ->logOnly($this->activityAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return static::activitySubjectLabel().' was '.$eventName;
    }

    /**
     * A human name for this model, shown in the audit viewer.
     */
    public static function activitySubjectLabel(): string
    {
        return str(class_basename(static::class))->headline()->toString();
    }

    /**
     * The attributes safe to record. Never include credentials.
     *
     * @return list<string>
     */
    abstract protected function activityAttributes(): array;
}
