<?php

namespace S3Gateway\Http;

/** @internal Forward-only request stream; an empty read means validated EOF. */
final class BodyInput
{
    private const PROTOCOL = 's3-request-body';
    /** @var resource PHP supplies the context. */
    public $context;
    private ?\Closure $read = null;
    private bool $ended = false;
    private ?\Throwable $failure = null;
    private int $position = 0;

    /** @param \Closure(int):string $read @return resource */
    public static function open(\Closure $read)
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)
            && !stream_wrapper_register(self::PROTOCOL, self::class)) {
            throw new \RuntimeException('Cannot register request body stream');
        }
        $context = stream_context_create([self::PROTOCOL => ['read' => $read]]);
        $stream = fopen(self::PROTOCOL . '://input', 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('Cannot open request body stream');
        }
        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->read = stream_context_get_options($this->context)[self::PROTOCOL]['read'];
        stream_context_set_option($this->context, self::PROTOCOL, 'read', null);
        return $mode === 'rb';
    }

    public function stream_read(int $count): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->ended) {
            return '';
        }
        try {
            $bytes = ($this->read)($count);
            $this->position += strlen($bytes);
            if ($bytes === '') {
                $this->ended = true;
                $this->read = null;
            }
            return $bytes;
        } catch (\Throwable $e) {
            $this->failure = $e;
            $this->ended = true;
            $this->read = null;
            throw $e;
        }
    }

    public function stream_eof(): bool
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->ended;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_stat(): array
    {
        return ['mode' => 0100444]; // Length is validated by Request at EOF.
    }

    public function stream_close(): void
    {
        $this->read = null;
        $this->ended = true;
    }
}
