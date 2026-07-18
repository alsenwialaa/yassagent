<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Discovery;

/** Fairly merges bounded retrieval buckets so every semantic query contributes candidates. */
final class CatalogCandidateMerger
{
    /** @param array<int,int> $priorityIds @param array<int,array<int,int>> $buckets @return array<int,int> */
    public function merge(array $priorityIds, array $buckets, int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0) {
            return array();
        }
        $rows = array();
        $seen = array();
        foreach ($priorityIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $rows[] = $id;
            }
            if (count($rows) >= $limit) {
                return $rows;
            }
        }
        for ($offset = 0;; $offset++) {
            $added = false;
            foreach ($buckets as $bucket) {
                if (!array_key_exists($offset, $bucket)) {
                    continue;
                }
                $added = true;
                $id = (int) $bucket[$offset];
                if ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $rows[] = $id;
                }
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
            if (!$added) {
                break;
            }
        }
        return $rows;
    }
}
