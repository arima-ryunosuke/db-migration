<?php

namespace ryunosuke\DbMigration\FileType\Tool;

class Binary
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function base64(): string
    {
        return rtrim(base64_encode($this->value), '=');
    }

    public function xstring(): string
    {
        return 'x' . bin2hex($this->value);
    }
}
