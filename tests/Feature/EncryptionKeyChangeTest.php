<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\MfaController;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

/** A restored database next to a new .env (different APP_KEY) must not end in a 500. */
class EncryptionKeyChangeTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCohers();
    }

    /** Encrypts with another key, as if the value came from a database made under another .env. */
    private function encryptWithOtherKey(string $plain): string
    {
        return (new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString($plain);
    }

    public function test_mfa_with_unreadable_secret_explains_instead_of_500(): void
    {
        DB::table('users')->where('id', $this->admin->id)->update(['mfa_enabled' => true, 'mfa_secret' => $this->encryptWithOtherKey('JBSWY3DPEHPK3PXP')]);

        $this->actingAs($this->admin->fresh())->post('/mfa', ['code' => '123456'])
            ->assertSessionHasErrors(['code' => MfaController::KEY_CHANGED]);
    }

    public function test_startup_warns_and_env_switch_resets_mfa(): void
    {
        DB::table('users')->where('id', $this->admin->id)->update(['mfa_enabled' => true, 'mfa_secret' => $this->encryptWithOtherKey('JBSWY3DPEHPK3PXP')]);
        SystemSetting::create(['key' => 'telegram.bot_token', 'value' => $this->encryptWithOtherKey('123:abc'), 'type' => 'secret', 'group' => 'telegram']);

        $this->artisan('cohers:first-run')
            ->expectsOutputToContain('APP_KEY in .env is not the key this database was encrypted with.')
            ->expectsOutputToContain('telegram.bot_token')
            ->assertSuccessful();

        putenv('COHERS_RESET_MFA=admin@test.local');
        try {
            $this->artisan('cohers:first-run')->expectsOutputToContain('Two-factor reset for admin@test.local')->assertSuccessful();
        } finally {
            putenv('COHERS_RESET_MFA');
        }
        $admin = $this->admin->fresh();
        $this->assertFalse($admin->mfa_enabled);
        $this->assertNull(DB::table('users')->where('id', $admin->id)->value('mfa_secret'));
    }
}
