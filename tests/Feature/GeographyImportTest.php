<?php

namespace Tests\Feature;

use App\Models\GeographicUnit;
use App\Support\GazetteerImporter;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeographyImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($path, "\xEF\xBB\xBF".$body); // Excel-style UTF-8 BOM

        return $path;
    }

    public function test_template_with_ncdd_khmer_types_builds_the_hierarchy(): void
    {
        // Children listed before parents on purpose; Khmer NCDD level words.
        $path = $this->csv(implode("\n", [
            'type,code,name_en,name_km,latitude,longitude',
            'ភូមិ,20010101,Village One,ភូមិមួយ,,',
            'ឃុំ,200101,Commune One,ឃុំមួយ,11.1,105.8',
            'ក្រុង,2001,Svay Rieng Town,ក្រុងស្វាយរៀង,,',
            'province,20,Svay Rieng,ខេត្តស្វាយរៀង,11.08,105.8',
        ])."\n");

        $n = app(GazetteerImporter::class)->import($path);

        $this->assertSame(4, $n);
        $village = GeographicUnit::where('code', '20010101')->first();
        $this->assertSame('village', $village->type);
        $this->assertSame('ភូមិមួយ', $village->name_km);
        $this->assertSame('Svay Rieng Town › Commune One › Village One', $village->fullPath(), 'parents linked from the codes');
        $this->assertSame('20', $village->ancestorOfType('province')->code);
        $this->assertSame('district', GeographicUnit::where('code', '2001')->value('type'), 'ក្រុង (municipality) is a district-level unit');
        $this->assertEqualsWithDelta(11.1, GeographicUnit::where('code', '200101')->value('latitude'), 0.0001);
    }

    public function test_reimport_updates_names_and_keeps_coordinates_when_cells_are_empty(): void
    {
        $this->seed(ReferenceDataSeeder::class); // legacy Prey Veng file still imports
        $before = GeographicUnit::where('code', '140101')->first();
        $this->assertNotNull($before->latitude);

        $path = $this->csv("type,code,name_en,name_km,latitude,longitude\nឃុំ,140101,Boeng Preah,បឹងព្រះ,,\n");
        app(GazetteerImporter::class)->import($path);

        $after = $before->fresh();
        $this->assertSame('បឹងព្រះ', $after->name_km, 'Khmer name added from NCDD');
        $this->assertSame($before->latitude, $after->latitude, 'empty cells keep coordinates');
        $this->assertSame(1, GeographicUnit::where('code', '140101')->count(), 'no duplicate');
    }

    public function test_admin_uploads_previews_then_applies(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $admin = \App\Models\User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'secret-password-1', 'account_status' => 'active']);
        $admin->roles()->attach(\App\Models\Role::where('name', 'system_admin')->value('id'));
        $district = \App\Models\User::create(['name' => 'DO', 'email' => 'd@test.local', 'password' => 'secret-password-1', 'account_status' => 'active']);
        $district->roles()->attach(\App\Models\Role::where('name', 'district_officer')->value('id'));

        $csv = "\xEF\xBB\xBFtype,code,name_en,name_km,latitude,longitude\n"
            ."province,20,Svay Rieng,ខេត្តស្វាយរៀង,,\n"
            ."ស្រុក,2001,Chantrea,ចន្ទ្រា,,\n"
            ."ឃុំ,140101,Boeng Preah,បឹងព្រះ,,\n"          // existing commune: gains its Khmer name
            ."ភូមិ,99010101,Orphan,,,\n";                    // parent 990101 unknown: skipped
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('svay-rieng.csv', $csv);

        $this->actingAs($district)->post('/admin/geography-import', ['file' => $file])->assertForbidden();

        $this->actingAs($admin)->post('/admin/geography-import', ['file' => $file])->assertRedirect('/admin/geography');
        $pending = session('geo_import');
        $this->assertSame(['new' => 1, 'updated' => 0, 'unchanged' => 0], $pending['preview']['levels']['province']);
        $this->assertSame(1, $pending['preview']['levels']['commune']['updated']);
        $this->assertSame(['parent code not found (add the parent row first)' => 1], $pending['preview']['skipped']);
        $this->assertNull(GeographicUnit::where('code', '20')->first(), 'preview saves nothing');

        $this->actingAs($admin)->get('/admin/geography')->assertOk()->assertSee('Import preview')->assertSee('Apply import');

        $this->actingAs($admin)->post('/admin/geography-import/apply', ['token' => 'wrong'])->assertStatus(410);
        $this->actingAs($admin)->post('/admin/geography-import/apply', ['token' => $pending['token']])->assertRedirect('/admin/geography');

        $this->assertSame('ចន្ទ្រា', GeographicUnit::where('code', '2001')->value('name_km'));
        $this->assertSame('បឹងព្រះ', GeographicUnit::where('code', '140101')->value('name_km'));
        $this->assertNull(GeographicUnit::where('code', '99010101')->first(), 'orphan rows are not imported');
        $this->assertNull(session('geo_import'));
        $this->assertTrue(\App\Models\AuditLog::where('action', 'geography.imported')->exists());

        $this->actingAs($admin)->get('/admin/geography-import/template')->assertOk();
    }

    public function test_cancel_discards_the_upload(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $admin = \App\Models\User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'secret-password-1', 'account_status' => 'active']);
        $admin->roles()->attach(\App\Models\Role::where('name', 'system_admin')->value('id'));
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('x.csv', "type,code,name_en,name_km,latitude,longitude\nprovince,21,Takeo,តាកែវ,,\n");

        $this->actingAs($admin)->post('/admin/geography-import', ['file' => $file]);
        $token = session('geo_import')['token'];
        $this->actingAs($admin)->post('/admin/geography-import/cancel')->assertRedirect();

        $this->assertNull(GeographicUnit::where('code', '21')->first());
        $this->assertFileDoesNotExist(\Storage::disk('local')->path("private/geo-imports/{$token}.csv"));
    }

    public function test_bad_rows_are_skipped_and_counted(): void
    {
        $importer = app(GazetteerImporter::class);
        $importer->import($this->csv("type,code,name_en,name_km,latitude,longitude\nregion,99,Unknown,,,\nvillage,,No Code,,,\n"));

        $this->assertSame(['missing code or unknown type' => 2], $importer->skipped);
        $this->assertSame(0, GeographicUnit::count());
    }
}
