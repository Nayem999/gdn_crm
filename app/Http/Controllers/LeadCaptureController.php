<?php

namespace App\Http\Controllers;

use App\Domain\Leads\Actions\SubmitLeadCaptureAction;
use App\Domain\Leads\Capture\CaptureRejected;
use App\Domain\Leads\Models\LeadCaptureForm;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The public capture form.
 *
 * A plain controller and a Blade page rather than a Livewire component: the
 * form is meant to be embedded in an iframe on somebody else's site, where
 * third-party cookie policy makes anything session-dependent unreliable. This
 * needs no session, no CSRF token and no JavaScript to work.
 *
 * The route carries a **token**, not an id, and is resolved through `active()`
 * — but an inactive form still resolves, so that a link already out in the
 * world says "this form is closed" rather than 404ing at somebody who followed
 * it in good faith.
 */
class LeadCaptureController extends Controller
{
    public function show(string $token): View
    {
        $form = $this->form($token);

        return view('lead-capture.show', [
            'form' => $form,
            'fields' => $form->captureFields(),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse|View
    {
        $form = $this->form($token);

        try {
            app(SubmitLeadCaptureAction::class)($form, $request->all());
        } catch (CaptureRejected $rejected) {
            if (! $rejected->silent) {
                return back()->withInput()->withErrors(['form' => $rejected->getMessage()]);
            }

            // A spam rejection looks exactly like success. Telling a bot which
            // signal caught it is telling whoever wrote it what to change.
            return $this->done($form);
        }

        return $this->done($form);
    }

    /**
     * Where a visitor lands after submitting.
     */
    private function done(LeadCaptureForm $form): RedirectResponse|View
    {
        if ($form->redirect_url !== null) {
            // away(), not redirect(): the target is somebody else's site, and
            // the URL is validated as one when the form is saved.
            return redirect()->away($form->redirect_url);
        }

        return view('lead-capture.done', ['form' => $form]);
    }

    private function form(string $token): LeadCaptureForm
    {
        return LeadCaptureForm::query()->where('token', $token)->firstOrFail();
    }
}
