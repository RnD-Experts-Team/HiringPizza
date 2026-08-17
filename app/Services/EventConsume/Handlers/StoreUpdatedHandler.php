<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;

/**
 * `auth.v1.store.updated` carries deltas for name/metadata/is_active only —
 * none of which this service stores (our stores table is {id, store_number},
 * and store_number never changes in pizzasys). So there is nothing to apply;
 * what this handler exists for is the ordering guarantee below.
 */
class StoreUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(data_get($event, 'data.store_id') ?? data_get($event, 'store_id'));

        // fallback if producer sends data.store.id
        if ($id <= 0) {
            $id = $this->asInt(data_get($event, 'data.store.id') ?? data_get($event, 'store.id'));
        }

        if ($id <= 0) {
            throw new \Exception('StoreUpdatedHandler: missing/invalid store id');
        }

        // Ordering is not guaranteed across redeliveries, so an update can land
        // before its create. Throwing lets JetStreamConsumer NAK and retry
        // rather than materialising a store with no store_number — which is
        // exactly what the previous version of this handler did.
        if (!Store::query()->whereKey($id)->exists()) {
            throw new \Exception("StoreUpdatedHandler: store {$id} not synced yet");
        }
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }
}
