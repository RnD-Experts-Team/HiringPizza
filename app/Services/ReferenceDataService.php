<?php

namespace App\Services;

use App\Models\AttachmentType;
use App\Models\AttachmentTag;
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
            'positions' => Position::query()->orderBy('label')->get(),
            'marital_statuses' => MaritalStatus::query()->orderBy('label')->get(),
            'id_types' => IdType::query()->orderBy('label')->get(),
            'attachment_types' => AttachmentType::query()->with('tags')->orderBy('label')->get(),
            'tags' => Tag::query()->orderBy('label')->get(),
        ];
    }

    public function sync(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $this->syncRows(Position::class, $payload['positions'] ?? [], ['label', 'description'], $payload['position_delete_ids'] ?? []);
            $this->syncRows(MaritalStatus::class, $payload['marital_statuses'] ?? [], ['label', 'description'], $payload['marital_status_delete_ids'] ?? []);
            $this->syncRows(IdType::class, $payload['id_types'] ?? [], ['label', 'description'], $payload['id_type_delete_ids'] ?? []);
            $this->syncAttachmentTypes($payload['attachment_types'] ?? [], $payload['attachment_type_delete_ids'] ?? []);
            $this->syncTags($payload['tags'] ?? [], $payload['tag_delete_ids'] ?? []);

            return $this->index();
        });
    }

    private function syncAttachmentTypes(array $rows, array $deleteIds): void
    {
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $tagIds = $row['tag_ids'] ?? null;
            $data = Arr::only($row, ['label', 'description']);

            if ($id) {
                AttachmentType::query()->whereKey($id)->update($data);
                $attachmentType = AttachmentType::query()->findOrFail($id);

                if (array_key_exists('tag_ids', $row)) {
                    $attachmentType->tags()->sync($tagIds ?? []);
                }

                continue;
            }

            $attachmentType = AttachmentType::query()->create($data);

            if (array_key_exists('tag_ids', $row)) {
                $attachmentType->tags()->sync($tagIds ?? []);
            }
        }

        if ($deleteIds !== []) {
            AttachmentTag::query()->whereIn('attachement_id', $deleteIds)->delete();
            AttachmentType::query()->whereIn('id', $deleteIds)->delete();
        }
    }

    private function syncTags(array $rows, array $deleteIds): void
    {
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $data = Arr::only($row, ['label']);

            if ($id) {
                Tag::query()->whereKey($id)->update($data);

                continue;
            }

            Tag::query()->create($data);
        }

        if ($deleteIds !== []) {
            AttachmentTag::query()->whereIn('tag_id', $deleteIds)->delete();
            Tag::query()->whereIn('id', $deleteIds)->delete();
        }
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
