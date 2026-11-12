<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private School $secondary;

    private School $primary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secondary = School::create(['name' => 'Secondary', 'slug' => 'secondary', 'subdomain' => 'secondary']);
        $this->primary = School::create(['name' => 'Primary', 'slug' => 'primary', 'subdomain' => 'primary']);
    }

    private function login(string $host, User $user, string $password = 'password')
    {
        return $this->post("http://{$host}/login", ['email' => $user->email, 'password' => $password]);
    }

    public function test_login_screens_render_for_school_and_platform(): void
    {
        $this->get('http://secondary.localhost/login')->assertOk()->assertSee('Sign in to Secondary')->assertDontSee('img/logo.png', false);
        config([
            'app.powered_by_email' => 'hello@wengineering.ng',
            'app.powered_by_phone' => '+234 800 000 0000',
            'app.powered_by_website' => 'https://www.wengineering.ng',
        ]);
        $this->get('http://localhost/login')
            ->assertOk()
            ->assertSee('Platform sign in')
            ->assertSee('img/logo.png', false)
            ->assertSee('Powered by')
            ->assertSee('hello@wengineering.ng')
            ->assertSee('www.wengineering.ng')
            ->assertSee('tel:+2348000000000', false);
    }

    public function test_school_user_logs_in_on_own_school_and_lands_on_dashboard(): void
    {
        $user = User::factory()->create(['school_id' => $this->secondary->id]);

        $this->login('secondary.localhost', $user)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_school_user_cannot_log_in_on_another_school_or_platform(): void
    {
        $user = User::factory()->create(['school_id' => $this->secondary->id]);

        $this->login('primary.localhost', $user);
        $this->assertGuest();

        $this->login('localhost', $user);
        $this->assertGuest();
    }

    public function test_same_email_in_two_schools_resolves_per_host(): void
    {
        $a = User::factory()->create(['school_id' => $this->secondary->id, 'email' => 'x@example.com']);
        $b = User::factory()->create(['school_id' => $this->primary->id, 'email' => 'x@example.com']);

        $this->login('primary.localhost', $b);
        $this->assertAuthenticatedAs($b);
    }

    public function test_platform_owner_logs_in_on_platform_host_only(): void
    {
        $owner = User::factory()->platformOwner()->create();

        $this->login('secondary.localhost', $owner);
        $this->assertGuest();

        $this->login('localhost', $owner)->assertRedirect(route('platform.schools'));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_must_change_password_redirects_and_blocks_shell(): void
    {
        $user = User::factory()->mustChangePassword()->create(['school_id' => $this->secondary->id]);

        $this->login('secondary.localhost', $user)->assertRedirect(route('password.change'));
        $this->get('http://secondary.localhost/dashboard')->assertRedirect(route('password.change'));

        $this->put('http://secondary.localhost/change-password', [
            'current_password' => 'password',
            'password' => 'NewPassw0rd!xyz',
            'password_confirmation' => 'NewPassw0rd!xyz',
        ])->assertRedirect(route('dashboard'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->get('http://secondary.localhost/dashboard')->assertOk();
    }

    public function test_invalid_password_fails(): void
    {
        $user = User::factory()->create(['school_id' => $this->secondary->id]);

        $this->login('secondary.localhost', $user, 'wrong');
        $this->assertGuest();
    }

    public function test_registration_is_disabled(): void
    {
        $this->get('http://secondary.localhost/register')->assertNotFound();
    }

    public function test_platform_owner_can_enter_school_and_non_owner_cannot_see_platform(): void
    {
        $owner = User::factory()->platformOwner()->create();
        $admin = User::factory()->create(['school_id' => $this->secondary->id, 'role' => Role::ADMIN]);

        $this->actingAs($admin)->get('http://secondary.localhost/platform/schools')->assertForbidden();

        $this->actingAs($owner)->get('http://localhost/platform/schools')->assertOk()->assertSee('Enter school');
        $this->actingAs($owner)->post("http://localhost/platform/schools/{$this->secondary->id}/enter")
            ->assertRedirect(route('dashboard'));
        $this->get('http://localhost/dashboard')->assertOk()->assertSee('Secondary');
    }

    public function test_settings_are_admin_only_and_update_school(): void
    {
        $teacher = User::factory()->create(['school_id' => $this->secondary->id, 'role' => Role::TEACHER]);
        $admin = User::factory()->create(['school_id' => $this->secondary->id, 'role' => Role::ADMIN]);

        $this->actingAs($teacher)->get('http://secondary.localhost/settings')->assertForbidden();

        $this->actingAs($admin)->put('http://secondary.localhost/settings', [
            'name' => 'Renamed',
            'primary_color' => '#112233',
        ])->assertRedirect(route('settings'));

        $this->assertSame('Renamed', $this->secondary->fresh()->name);
        $this->assertSame('#112233', $this->secondary->fresh()->primary_color);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create(['school_id' => $this->secondary->id]);

        $this->actingAs($user)->post('http://secondary.localhost/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
