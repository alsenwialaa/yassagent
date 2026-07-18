<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/** Exact difference between the authoritative definition and physical storage. */
final class SchemaDiff
{
    /** @var array<string,array<string,mixed>> */ private $changes;

    /** @param array<string,array<string,mixed>> $changes */
    public function __construct(array $changes)
    {
        $this->changes = $changes;
    }

    public function isClean(): bool
    {
        foreach ($this->changes as $items) {
            if ($items !== array()) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int,string> */
    public function issueCodes(): array
    {
        $issues = array();
        foreach ($this->changes as $kind => $items) {
            foreach ($items as $key => $unused) {
                $issues[] = $kind . ':' . (string) $key;
            }
        }
        sort($issues, SORT_STRING);
        return $issues;
    }
}
