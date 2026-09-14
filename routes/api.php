<?php

use App\Domain\Api\ApiModules;
use App\Domain\Api\Documentation\OpenApiDocument;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\MetaWebhookController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ResourceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API
|--------------------------------------------------------------------------
|
| Versioned in the path. A version in a header is tidier and nobody uses it:
| an integration is written by somebody with a URL, and the URL is where they
| will look to find out which version they are on.
|
| Every route is authenticated by a Sanctum token, which belongs to a user —
| so the policies and access levels the browser obeys apply here unchanged.
| Writes additionally need the `write` ability, so a read-only key is a real
| thing rather than a promise.
|
| The collection segment is matched against the registry's keys, so a request
| can only ever name a module the API actually exposes.
|
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->group(function () {
        Route::get('/me', MeController::class);

        // Served live rather than from a built file: a description generated at
        // deploy time is one more thing that can be stale, and this one is
        // assembled from the registry in microseconds.
        Route::get('/openapi.json', fn (OpenApiDocument $document) => response()->json($document->build()))
            ->name('api.openapi');

        Route::get('/{module}', [ResourceController::class, 'index']);
        Route::get('/{module}/{id}', [ResourceController::class, 'show'])->whereNumber('id');

        Route::middleware('abilities:write')->group(function () {
            Route::post('/{module}', [ResourceController::class, 'store']);
            Route::patch('/{module}/{id}', [ResourceController::class, 'update'])->whereNumber('id');
            Route::put('/{module}/{id}', [ResourceController::class, 'update'])->whereNumber('id');
            Route::delete('/{module}/{id}', [ResourceController::class, 'destroy'])->whereNumber('id');
        });
    })
    ->whereIn('module', ApiModules::keys());

/*
|--------------------------------------------------------------------------
| Inbound data gateway
|--------------------------------------------------------------------------
|
| Outside the versioned API and outside Sanctum on purpose: this is not
| somebody reading their own records with a token, it is somebody else's system
| posting into ours, authenticated per source by a key and a signature.
|
| The uuid is matched in the controller rather than by model binding, so an
| unknown source, a deleted one and a switched-off one give the same answer.
|
*/
Route::post('/ingest/{source}', IngestController::class)
    ->middleware('throttle:ingest')
    ->name('api.ingest');

/*
|--------------------------------------------------------------------------
| Meta webhooks
|--------------------------------------------------------------------------
|
| GET is Meta's subscription handshake, POST is a delivery. Outside every auth
| group by necessity — the caller is Meta, not a person — and authenticated by
| the signature over the app secret, checked before anything is written down.
|
| Throttled, because an endpoint that anybody can post to is an endpoint anybody
| can flood. Meta's own volume sits far below this; a burst above it is not Meta.
|
*/
Route::get('/webhooks/meta/{channel}', [MetaWebhookController::class, 'verify'])
    ->middleware('throttle:60,1')
    ->name('api.webhooks.meta.verify');

Route::post('/webhooks/meta/{channel}', [MetaWebhookController::class, 'receive'])
    ->middleware('throttle:600,1')
    ->name('api.webhooks.meta');
