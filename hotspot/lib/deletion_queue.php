<?php

function deletionQueueFile(): string
{
    return defined('MIKROTIK_DELETION_QUEUE_FILE')
        ? MIKROTIK_DELETION_QUEUE_FILE
        : sys_get_temp_dir() . '/hotspot_mikrotik_deletions.json';
}

function withDeletionQueue(callable $change): array
{
    $file = deletionQueueFile();
    $lock = fopen($file . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Unable to lock deletion queue');
    }
    try {
        $queue = [];
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) $queue = $decoded;
        }
        $result = $change($queue);
        $tmp = tempnam(dirname($file), '.hotspot_delete_');
        if ($tmp === false || file_put_contents($tmp, json_encode(array_values($queue), JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !rename($tmp, $file)) {
            if ($tmp && is_file($tmp)) @unlink($tmp);
            throw new RuntimeException('Unable to write deletion queue');
        }
        @chmod($file, 0600);
        return is_array($result) ? $result : [];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function enqueueMikrotikDeletion(string $id, string $citizenId): void
{
    withDeletionQueue(function (array &$queue) use ($id, $citizenId): array {
        foreach ($queue as $item) {
            if (($item['id'] ?? '') === $id) return [];
        }
        $queue[] = [
            'id' => $id,
            'citizen_id' => $citizenId,
            'queued_at' => gmdate('c'),
        ];
        return [];
    });
}

function pendingMikrotikDeletions(): array
{
    return withDeletionQueue(function (array &$queue): array {
        return array_slice(array_values($queue), 0, 50);
    });
}

function removeMikrotikDeletion(string $id): void
{
    withDeletionQueue(function (array &$queue) use ($id): array {
        $queue = array_values(array_filter($queue, function ($item) use ($id): bool {
            return ($item['id'] ?? '') !== $id;
        }));
        return [];
    });
}
