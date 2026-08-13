<?php

namespace App\Modules\Notifications\Application\Services;

use App\Models\User;

class NotificationChannelAuthorizer
{
    public function authorize(User $user, int $userId): bool
    {
        return $user->id === $userId;
    }
}
