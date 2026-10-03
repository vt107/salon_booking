<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return true;
    }

    /** Chặn / bỏ chặn khách đặt online (form thêm khách gọi không kèm bản ghi) */
    public function block(User $user, ?Customer $customer = null): bool
    {
        return $user->role->canManageCatalog();
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->role->isAdmin() && ! $customer->bookings()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
