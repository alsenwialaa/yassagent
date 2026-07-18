<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Concurrency;

use InvalidArgumentException;

final class TurnLease
{
    /** @var string */ private $resource;
    /** @var string */ private $resourceHash;
    /** @var string */ private $owner;
    /** @var int */ private $fence;
    /** @var int */ private $expiresAt;

    public function __construct(
        string $resource,
        string $resourceHash,
        string $owner,
        int $fence,
        int $expiresAt
    ) {
        if (
            $resource === '' || strlen($resource) > 191
            || preg_match('/^[a-f0-9]{64}$/', $resourceHash) !== 1
            || !hash_equals(hash('sha256', $resource), $resourceHash)
            || preg_match('/^[a-f0-9]{32}$/', $owner) !== 1
            || $fence < 1
            || $expiresAt < 1
        ) {
            throw new InvalidArgumentException('Turn lease authority is invalid.');
        }
        $this->resource = $resource;
        $this->resourceHash = $resourceHash;
        $this->owner = $owner;
        $this->fence = $fence;
        $this->expiresAt = $expiresAt;
    }

    public function resource(): string
    {
        return $this->resource;
    }
    public function resourceHash(): string
    {
        return $this->resourceHash;
    }
    public function owner(): string
    {
        return $this->owner;
    }
    public function fence(): int
    {
        return $this->fence;
    }
    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function renewedUntil(int $expiresAt): self
    {
        return new self($this->resource, $this->resourceHash, $this->owner, $this->fence, $expiresAt);
    }
}
