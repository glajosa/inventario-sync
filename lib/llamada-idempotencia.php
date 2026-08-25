<?php
declare(strict_types=1);

final class LlamadaIdempotenciaConflict extends RuntimeException {}
final class LlamadaLeaseLost extends RuntimeException {}

final class LlamadaIdempotenciaStore {
    private const LEASE_SECONDS = 60;

    private PDO $pdo;
    private Closure $clock;

    public function __construct(?string $dataDir = null, ?callable $clock = null) {
        $directory = $dataDir ?? (getenv('DATA_DIR') ?: '/data');
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create idempotency data directory');
        }

        $this->pdo = new PDO('sqlite:' . rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'llamada-resultados.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->clock = $clock === null
            ? static fn(): int => time()
            : Closure::fromCallable($clock);
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS result_operations (
            idempotency_key TEXT PRIMARY KEY,
            request_hash TEXT NOT NULL,
            state TEXT NOT NULL,
            response_json TEXT,
            comment_state TEXT,
            comment_id INTEGER,
            lease_token TEXT,
            lease_until INTEGER,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )');
        $columns = $this->pdo->query('PRAGMA table_info(result_operations)')->fetchAll();
        $columnNames = array_column($columns, 'name');
        if (!in_array('lease_token', $columnNames, true)) {
            $this->pdo->exec('ALTER TABLE result_operations ADD COLUMN lease_token TEXT');
        }
        if (!in_array('lease_until', $columnNames, true)) {
            $this->pdo->exec('ALTER TABLE result_operations ADD COLUMN lease_until INTEGER');
        }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS result_cycles (
            operation_key TEXT PRIMARY KEY,
            deal_id INTEGER NOT NULL,
            bitrix_user_id INTEGER NOT NULL,
            pending_activity_id INTEGER,
            source TEXT NOT NULL,
            outcome TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS result_cycles_pending
            ON result_cycles(pending_activity_id)
            WHERE pending_activity_id IS NOT NULL');
    }

    public function now(): int {
        return ($this->clock)();
    }

    public function begin(string $idempotencyKey, string $requestHash, int $now): array {
        $this->pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;

        try {
            $record = $this->get($idempotencyKey);
            $isNew = false;
            if ($record === null) {
                $leaseToken = bin2hex(random_bytes(16));
                $statement = $this->pdo->prepare('INSERT INTO result_operations
                    (idempotency_key, request_hash, state, response_json, comment_state, comment_id,
                     lease_token, lease_until, created_at, updated_at)
                    VALUES (:idempotency_key, :request_hash, :state, NULL, NULL, NULL,
                            :lease_token, :lease_until, :created_at, :updated_at)');
                $statement->execute([
                    ':idempotency_key' => $idempotencyKey,
                    ':request_hash' => $requestHash,
                    ':state' => 'processing',
                    ':lease_token' => $leaseToken,
                    ':lease_until' => $now + self::LEASE_SECONDS,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $record = $this->get($idempotencyKey);
                $isNew = true;
            } elseif (!hash_equals((string)$record['request_hash'], $requestHash)) {
                throw new LlamadaIdempotenciaConflict('idempotency key already belongs to another request');
            } elseif ((string)$record['state'] === 'retryable'
                || ((string)$record['state'] === 'processing'
                    && (string)($record['comment_state'] ?? '') !== 'in_progress'
                    && (int)($record['lease_until'] ?? ((int)$record['updated_at'] + self::LEASE_SECONDS)) < $now)) {
                $leaseToken = bin2hex(random_bytes(16));
                $statement = $this->pdo->prepare('UPDATE result_operations
                    SET state = :state, lease_token = :lease_token, lease_until = :lease_until,
                        updated_at = :updated_at
                    WHERE idempotency_key = :idempotency_key');
                $statement->execute([
                    ':state' => 'processing',
                    ':lease_token' => $leaseToken,
                    ':lease_until' => $now + self::LEASE_SECONDS,
                    ':updated_at' => $now,
                    ':idempotency_key' => $idempotencyKey,
                ]);
                $record = $this->get($idempotencyKey);
                $isNew = true;
            }

            $this->pdo->exec('COMMIT');
            $transactionOpen = false;
            if (!$isNew) $record['lease_token'] = null;
            return $record + ['is_new' => $isNew];
        } catch (Throwable $error) {
            if ($transactionOpen) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $error;
        }
    }

    public function claimCycle(
        string $operationKey,
        int $dealId,
        int $bitrixUserId,
        ?int $pendingActivityId,
        string $source,
        string $outcome,
        int $now
    ): array {
        if ($operationKey === '' || $dealId <= 0 || $bitrixUserId <= 0
            || ($pendingActivityId !== null && $pendingActivityId <= 0)) {
            throw new InvalidArgumentException('invalid call cycle identity');
        }
        if (!in_array($source, ['mobile', 'panel'], true)) {
            throw new InvalidArgumentException('invalid call cycle source');
        }
        if (!in_array($outcome, ['no_answer', 'answered', 'not_interested'], true)) {
            throw new InvalidArgumentException('invalid call cycle outcome');
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            $record = $this->findCycleRecord(
                $dealId,
                $bitrixUserId,
                $pendingActivityId,
                $source,
                $now
            );

            $isNew = false;
            if ($record === null) {
                $statement = $this->pdo->prepare('INSERT INTO result_cycles
                    (operation_key, deal_id, bitrix_user_id, pending_activity_id, source, outcome, created_at)
                    VALUES (:operation_key, :deal_id, :bitrix_user_id, :pending_activity_id,
                            :source, :outcome, :created_at)');
                $statement->execute([
                    ':operation_key' => $operationKey,
                    ':deal_id' => $dealId,
                    ':bitrix_user_id' => $bitrixUserId,
                    ':pending_activity_id' => $pendingActivityId,
                    ':source' => $source,
                    ':outcome' => $outcome,
                    ':created_at' => $now,
                ]);
                $record = [
                    'operation_key' => $operationKey,
                    'source' => $source,
                    'outcome' => $outcome,
                ];
                $isNew = true;
            }

            $this->pdo->exec('COMMIT');
            $transactionOpen = false;
            return $record + ['is_new' => $isNew];
        } catch (Throwable $error) {
            if ($transactionOpen) $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }

    public function findCycle(
        int $dealId,
        int $bitrixUserId,
        ?int $pendingActivityId,
        string $source,
        int $now
    ): ?array {
        if ($dealId <= 0 || $bitrixUserId <= 0
            || ($pendingActivityId !== null && $pendingActivityId <= 0)) {
            throw new InvalidArgumentException('invalid call cycle identity');
        }
        if (!in_array($source, ['mobile', 'panel'], true)) {
            throw new InvalidArgumentException('invalid call cycle source');
        }
        return $this->findCycleRecord($dealId, $bitrixUserId, $pendingActivityId, $source, $now);
    }

    private function findCycleRecord(
        int $dealId,
        int $bitrixUserId,
        ?int $pendingActivityId,
        string $source,
        int $now
    ): ?array {
        if ($pendingActivityId !== null) {
            $statement = $this->pdo->prepare('SELECT operation_key, source, outcome
                FROM result_cycles WHERE pending_activity_id = :pending_activity_id LIMIT 1');
            $statement->execute([':pending_activity_id' => $pendingActivityId]);
            $found = $statement->fetch();
            if ($found !== false) return $found;
        }

        $statement = $this->pdo->prepare('SELECT operation_key, source, outcome
            FROM result_cycles
            WHERE deal_id = :deal_id
              AND bitrix_user_id = :bitrix_user_id
              AND created_at >= :created_after
              AND (source = \'panel\' OR :request_source = \'panel\')
            ORDER BY created_at DESC, operation_key DESC
            LIMIT 1');
        $statement->execute([
            ':deal_id' => $dealId,
            ':bitrix_user_id' => $bitrixUserId,
            ':created_after' => $now - 1_800,
            ':request_source' => $source,
        ]);
        $found = $statement->fetch();
        return $found === false ? null : $found;
    }

    public function complete(
        string $idempotencyKey,
        string $responseJson,
        string $commentState,
        ?int $commentId,
        int $now,
        ?string $leaseToken = null
    ): void {
        $owned = $leaseToken !== null;
        $statement = $this->pdo->prepare('UPDATE result_operations
            SET state = :state, response_json = :response_json, comment_state = :comment_state,
                comment_id = :comment_id, lease_token = NULL, lease_until = NULL, updated_at = :updated_at
            WHERE idempotency_key = :idempotency_key' . ($owned
                ? ' AND state = \'processing\' AND lease_token = :lease_token AND lease_until >= :lease_now'
                : ''));
        $parameters = [
            ':state' => 'completed',
            ':response_json' => $responseJson,
            ':comment_state' => $commentState,
            ':comment_id' => $commentId,
            ':updated_at' => $now,
            ':idempotency_key' => $idempotencyKey,
        ];
        if ($owned) {
            $parameters[':lease_token'] = $leaseToken;
            $parameters[':lease_now'] = $now;
        }
        $statement->execute($parameters);

        if ($statement->rowCount() !== 1) {
            if ($owned) throw new LlamadaLeaseLost('idempotency lease lost before completion');
            throw new RuntimeException('idempotency operation not found');
        }
    }

    public function checkpoint(
        string $idempotencyKey,
        string $responseJson,
        ?string $commentState,
        ?int $commentId,
        int $now,
        string $state,
        string $leaseToken
    ): void {
        if (!in_array($state, ['processing', 'retryable'], true)) {
            throw new InvalidArgumentException('invalid idempotency checkpoint state');
        }
        $retryable = $state === 'retryable';
        $statement = $this->pdo->prepare('UPDATE result_operations
            SET state = :state, response_json = :response_json, comment_state = :comment_state,
                comment_id = :comment_id,
                lease_token = ' . ($retryable ? 'NULL' : ':next_lease_token') . ',
                lease_until = ' . ($retryable ? 'NULL' : ':lease_until') . ',
                updated_at = :updated_at
            WHERE idempotency_key = :idempotency_key
              AND state = \'processing\' AND lease_token = :lease_token AND lease_until >= :lease_now');
        $parameters = [
            ':state' => $state,
            ':response_json' => $responseJson,
            ':comment_state' => $commentState,
            ':comment_id' => $commentId,
            ':updated_at' => $now,
            ':idempotency_key' => $idempotencyKey,
            ':lease_token' => $leaseToken,
            ':lease_now' => $now,
        ];
        if (!$retryable) {
            $parameters[':next_lease_token'] = $leaseToken;
            $parameters[':lease_until'] = $now + self::LEASE_SECONDS;
        }
        $statement->execute($parameters);

        if ($statement->rowCount() !== 1) {
            throw new LlamadaLeaseLost('idempotency lease lost before checkpoint');
        }
    }

    public function forbid(string $idempotencyKey, int $now, string $leaseToken): void {
        $statement = $this->pdo->prepare('UPDATE result_operations
            SET state = :state,
                comment_state = CASE
                    WHEN comment_state = \'in_progress\' AND comment_id IS NULL THEN \'pending\'
                    ELSE comment_state
                END,
                lease_token = NULL, lease_until = NULL, updated_at = :updated_at
            WHERE idempotency_key = :idempotency_key
              AND state = \'processing\' AND lease_token = :lease_token AND lease_until >= :lease_now');
        $statement->execute([
            ':state' => 'forbidden',
            ':updated_at' => $now,
            ':idempotency_key' => $idempotencyKey,
            ':lease_token' => $leaseToken,
            ':lease_now' => $now,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new LlamadaLeaseLost('idempotency lease lost before forbidden transition');
        }
    }

    public function get(string $idempotencyKey): ?array {
        $statement = $this->pdo->prepare('SELECT idempotency_key, request_hash, state, response_json,
                comment_state, comment_id, lease_token, lease_until, created_at, updated_at
            FROM result_operations WHERE idempotency_key = :idempotency_key');
        $statement->execute([':idempotency_key' => $idempotencyKey]);
        $record = $statement->fetch();
        return $record === false ? null : $record;
    }
}
