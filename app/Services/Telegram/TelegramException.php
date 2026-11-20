<?php

namespace App\Services\Telegram;

use RuntimeException;

class TelegramException extends RuntimeException
{
    /** 403 = bot blocked by user / chat not found; retrying will not help. */
    public function isUndeliverable(): bool
    {
        return $this->getCode() === 403
            || str_contains(strtolower($this->getMessage()), 'chat not found')
            || str_contains(strtolower($this->getMessage()), 'blocked');
    }
}
