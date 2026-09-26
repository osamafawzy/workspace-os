<?php

namespace App\Support\Import;

/**
 * Every importer the application has. Each module registers its own from its
 * service provider, the same way it registers its permissions.
 */
class Importers
{
    /** @var array<string, class-string<Importer>> */
    protected array $importers = [];

    /** @param  class-string<Importer>  $importer */
    public function register(string $importer): void
    {
        $this->importers[$importer::key()] = $importer;
    }

    public function get(string $key): ?Importer
    {
        return isset($this->importers[$key]) ? app($this->importers[$key]) : null;
    }

    /** @return array<string, class-string<Importer>> */
    public function all(): array
    {
        return $this->importers;
    }
}
