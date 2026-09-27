<?php

namespace App\Policies;

use App\Models\User;

/**
 * Danh mục do admin / quản lý quản lý: dịch vụ, nhóm dịch vụ, nhân viên, voucher, mã QR.
 */
abstract class CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canManageCatalog();
    }

    public function view(User $user): bool
    {
        return $user->role->canManageCatalog();
    }

    public function create(User $user): bool
    {
        return $user->role->canManageCatalog();
    }

    public function update(User $user): bool
    {
        return $user->role->canManageCatalog();
    }

    public function delete(User $user): bool
    {
        return $user->role->canManageCatalog();
    }

    public function deleteAny(User $user): bool
    {
        return $user->role->canManageCatalog();
    }

    public function restore(User $user): bool
    {
        return $user->role->canManageCatalog();
    }

    public function restoreAny(User $user): bool
    {
        return $user->role->canManageCatalog();
    }

    public function forceDelete(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->role->canManageCatalog();
    }
}
