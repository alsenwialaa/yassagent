<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/** Short MySQL connection-scoped lock for serialized metadata transitions. */
final class AdvisoryLock
{
    /** @var object */ private $database;
    /** @var string */ private $name;
    /** @var bool */ private $held = false;

    /** @param object $database wpdb-compatible database adapter */
    public function __construct($database, string $domain, string $scope)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/', $domain) !== 1 || $scope === '') {
            throw new \InvalidArgumentException('Database advisory-lock identity is invalid.');
        }
        $this->database = $database;
        $this->name = 'ysai_' . $domain . '_' . substr(hash('sha256', $scope), 0, 40);
    }

    public function acquire(int $timeoutSeconds): bool
    {
        $this->held = false;
        $this->clearLastError();
        $query = $this->database->prepare(
            'SELECT GET_LOCK(%s,%d)',
            $this->name,
            max(0, $timeoutSeconds)
        );
        if (!is_string($query) || $query === '') {
            return false;
        }
        $result = $this->database->get_var($query);
        if ($this->lastError() !== '') {
            return false;
        }
        $this->held = (string) $result === '1';
        return $this->held;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        $this->clearLastError();
        $query = $this->database->prepare('SELECT RELEASE_LOCK(%s)', $this->name);
        if (is_string($query) && $query !== '') {
            $this->database->get_var($query);
        }
        $this->held = false;
    }

    private function clearLastError(): void
    {
        $this->database->last_error = '';
    }

    private function lastError(): string
    {
        return trim((string) $this->database->last_error);
    }
}
