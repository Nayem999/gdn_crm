<?php

use App\Domain\Api\ApiModules;
use App\Domain\Api\Documentation\OpenApiDocument;
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
