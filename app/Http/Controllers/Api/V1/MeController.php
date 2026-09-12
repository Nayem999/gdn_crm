<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who the token belongs to, and what it may do.
 *
 * The first call anybody writing an integration makes, and the one that tells
 * them whether the key they pasted is the key they think it is.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $token = $user->currentAccessToken();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'token_name' => $token->name,
            'abilities' => $token->abilities,
        ]]);
    }
}
