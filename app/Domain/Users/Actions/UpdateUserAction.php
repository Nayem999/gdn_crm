<?php

namespace App\Domain\Users\Actions;

use App\Domain\Users\DTOs\UserData;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UpdateUserAction
{
    public function __invoke(User $user, UserData $data, ?UploadedFile $avatar = null): User
    {
        return DB::transaction(function () use ($user, $data, $avatar) {
            $user->fill($data->toAttributes());

            if (filled($data->password)) {
                $user->password = Hash::make($data->password);
            }

            $user->save();

            if ($data->roleId !== null) {
                $role = Role::query()->find($data->roleId);

                if ($role !== null) {
                    $user->syncRoles([$role]);
                }
            }

            if ($avatar !== null) {
                $user->addMedia($avatar)->toMediaCollection('avatar');
            }

            return $user->refresh();
        });
    }
}
