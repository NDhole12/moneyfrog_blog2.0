<?php

namespace MoneyfrogCore;

/**
 * Fixed-window failed-login limiter. Storage and clock are injected so the
 * decision logic runs without WordPress.
 */
final class LoginLimiter
{
    /**
     * @param callable(string):mixed $get           returns the stored record or false
     * @param callable(string,array,int):void $set  stores a record with a TTL in seconds
     * @param callable(string):void $delete         removes a record
     * @param callable():int $now                   current unix time
     */
    public function __construct(
        private $get,
        private $set,
        private $delete,
        private $now,
        private int $maxFailures = 5,
        private int $windowSeconds = 900,
    ) {
    }

    public function isBlocked(string $key): bool
    {
        return $this->record($key)['count'] >= $this->maxFailures;
    }

    public function recordFailure(string $key): void
    {
        $record = $this->record($key);
        if ($record['count'] >= $this->maxFailures) {
            return; // blocked: never extend the window
        }
        $now = ($this->now)();
        $expires = $record['expires'] ?: $now + $this->windowSeconds;
        ($this->set)($key, ['count' => $record['count'] + 1, 'expires' => $expires], max(1, $expires - $now));
    }

    public function clear(string $key): void
    {
        ($this->delete)($key);
    }

    /** @return array{count:int,expires:int} */
    private function record(string $key): array
    {
        $stored = ($this->get)($key);
        if (!is_array($stored) || (int) ($stored['expires'] ?? 0) <= ($this->now)()) {
            return ['count' => 0, 'expires' => 0];
        }
        return ['count' => (int) $stored['count'], 'expires' => (int) $stored['expires']];
    }
}
