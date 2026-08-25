<?php

namespace App\Data;

use Random\RandomException;

class Token implements \JsonSerializable
{
    public function __construct(protected ?string $value = null)
    {
        if ($this->value === null) {
            $this->generate();
        }
    }

    /**
     * Constant-time comparison against the stored value.
     *
     * 存储层自 v1.7.0 起落库 SHA-256 哈希（防 DB/Redis 泄露后 token 被冒用）；
     * 对哈希前写入的存量明文记录保持兼容：先比对请求值的哈希，再回退原文比对，
     * 两次均为 hash_equals 时序安全比较。
     *
     * @param string $token
     * @return bool
     */
    public function matches(string $token): bool
    {
        if ($this->value === null) {
            return false;
        }
        return hash_equals($this->value, hash('sha256', $token))
            || hash_equals($this->value, $token);
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * @throws RandomException
     */
    protected function generate(): void
    {
        $this->value = bin2hex(random_bytes(32));
    }

    public function get(): ?string
    {
        return $this->value;
    }
}
