<?php

namespace App\Services;

use App\Models\AttachmentType;
use App\Models\IdType;
use App\Models\MaritalStatus;
use App\Models\Position;
use App\Models\Tag;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ReferenceDataService
{
    public function index(): array
    {
        return [
            'positions' => Position::query()->orderBy('lebel')->get(),
            'marital_statuses' => MaritalStatus::query()->orderBy('label')->get(),
            'id_types' => IdType::query()->orderBy('label')->get(),
            'attachment_types' => AttachmentType::query()->orderBy('label')->get(),
            'tags' => Tag::query()->orderBy('label')->get(),
        ];
    }

    public function sync(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $this->syncRows(Position::class, $payload['positions'] ?? [], ['lebel', 'description'], $payload['position_delete_ids'] ?? []);
            $this->syncRows(MaritalStatus::class, $payload['marital_statuses'] ?? [], ['label', 'description'], $payload['marital_status_delete_ids'] ?? []);
            $this->syncRows(IdType::class, $payload['id_types'] ?? [], ['label', 'description'], $payload['id_type_delete_ids'] ?? []);
            $this->syncRows(AttachmentType::class, $payload['attachment_types'] ?? [], ['label', 'description'], $payload['attachment_type_delete_ids'] ?? []);
            $this->syncRows(Tag::class, $payload['tags'] ?? [], ['label'], $payload['tag_delete_ids'] ?? []);

            return $this->index();
        });
    }

    private function syncRows(string $modelClass, array $rows, array $columns, array $deleteIds): void
    {
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $data = Arr::only($row, $columns);

            if ($id) {
                $modelClass::query()->whereKey($id)->update($data);

                continue;
            }

            $modelClass::query()->create($data);
        }

        if ($deleteIds !== []) {
            $modelClass::query()->whereIn('id', $deleteIds)->delete();
        }
    }
}
