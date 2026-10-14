<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Recovery;

/** Ephemeral authorization: it is valid once, in this process, for this plan only. */
final class RecoveryAuthorization
{
    private bool $consumed = false;
    private bool $accepted = false;
    private readonly string $token;
    private readonly int $deadline;
    private readonly string $fingerprint;

    public function __construct(array $plan, private readonly \Closure $clock)
    {
        $this->fingerprint = self::fingerprint($plan);
        $this->token = bin2hex(random_bytes(32));
        $this->deadline = ($this->clock)() + 120;
    }

    public static function fingerprint(array $plan): string
    {
        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function challenge(): array
    {
        return ['action' => 'CREATE_EMPTY_CANONICAL_REGISTRY', 'plan_fingerprint' => $this->fingerprint,
            'token' => $this->token, 'expires_at' => $this->deadline,
            'confirmation' => 'CREATE EMPTY REGISTRY ' . $this->fingerprint];
    }

    public function validFor(array $plan): bool
    {
        return $this->accepted && ($this->clock)() < $this->deadline
            && hash_equals($this->fingerprint, self::fingerprint($plan));
    }

    public function consume(array $plan, array $response): bool
    {
        $valid = !$this->consumed && ($this->clock)() < $this->deadline
            && hash_equals($this->fingerprint, self::fingerprint($plan))
            && is_string($response['token'] ?? null) && hash_equals($this->token, $response['token'])
            && ($response['confirmation'] ?? null) === 'CREATE EMPTY REGISTRY ' . $this->fingerprint;
        $this->consumed = true;
        $this->accepted = $valid;
        return $valid;
    }
}
