<?php
declare(strict_types=1);

final class LlamadaIdempotenciaConflict extends RuntimeException {}

final class LlamadaIdempotenciaStore {
    private PDO $pdo;

    public function __construct(?string $dataDir = null) {
        $directory = $dataDir ?? (getenv('DATA_DIR') ?: '/data');
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create idempotency data directory');
        }

        $this->pdo = new PDO('sqlite:' . rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'llamada-resultados.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS result_operations (
            idempotency_key TEXT PRIMARY KEY,
            request_hash TEXT NOT NULL,
            state TEXT NOT NULL,
            response_json TEXT,
            comment_state TEXT,
            comment_id INTEGER,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )');
    }

    public function begin(string $idempotencyKey, string $requestHash, int $now): array {
        $this->pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;

        try {
            $record = $this->get($idempotencyKey);
            $isNew = false;
            if ($record === null) {
                $statement = $this->pdo->prepare('INSERT INTO result_operations
                    (idempotency_key, request_hash, state, response_json, comment_state, comment_id, created_at, updated_at)
                    VALUES (:idempotency_key, :request_hash, :state, NULL, NULL, NULL, :created_at, :updated_at)');
                $statement->execute([
                    ':idempotency_key' => $idempotencyKey,
                    ':request_hash' => $requestHash,
                    ':state' => 'processing',
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $record = $this->get($idempotencyKey);
                $isNew = true;
            } elseif (!hash_equals((string)$record['request_hash'], $requestHash)) {
                throw new LlamadaIdempotenciaConflict('idempotency key already belongs to another request');
            }

            $this->pdo->exec('COMMIT');
            $transactionOpen = false;
            return $record + ['is_new' => $isNew];
        } catch (Throwable $error) {
            if ($transactionOpen) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $error;
        }
    }

    public function complete(string $idempotencyKey, string $responseJson, string $commentState, ?int $commentId, int $now): void {
        $statement = $this->pdo->prepare('UPDATE result_operations
            SET state = :state, response_json = :response_json, comment_state = :comment_state,
                comment_id = :comment_id, updated_at = :updated_at
            WHERE idempotency_key = :idempotency_key');
        $statement->execute([
            ':state' => 'completed',
            ':response_json' => $responseJson,
            ':comment_state' => $commentState,
            ':comment_id' => $commentId,
            ':updated_at' => $now,
            ':idempotency_key' => $idempotencyKey,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('idempotency operation not found');
        }
    }

    public function get(string $idempotencyKey): ?array {
        $statement = $this->pdo->prepare('SELECT idempotency_key, request_hash, state, response_json, comment_state, comment_id, created_at, updated_at
            FROM result_operations WHERE idempotency_key = :idempotency_key');
        $statement->execute([':idempotency_key' => $idempotencyKey]);
        $record = $statement->fetch();
        return $record === false ? null : $record;
    }
}
