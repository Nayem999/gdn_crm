<?php

namespace App\Domain\Webhooks;

use App\Domain\Api\ApiModules;
use App\Domain\Webhooks\Actions\DispatchWebhookAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Turns record changes into webhook deliveries.
 *
 * A second observer rather than more work inside the workflow one. They watch
 * the same models and look alike, but they answer to different people: a
 * workflow is something an administrator built inside the CRM, and a webhook is
 * a promise to somebody else's system. Entangling them would mean suppressing
 * one suppresses the other, which is never what anybody wants.
 *
 * **Deferred to after the transaction commits.** A webhook is an outbound fact:
 * once somebody else's system has been told a contact exists, a rollback here
 * cannot untell them.
 */
class WebhookObserver
{
    public function created(Model $model): void
    {
        $this->fire($model, WebhookEvents::CREATED);
    }

    public function updated(Model $model): void
    {
        $this->fire($model, WebhookEvents::UPDATED);
    }

    public function deleted(Model $model): void
    {
        $this->fire($model, WebhookEvents::DELETED);
    }

    private function fire(Model $model, string $action): void
    {
        $module = $this->moduleFor($model);

        if ($module === null) {
            return;
        }

        DB::afterCommit(fn () => app(DispatchWebhookAction::class)->handle($module, $action, $model));
    }

    /**
     * Which API collection this record belongs to, if any.
     *
     * Matched on the model class through the registry rather than guessed from
     * the table name: the event key is part of a published contract, and
     * deriving it from anything renameable would break that contract the day
     * somebody renames it.
     */
    private function moduleFor(Model $model): ?string
    {
        foreach (ApiModules::keys() as $key) {
            $module = ApiModules::find($key);

            if ($module !== null && $model instanceof ($module->modelClass())) {
                return $key;
            }
        }

        return null;
    }
}
