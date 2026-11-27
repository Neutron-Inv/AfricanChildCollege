<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\School;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\ExamsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://secondary.localhost';

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        $this->school = School::create([
            'name' => 'Secondary',
            'slug' => 'secondary',
            'subdomain' => 'secondary',
            'primary_color' => '#a01010',
        ]);
    }

    public function test_admin_can_upload_and_clear_logo_and_principal_signature(): void
    {
        $admin = User::factory()->create(['school_id' => $this->school->id, 'role' => Role::ADMIN]);

        $this->actingAs($admin)
            ->get(self::HOST.'/settings')
            ->assertOk()
            ->assertSee('School logo')
            ->assertSee('First signature')
            ->assertSee("Principal's signature");

        $logo = UploadedFile::fake()->image('logo.png', 120, 120);
        $signature = UploadedFile::fake()->image('sign.png', 200, 80);

        $this->actingAs($admin)->put(self::HOST.'/settings', [
            'name' => 'Secondary',
            'primary_color' => '#a01010',
            'logo' => $logo,
            'principal_signature' => $signature,
            'principal_signature_label' => 'Head of school',
        ])->assertRedirect(route('settings'));

        $this->assertSame('Head of school', $this->school->fresh()->principal_signature_label);

        $school = $this->school->fresh();
        $this->assertNotNull($school->logo_file_id);
        $this->assertNotNull($school->principal_signature_file_id);
        $this->assertSame(2, StoredFile::query()->count());
        Storage::disk('local')->assertExists($school->logo->storage_key);
        Storage::disk('local')->assertExists($school->principalSignature->storage_key);

        $this->actingAs($admin)->get(self::HOST.'/settings')
            ->assertOk()
            ->assertSee('Remove logo')
            ->assertSee('Remove signature');

        $this->actingAs($admin)->get(self::HOST.'/dashboard')
            ->assertOk()
            ->assertSee(route('branding.logo', ['v' => $school->logo_file_id]), false)
            ->assertSee($school->name.' logo', false);

        $this->actingAs($admin)->get(self::HOST.'/branding/logo')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->actingAs($admin)->put(self::HOST.'/settings', [
            'name' => 'Secondary',
            'primary_color' => '#a01010',
            'clear_logo' => '1',
            'clear_signature' => '1',
        ])->assertRedirect(route('settings'));

        $school = $this->school->fresh();
        $this->assertNull($school->logo_file_id);
        $this->assertNull($school->principal_signature_file_id);
        $this->assertSame(0, StoredFile::query()->count());
    }

    public function test_admin_can_save_a_second_signature_with_a_label(): void
    {
        $admin = User::factory()->create(['school_id' => $this->school->id, 'role' => Role::ADMIN]);

        $this->actingAs($admin)->get(self::HOST.'/settings')
            ->assertOk()
            ->assertSee('Second signature')
            ->assertSee('Both the label and the image are required');

        $this->actingAs($admin)->put(self::HOST.'/settings', [
            'name' => 'Secondary',
            'primary_color' => '#a01010',
            'second_signature' => UploadedFile::fake()->image('director.png', 200, 80),
            'second_signature_label' => "Director's signature",
        ])->assertRedirect(route('settings'));

        $school = $this->school->fresh();
        $this->assertNotNull($school->second_signature_file_id);
        $this->assertSame("Director's signature", $school->second_signature_label);
        Storage::disk('local')->assertExists($school->secondSignature->storage_key);

        $this->actingAs($admin)->get(self::HOST.'/settings')
            ->assertOk()
            ->assertSee('Remove second signature')
            ->assertSee("Director's signature");

        $this->actingAs($admin)->put(self::HOST.'/settings', [
            'name' => 'Secondary',
            'primary_color' => '#a01010',
            'second_signature_label' => "Director's signature",
            'clear_second_signature' => '1',
        ])->assertRedirect(route('settings'));

        $school = $this->school->fresh();
        $this->assertNull($school->second_signature_file_id);
        $this->assertSame("Director's signature", $school->second_signature_label);
        $this->assertSame(0, StoredFile::query()->count());
    }

    public function test_broadsheet_paper_widens_for_many_subjects(): void
    {
        $service = app(ExamsService::class);

        $a4 = $service->broadsheetPaper(5);
        $wide = $service->broadsheetPaper(20);

        // Custom boxes are [x0, y0, x1, y1] in points; width is index 2.
        $this->assertGreaterThan($a4[2], $wide[2]);
        $this->assertEqualsWithDelta(210 * 72 / 25.4, $a4[3], 0.5);
        $this->assertGreaterThanOrEqual(297 * 72 / 25.4 - 0.5, $a4[2]);
    }
}
