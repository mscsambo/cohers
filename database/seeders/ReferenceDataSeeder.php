<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\ReportCategory;
use App\Models\Role;
use App\Models\RoutingRule;
use App\Support\GazetteerImporter;
use Illuminate\Database\Seeder;

/** Roles, permissions (section 03 matrix), Prey Veng geography, categories and safety rules. */
class ReferenceDataSeeder extends Seeder
{
    private const PERMISSIONS = [
        'report.create' => ['Create a report', ['reporter', 'focal_point', 'district_officer', 'system_admin']],
        'report.view_own' => ['View own reports', ['reporter', 'focal_point', 'district_officer', 'system_admin']],
        'report.view_assigned' => ['View assigned / scoped reports', ['focal_point', 'district_officer', 'system_admin']],
        'report.verify' => ['Acknowledge / verify in field', ['focal_point']],
        'report.reassign' => ['Reassign focal-point case', ['district_officer', 'system_admin']],
        'escalation.review' => ['Review / resolve escalation', ['district_officer']],
        'escalation.monitor' => ['Monitor escalations', ['system_admin']],
        'report.close' => ['Approve case closure', ['district_officer']],
        'users.manage' => ['Manage users, roles, area scopes', ['system_admin']],
        'settings.manage' => ['Manage routing / AI / system settings', ['system_admin']],
        'analytics.view' => ['Analytics / exports', ['district_officer', 'system_admin']],
    ];

    public function run(): void
    {
        foreach (RoleName::cases() as $r) {
            Role::updateOrCreate(['name' => $r->value], ['label' => $r->label()]);
        }
        foreach (self::PERMISSIONS as $name => [$label, $roles]) {
            $p = Permission::updateOrCreate(['name' => $name], ['label' => $label]);
            $p->roles()->sync(Role::whereIn('name', $roles)->pluck('id'));
        }

        app(GazetteerImporter::class)->import(database_path('data/prey_veng_gazetteer.csv'));

        $categories = [
            ['HUMAN_HEALTH', 'Human health event', 'ព្រឹត្តិការណ៍សុខភាពមនុស្ស', 'HUMAN', 'MEDIUM', 'Unusual illness clusters, deaths or symptoms in people.', 1],
            ['ANIMAL_HEALTH', 'Animal health event', 'ព្រឹត្តិការណ៍សុខភាពសត្វ', 'ANIMAL', 'MEDIUM', 'Sick or dead livestock, poultry or wildlife.', 2],
            ['ENVIRONMENT', 'Environmental event', 'ព្រឹត្តិការណ៍បរិស្ថាន', 'ENVIRONMENT', 'LOW', 'Water contamination, chemical spills, fish kills, vector breeding sites.', 3],
        ];
        foreach ($categories as [$code, $en, $km, $sector, $prio, $desc, $sort]) {
            ReportCategory::updateOrCreate(['code' => $code], [
                'name_en' => $en, 'name_km' => $km, 'sector' => $sector, 'default_priority' => $prio,
                'description' => $desc, 'sort' => $sort, 'is_active' => true,
            ]);
        }

        $rules = [
            ['Human death or severe illness', 'keyword_priority', 'person died|people died|child died|baby died|unconscious|bleeding|មនុស្សស្លាប់|កុមារស្លាប់|សន្លប់|ហូរឈាម', 'CRITICAL', true],
            ['Mass or sudden animal deaths', 'keyword_priority', 'many dead|died suddenly|sudden death|dozens|hundreds|ងាប់ច្រើន|ងាប់ភ្លាមៗ', 'HIGH', false],
            ['Chemical or poisoning hazard', 'keyword_priority', 'chemical|pesticide|poison|គីមី|ពុល|ថ្នាំសម្លាប់សត្វល្អិត', 'HIGH', false],
        ];
        foreach ($rules as [$name, $type, $pattern, $prio, $esc]) {
            RoutingRule::updateOrCreate(['name' => $name], [
                'rule_type' => $type, 'pattern' => $pattern, 'priority' => $prio, 'escalate_district' => $esc, 'is_active' => true,
            ]);
        }
    }
}
