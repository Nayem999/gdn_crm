<?php

namespace App\Domain\Users\Actions;

use App\Domain\Users\DTOs\UserData;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CreateUserAction
{
    public function __invoke(UserData $data, ?UploadedFile $avatar = null): User
    {
        return DB::transaction(function () use ($data, $avatar) {
            $user = User::create([
                ...$data->toAttributes(),
                // A user created by an administrator without a password can still
                // reach the account through the password reset flow.
                'password' => Hash::make($data->password ?? Str::random(32)),
            ]);

            if ($data->roleId !== null) {
                $role = Role::query()->find($data->roleId);

                if ($role !== null) {
                    $user->syncRoles([$role]);
                }
            }

            if ($avatar !== null) {
                $user->addMedia($avatar)->toMediaCollection('avatar');
            }

            return $user;
        });
    }
}
