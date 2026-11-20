<?php

namespace App\Support;

use App\Models\GeographicUnit;
use RuntimeException;

/**
 * Imports geography from a CSV in either layout:
 *
 *  1. COHERS template (header row required; see docs/geography-template.csv):
 *       type,code,name_en,name_km,latitude,longitude
 *     `type` may be English (province, district, commune, village, zone) or the Khmer
 *     words used in the NCDD gazetteer (ខេត្ត ស្រុក ក្រុង ខណ្ឌ ឃុំ សង្កាត់ ភូមិ).
 *     Coordinates are optional, as decimals or DMS; empty cells keep existing values.
 *
 *  2. Legacy coordinate file (database/data/prey_veng_gazetteer.csv):
 *       Zip Code,Name,Longtitude,Latitude,Type of Location   (DMS coordinates)
 *
 * The parent is derived from the code: 14 → 1401 → 140101 → 14010101. Rows are applied
 * parents-first, and re-importing updates existing codes instead of duplicating them.
 */
class GazetteerImporter
{
    /** Khmer names for the Prey Veng province and districts (legacy file has Latin names only). */
    private const KHMER = [
        '14' => 'ខេត្តព្រៃវែង',
        '1401' => 'បាភ្នំ', '1402' => 'កំចាយមារ', '1403' => 'កំពង់ត្របែក', '1404' => 'កញ្ជ្រៀច',
        '1405' => 'មេសាង', '1406' => 'ពាមជរ', '1407' => 'ពាមរក៍', '1408' => 'ពារាំង',
        '1409' => 'ព្រះស្ដេច', '1410' => 'ក្រុងព្រៃវែង', '1411' => 'ពោធិ៍រៀង', '1412' => 'ស៊ីធរកណ្ដាល',
        '1413' => 'ស្វាយអន្ទរ',
    ];

    /** NCDD level words and common English synonyms → COHERS unit type. */
    private const TYPE_ALIASES = [
        'province' => 'province', 'ខេត្ត' => 'province', 'រាជធានី' => 'province', 'capital' => 'province',
        'district' => 'district', 'ស្រុក' => 'district', 'ក្រុង' => 'district', 'ខណ្ឌ' => 'district',
        'municipality' => 'district', 'khan' => 'district',
        'commune' => 'commune', 'ឃុំ' => 'commune', 'សង្កាត់' => 'commune', 'sangkat' => 'commune',
        'village' => 'village', 'ភូមិ' => 'village',
        'zone' => 'zone',
    ];

    /** @var array<string, int> rows skipped in the last import, by reason */
    public array $skipped = [];

    public const MAX_ROWS = 20000;

    public function import(string $path): int
    {
        $rows = $this->load($path);

        $byCode = GeographicUnit::pluck('id', 'code')->all();
        $count = 0;
        foreach ($rows as $r) {
            $parentCode = $this->parentCode($r['code']);
            if ($parentCode && ! isset($byCode[$parentCode])) {
                // An orphan village or commune would break location paths and routing.
                $this->skip('parent code not found (add the parent row first)');

                continue;
            }
            $values = [
                'parent_id' => $parentCode ? $byCode[$parentCode] : null,
                'type' => $r['type'],
                'name_en' => $r['name_en'],
                'name_km' => $r['name_km'],
                'is_active' => true,
            ];
            // Empty coordinate cells keep whatever the unit already has.
            if ($r['lat'] !== null) {
                $values['latitude'] = $r['lat'];
            }
            if ($r['lng'] !== null) {
                $values['longitude'] = $r['lng'];
            }

            $unit = GeographicUnit::updateOrCreate(['code' => $r['code']], $values);
            $byCode[$r['code']] = $unit->id;
            $count++;
        }

        return $count;
    }

    /**
     * What import() would do, without writing anything: per level, how many units are
     * new, updated or unchanged, plus skipped rows and a sample of the changes.
     *
     * @return array{total:int, levels:array<string,array{new:int,updated:int,unchanged:int}>, skipped:array<string,int>, samples:list<array>}
     */
    public function preview(string $path, int $sampleSize = 15): array
    {
        $rows = $this->load($path);
        $existing = GeographicUnit::whereIn('code', array_column($rows, 'code'))->get()->keyBy('code');
        $knownCodes = array_flip(array_merge(
            GeographicUnit::pluck('code')->all(),
            array_column($rows, 'code'),
        ));

        $levels = [];
        $samples = [];
        $total = 0;
        foreach ($rows as $r) {
            $parentCode = $this->parentCode($r['code']);
            if ($parentCode && ! isset($knownCodes[$parentCode])) {
                $this->skip('parent code not found (add the parent row first)');

                continue;
            }

            $unit = $existing[$r['code']] ?? null;
            $changes = [];
            if ($unit) {
                foreach (['type' => 'type', 'name_en' => 'name_en', 'name_km' => 'name_km'] as $field => $key) {
                    if ((string) $unit->{$field} !== (string) $r[$key]) {
                        $changes[] = $field;
                    }
                }
                foreach (['latitude' => 'lat', 'longitude' => 'lng'] as $field => $key) {
                    if ($r[$key] !== null && abs((float) $unit->{$field} - $r[$key]) > 0.000001) {
                        $changes[] = $field;
                    }
                }
            }
            $action = ! $unit ? 'new' : ($changes ? 'updated' : 'unchanged');

            $levels[$r['type']] ??= ['new' => 0, 'updated' => 0, 'unchanged' => 0];
            $levels[$r['type']][$action]++;
            $total++;

            if ($action !== 'unchanged' && count($samples) < $sampleSize) {
                $samples[] = $r + ['action' => $action, 'changes' => $changes, 'old_name_km' => $unit?->name_km, 'old_name_en' => $unit?->name_en];
            }
        }

        $order = array_flip(GeographicUnit::TYPES);
        uksort($levels, fn ($a, $b) => $order[$a] <=> $order[$b]);

        return ['total' => $total, 'levels' => $levels, 'skipped' => $this->skipped, 'samples' => $samples];
    }

    /** Reads, validates and orders rows parents-first; resets the skip counters. */
    private function load(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}");
        }
        $this->skipped = [];
        $rows = $this->readRows($path);
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('The file has more than '.self::MAX_ROWS.' rows; split it by province.');
        }
        usort($rows, fn ($a, $b) => [strlen($a['code']), $a['code']] <=> [strlen($b['code']), $b['code']]);

        return $rows;
    }

    /** @return list<array{code:string,type:string,name_en:string,name_km:string,lat:?float,lng:?float}> */
    private function readRows(string $path): array
    {
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh) ?: [];
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $template = in_array('code', $header, true) && in_array('type', $header, true) && in_array('name_en', $header, true);
        $col = array_flip($header);
        $rows = [];

        while (($row = fgetcsv($fh)) !== false) {
            $row = array_map(fn ($v) => trim((string) $v), $row);
            if ($template) {
                $get = fn (string $k) => isset($col[$k]) ? ($row[$col[$k]] ?? '') : '';
                [$code, $type, $nameEn, $nameKm, $lat, $lng] = [$get('code'), $get('type'), $get('name_en'), $get('name_km'), $get('latitude'), $get('longitude')];
            } else {
                if (count($row) < 5) {
                    continue;
                }
                [$code, $nameEn, $lng, $lat, $type] = $row;
                $nameEn = $this->title($nameEn);
                $nameKm = '';
            }

            $code = preg_replace('/\D/', '', $code);
            $type = self::TYPE_ALIASES[mb_strtolower($type)] ?? null;
            if ($code === '' || ! $type) {
                $this->skip('missing code or unknown type');

                continue;
            }
            if ($nameEn === '' && $nameKm === '') {
                $this->skip('missing name');

                continue;
            }
            $nameKm = $nameKm !== '' ? $nameKm : (self::KHMER[$code] ?? $nameEn);

            $rows[] = [
                'code' => $code,
                'type' => $type,
                'name_en' => $nameEn !== '' ? $nameEn : $nameKm,
                'name_km' => $nameKm,
                'lat' => $lat === '' ? null : $this->dms($lat),
                'lng' => $lng === '' ? null : $this->dms($lng),
            ];
        }
        fclose($fh);

        return $rows;
    }

    /** 14 → none, 1401 → 14, 140101 → 1401, 14010101 → 140101; longer codes drop the last two digits. */
    private function parentCode(string $code): ?string
    {
        return strlen($code) <= 2 ? null : substr($code, 0, -2);
    }

    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    private function title(string $name): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $name);

        return $letters !== '' && ctype_upper($letters) ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE) : $name;
    }

    /** "E105° 23' 51.127"" → 105.3975; plain decimals pass through. */
    public function dms(string $value): ?float
    {
        if (! preg_match('/([NSEW])?\s*(\d+)°\s*(\d+)\'\s*([\d.]+)/u', $value, $m)) {
            return is_numeric($value) ? (float) $value : null;
        }
        $deg = (int) $m[2] + (int) $m[3] / 60 + (float) $m[4] / 3600;

        return round(in_array($m[1], ['S', 'W'], true) ? -$deg : $deg, 7);
    }
}
