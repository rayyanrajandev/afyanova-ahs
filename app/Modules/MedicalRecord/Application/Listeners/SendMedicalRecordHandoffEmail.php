<?php

namespace App\Modules\MedicalRecord\Application\Listeners;

use App\Models\User;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffInitiated;
use App\Notifications\MedicalRecordHandoffNotification;
use Carbon\CarbonImmutable;

class SendMedicalRecordHandoffEmail
{
    public function handle(MedicalRecordHandoffInitiated $event): void
    {
        $targetUser = User::query()->find($event->targetUserId);

        if ($targetUser === null) {
            return;
        }

        $targetUser->notify(new MedicalRecordHandoffNotification(
            medicalRecordId: $event->medicalRecordId,
            recordNumber: $event->recordNumber,
            initiatorName: $event->initiatorName,
            handoffNote: $event->note,
            handedOffAt: CarbonImmutable::now(),
        ));
    }
}
