<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use YassinStore\AiAssistant\Application\Port\TurnStorePort;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Chat\TurnRecord;
use YassinStore\AiAssistant\Domain\Chat\TurnReservation;
use YassinStore\AiAssistant\Domain\Chat\TurnStatus;
use YassinStore\AiAssistant\Domain\Exception\InvalidRequest;
use YassinStore\AiAssistant\Domain\Exception\LeaseLostException;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

final class TurnRepository implements TurnStorePort
{
    public function find(int $conversationId, string $turnId): ?TurnRecord
    {
        if ($conversationId < 1) {
            throw new RuntimeException('Turn conversation identity is invalid.');
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::turns()
                . ' WHERE conversation_id = %d AND turn_id = %s LIMIT 1',
                $conversationId,
                $this->turnId($turnId)
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to read turn state.');
        }
        return is_array($row) ? $this->hydrate($row) : null;
    }


    public function findActive(int $conversationId): ?TurnRecord
    {
        if ($conversationId < 1) {
            throw new RuntimeException('Turn conversation identity is invalid.');
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::turns()
                . ' WHERE conversation_id = %d AND status IN (%s,%s)'
                . ' ORDER BY id ASC LIMIT 1',
                $conversationId,
                TurnStatus::RECEIVED,
                TurnStatus::RUNNING
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to read active turn state.');
        }
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string,mixed> $input */
    public function reserve(
        int $conversationId,
        string $turnId,
        string $requestHash,
        array $input
    ): TurnReservation {
        if (
            $conversationId < 1 || preg_match('/^[a-f0-9]{64}$/', $requestHash) !== 1
            || ($input !== array() && \YassinStore\AiAssistant\Support\Arr::isList($input))
        ) {
            throw new RuntimeException('Turn reservation authority is invalid.');
        }
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . SchemaRegistry::turns()
                . ' (conversation_id,turn_id,request_hash,status,lease_fence,input_payload,'
                . ' response_payload,failure_code,created_at,updated_at,completed_at)'
                . ' VALUES (%d,%s,%s,%s,0,%s,NULL,%s,%s,%s,NULL)',
                $conversationId,
                $this->turnId($turnId),
                $requestHash,
                TurnStatus::RECEIVED,
                Json::encodeObject($input),
                '',
                $now,
                $now
            )
        );
        if ($inserted === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to reserve turn.');
        }

        $turn = $this->find($conversationId, $turnId);
        if ($turn === null) {
            throw new RuntimeException('Unable to reserve turn.');
        }
        if (!hash_equals($turn->requestHash(), $requestHash)) {
            throw new InvalidRequest(
                'turn_id_conflict',
                ('تم استخدام معرّف هذا الطلب لمحتوى مختلف.'),
                'The client turn ID was reused with a different canonical request hash.',
                409
            );
        }

        return new TurnReservation($turn, $inserted === 1);
    }

    public function claim(TurnRecord $turn, int $fence): TurnRecord
    {
        if ($turn->isTerminal()) {
            return $turn;
        }

        global $wpdb;
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . SchemaRegistry::turns()
                . ' SET status = %s, lease_fence = %d, updated_at = %s'
                . ' WHERE id = %d AND lease_fence <= %d AND status IN (%s,%s)',
                TurnStatus::RUNNING,
                $fence,
                gmdate('Y-m-d H:i:s'),
                $turn->id(),
                $fence,
                TurnStatus::RECEIVED,
                TurnStatus::RUNNING
            )
        );
        if ($updated === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to claim turn.');
        }

        $claimed = $this->find($turn->conversationId(), $turn->turnId());
        // A duplicate request may have waited for the lease while the original
        // worker completed. In that race the canonical terminal record is the
        // replay authority; it must be returned instead of misreported as lease
        // loss merely because it carries the previous fence.
        if ($claimed !== null && $claimed->isTerminal()) {
            return $claimed;
        }
        if ($claimed === null || $claimed->leaseFence() !== $fence || $claimed->status() !== TurnStatus::RUNNING) {
            throw new LeaseLostException('The turn could not be claimed by the current lease.');
        }
        return $claimed;
    }

    /** Must be called inside a transaction. */
    public function assertClaimedForUpdate(int $turnId, int $fence): TurnRecord
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::turns() . ' WHERE id = %d LIMIT 1 FOR UPDATE',
                $turnId
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to lock the claimed turn.');
        }
        if (!is_array($row)) {
            throw new LeaseLostException('The claimed turn no longer exists.');
        }
        $turn = $this->hydrate($row);
        if ($turn->status() !== TurnStatus::RUNNING || $turn->leaseFence() !== $fence) {
            throw new LeaseLostException('A stale worker cannot commit this turn.');
        }
        return $turn;
    }

    /** @param array<string,mixed> $response */
    public function complete(
        int $turnId,
        int $fence,
        string $status,
        array $response,
        string $failureCode
    ): void {
        if (!TurnStatus::isTerminal($status)) {
            throw new RuntimeException('Turn completion status is invalid.');
        }

        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . SchemaRegistry::turns()
                . ' SET status = %s, response_payload = %s, failure_code = %s, updated_at = %s, completed_at = %s'
                . ' WHERE id = %d AND status = %s AND lease_fence = %d',
                $status,
                Json::encodeObject($response),
                $this->failureCode($failureCode),
                $now,
                $now,
                $turnId,
                TurnStatus::RUNNING,
                $fence
            )
        );
        if ($updated !== 1 || WpdbError::has($wpdb)) {
            throw new LeaseLostException('A stale worker cannot complete this turn.');
        }
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): TurnRecord
    {
        return new TurnRecord(
            (int) $row['id'],
            (int) $row['conversation_id'],
            (string) $row['turn_id'],
            (string) $row['request_hash'],
            (string) $row['status'],
            (int) $row['lease_fence'],
            Json::decodeRequiredObject((string) ($row['input_payload'] ?? ''), 'Turn input payload'),
            Json::decodeOptionalObject((string) ($row['response_payload'] ?? ''), 'Turn response payload'),
            (string) ($row['failure_code'] ?? ''),
            $this->timestamp((string) $row['created_at']),
            $this->timestamp((string) $row['updated_at']),
            $this->timestamp((string) ($row['completed_at'] ?? ''))
        );
    }


    private function turnId(string $turnId): string
    {
        if (!Uuid::isV4($turnId)) {
            throw new RuntimeException('Turn identifier must be a UUIDv4.');
        }
        return strtolower($turnId);
    }

    private function failureCode(string $failureCode): string
    {
        if (preg_match('/^[a-z0-9_]{0,64}$/', $failureCode) !== 1) {
            throw new RuntimeException('Turn failure code is invalid.');
        }
        return $failureCode;
    }

    private function timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false) {
            throw new RuntimeException('Turn timestamp is invalid.');
        }
        return $timestamp;
    }
}
