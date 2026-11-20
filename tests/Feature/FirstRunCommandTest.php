<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** cohers:first-run must rebuild an empty database and never touch one in use. */
class FirstRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_gets_reference_data_and_demo_accounts(): void
    {
        $this->assertSame(0, Role::count());

        $this->artisan('cohers:first-run', ['--demo' => true])->assertSuccessful();

        $this->assertSame(4, Role::count());
        $this->assertTrue(User::where('email', 'admin@cohers.test')->exists());
        $this->assertGreaterThan(100, Report::count());
        // Demo passwords were replaced with random ones.
        $this->assertFalse(\Hash::check(\Database\Seeders\DemoSeeder::PASSWORD, User::where('email', 'admin@cohers.test')->value('password')));
    }

    public function test_without_demo_an_administrator_is_still_created(): void
    {
        $this->artisan('cohers:first-run')->assertSuccessful();

        $this->assertSame(0, Report::count());
        $this->assertTrue(User::where('email', 'admin@cohers.local')->first()->isAdmin());
    }

    public function test_database_in_use_is_left_alone(): void
    {
        $this->artisan('cohers:first-run', ['--admin-email' => 'owner@example.org'])->assertSuccessful();
        $hash = User::where('email', 'owner@example.org')->value('password');

        $this->artisan('cohers:first-run', ['--demo' => true, '--admin-email' => 'owner@example.org'])
            ->expectsOutputToContain('already set up')->assertSuccessful();

        $this->assertSame($hash, User::where('email', 'owner@example.org')->value('password'), 'password not reset');
        $this->assertSame(0, Report::count(), 'no demo data added to a live database');
    }
}
