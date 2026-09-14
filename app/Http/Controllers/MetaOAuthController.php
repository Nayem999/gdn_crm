<?php

namespace App\Http\Controllers;

use App\Domain\Meta\Actions\ConnectMetaAccountAction;
use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Models\MetaAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where Meta sends the administrator back to.
 *
 * A controller rather than a Livewire component because Meta performs a full
 * page redirect with the code in the query string, which is not something a
 * component can receive.
 *
 * **The state check is the whole security of this endpoint.** Without it,
 * anybody who can make an administrator's browser visit this URL with their own
 * `code` attaches *their* Meta business to *our* CRM — their page token written
 * into our database, their leads flowing into our pipeline, and an audit trail
 * saying our administrator did it. The state is random, kept in the session, and
 * consumed on use so a replayed callback cannot work twice.
 *
 * The code itself never reaches a log, a flash message or a redirect parameter.
 * It is single-use and short-lived, but it is a credential for the whole
 * duration of its life.
 */
class MetaOAuthController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MetaAuthService $auth,
        private readonly ConnectMetaAccountAction $connect,
    ) {}

    /**
     * Send the administrator to Meta.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $this->authorize('create', MetaAccount::class);

        $state = $this->auth->newState();

        $request->session()->put(MetaAuthService::STATE_KEY, $state);

        try {
            $url = $this->auth->authorizeUrl($state, route('settings.meta.callback'));
        } catch (MetaApiException $exception) {
            return redirect()
                ->route('settings.group', 'meta')
                ->with('error', $exception->getMessage());
        }

        return redirect()->away($url);
    }

    /**
     * Meta's answer.
     */
    public function callback(Request $request): RedirectResponse
    {
        $this->authorize('create', MetaAccount::class);

        $expected = $request->session()->pull(MetaAuthService::STATE_KEY);
        $state = $request->query('state');

        // Pulled, not read: a state that has been used is gone, so a callback
        // replayed from a browser's history cannot connect anything.
        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            Log::warning('A Meta OAuth callback arrived with a state that did not match.', [
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
            ]);

            return $this->back('That sign-in could not be verified. Start the connection again from this page.');
        }

        // Meta reports a declined consent screen as an error in the query
        // string rather than as a failed exchange.
        if ($request->query('error') !== null) {
            return $this->back('Meta did not grant access: '
                .(is_string($request->query('error_description')) ? $request->query('error_description') : 'the request was declined').'.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back('Meta did not return an authorisation code. Try again.');
        }

        try {
            $token = $this->auth->exchangeCode($code, route('settings.meta.callback'));
            $account = ($this->connect)($token, $request->user());
        } catch (MetaApiException $exception) {
            // Meta's own words here: an administrator connecting an integration
            // is the one audience for whom they are more use than our summary.
            return $this->back($exception->getMessage());
        } catch (Throwable $exception) {
            // Never the exception message: it can carry the exchange URL, and
            // that URL carries the code.
            report($exception);

            return $this->back('The connection could not be completed. The details are in the application log.');
        }

        return redirect()
            ->route('settings.meta.connect')
            ->with('status', 'Connected to '.$account->name.'. Choose what this CRM should use.');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('settings.meta.connect')->with('error', $message);
    }
}
