<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Support\BotText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCohersFixtures;
use Tests\TestCase;

/** Admin → Language & Settings: saving the form exactly as the browser submits it. */
class SettingsPageTest extends TestCase
{
    use BuildsCohersFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCohers();
    }

    private function formPayload(array $overrides = []): array
    {
        $messages = [];
        foreach (BotText::EDITABLE as $key) {
            foreach (['km', 'en'] as $loc) {
                // Browsers submit textarea line breaks as CRLF.
                $messages[$key][$loc] = str_replace("\n", "\r\n", BotText::t($key, $loc));
            }
        }

        return array_replace_recursive([
            'targets' => ['LOW' => 1440, 'MEDIUM' => 240, 'HIGH' => 30, 'CRITICAL' => 15],
            'verification_target_hours' => 48,
            'require_mfa' => '1',
            'messages' => $messages,
            'reason' => '',
        ], $overrides);
    }

    public function test_saving_unchanged_form_works(): void
    {
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk();
        $this->actingAs($this->admin)->put('/admin/settings', $this->formPayload())
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_changing_a_target_and_a_message_is_saved(): void
    {
        $this->actingAs($this->admin)->put('/admin/settings', $this->formPayload([
            'targets' => ['HIGH' => 45],
            'messages' => ['help' => ['en' => 'Custom help text']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(45, \App\Enums\Priority::HIGH->ackMinutes());
        $this->assertSame('Custom help text', BotText::t('help', 'en'));
        $this->assertSame(__('bot.help', [], 'km'), BotText::t('help', 'km'), 'unchanged Khmer text keeps the default');
        $this->assertFalse(SystemSetting::where('key', 'msg.km.help')->where('value', '!=', '')->exists());
    }
}
