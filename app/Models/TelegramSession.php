<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Conversation state for a Telegram user (wizard step + collected data). */
class TelegramSession extends Model
{
    protected $fillable = ['telegram_user_id', 'chat_id', 'state', 'data'];

    protected $casts = ['data' => 'array'];

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data ?? [], $key, $default);
    }

    public function put(array $values): self
    {
        $this->data = array_merge($this->data ?? [], $values);

        return $this;
    }

    public function transition(string $state, array $values = []): self
    {
        $this->state = $state;
        $this->put($values)->save();

        return $this;
    }

    public function reset(array $keep = []): self
    {
        $this->state = 'idle';
        $this->data = array_intersect_key($this->data ?? [], array_flip($keep));
        $this->save();

        return $this;
    }
}
