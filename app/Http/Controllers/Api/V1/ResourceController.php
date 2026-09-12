<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ApiModules;
use App\Domain\Api\Contracts\ApiModule;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every module the API exposes, through one controller.
 *
 * Four things hold here, and three of them are the reason the API is safe to
 * expose at all:
 *
 * - **The token is a user.** Sanctum authenticates it to the person who made
 *   it, so every policy and every access level applies exactly as it does in
 *   the browser. There is no "API user" with its own rules to get wrong.
 * - **The list is scoped before it is paginated.** `visibleTo()` is on the
 *   query, so "page 40" cannot walk past the end of what somebody may see.
 * - **A record outside the scope is a 404, not a 403.** Telling somebody a
 *   record exists but is not theirs is telling them it exists.
 * - The response shape is declared field by field by the module, so the next
 *   column added to a table is not published the day it is added.
 */
class ResourceController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, string $module): JsonResponse
    {
        $api = $this->module($module);
        $user = $this->user($request);

        $this->authorize('viewAny', $api->modelClass());

        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));

        $page = $api->query($user)->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => array_map(
                fn (Model $record): array => $api->toArray($record),
                $page->items()
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $module, int $id): JsonResponse
    {
        $api = $this->module($module);
        $record = $this->find($api, $this->user($request), $id);

        $this->authorize('view', $record);

        return response()->json(['data' => $api->toArray($record)]);
    }

    public function store(Request $request, string $module): JsonResponse
    {
        $api = $this->module($module);
        $user = $this->user($request);

        $this->authorize('create', $api->modelClass());

        $validated = $request->validate($api->rules(creating: true));

        $record = $api->create($validated, $user);

        return response()->json(['data' => $api->toArray($record)], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $module, int $id): JsonResponse
    {
        $api = $this->module($module);
        $record = $this->find($api, $this->user($request), $id);

        $this->authorize('update', $record);

        $validated = $request->validate($api->rules(creating: false));

        return response()->json(['data' => $api->toArray($api->update($record, $validated))]);
    }

    public function destroy(Request $request, string $module, int $id): JsonResponse
    {
        $api = $this->module($module);
        $record = $this->find($api, $this->user($request), $id);

        $this->authorize('delete', $record);

        $api->delete($record);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function module(string $key): ApiModule
    {
        $module = ApiModules::find($key);

        abort_if($module === null, Response::HTTP_NOT_FOUND, 'No such collection.');

        return $module;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }

    /**
     * Found within what this user may see, so a record outside their access
     * level is missing rather than forbidden.
     */
    private function find(ApiModule $api, User $user, int $id): Model
    {
        $record = $api->query($user)->find($id);

        abort_if($record === null, Response::HTTP_NOT_FOUND, 'Not found.');

        return $record;
    }
}
