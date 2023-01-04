<?php

namespace ryunosuke\DbMigration\FileType\Tool;

use function ryunosuke\DbMigration\file_set_contents;

class Exportion
{
    private string $localname;

    private $data;

    public function __construct(string $localname, $data)
    {
        $this->localname = $localname;
        $this->data      = $data;
    }

    public function export(string $dirname, callable $encoder): string
    {
        file_set_contents("{$dirname}/{$this->localname}", $encoder($this->data));

        return $this->localname;
    }
}
