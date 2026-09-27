<?php

namespace App\Policies;

use App\Models\User;

/** Chỉ admin quản lý tài khoản đăng nhập */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->role->isAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->role->isAdmin() && ! $user->is($model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
