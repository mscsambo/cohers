<?php

namespace App\Console\Commands;

use App\Support\GazetteerImporter;
use Illuminate\Console\Command;

class ImportGeography extends Command
{
    protected $signature = 'cohers:import-geography {path : CSV with Zip Code,Name,Longtitude,Latitude,Type of Location}';

    protected $description = 'Import or update province/district/commune/village codes from a gazetteer CSV';

    public function handle(GazetteerImporter $importer): int
    {
        $n = $importer->import($this->argument('path'));
        $this->info("Imported/updated {$n} geographic units.");

        return self::SUCCESS;
    }
}
