<?php

declare(strict_types=1);

namespace App\Ai\Support;

/**
 * One record's worth of retrieved content, still raw.
 *
 * `source` identifies where it came from — `task:412`, `comment:98`, `wiki:7` — and becomes
 * the `source` attribute on the wrapper, so a reviewer reading a flagged run can go straight
 * to the record. `content` is unwrapped and unescaped: {@see PromptBuilder} does that, once.
 */
final readonly class ContextItem
{
    public function __construct(
        public string $source,
        public string $content,
    ) {}

    public static function for(string $type, int|string $id, string $content): self
    {
        return new self(UntrustedData::label($type, $id), $content);
    }

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }

    public function withContent(string $content): self
    {
        return new self($this->source, $content);
    }
}
