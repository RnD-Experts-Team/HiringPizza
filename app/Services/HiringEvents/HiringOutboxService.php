<?php

namespace App\Services\HiringEvents;

use App\Models\HiringOutboxEvent;

class HiringOutboxService
{
    public function record(string $subject, array $payload): HiringOutboxEvent
    {

        $subject = $this->applyEnvironmentPrefix($subject);
        return HiringOutboxEvent::create([
            'subject' => $subject,
            'type' => $subject,
            'payload' => $payload,
        ]);
    }
    private function applyEnvironmentPrefix(string $subject): string
    {
        if (!config('nats.dev_mode')) {
            return $subject;
        }

        // Only transform Hiring + notifications domains
        if (str_starts_with($subject, 'hiring.v1.')) {
            return str_replace('hiring.v1.', 'hiring.testing.v1.', $subject);
        }

        if (str_starts_with($subject, 'notifications.v1.')) {
            return str_replace('notifications.v1.', 'notifications.testing.v1.', $subject);
        }

        return $subject;
    }
}
