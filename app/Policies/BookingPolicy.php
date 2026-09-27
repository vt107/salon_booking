<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Mọi nhân viên xem và tạo lịch hộ khách được; duyệt / từ chối cần quyền quản lý.
 * Booking không bao giờ bị xóa: hủy thay vì xóa để giữ lịch sử và doanh thu.
 */
class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Booking $booking): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Booking $booking): bool
    {
        return true;
    }

    public function approve(User $user, Booking $booking): bool
    {
        return $user->canApproveBookings();
    }

    public function delete(User $user, Booking $booking): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
