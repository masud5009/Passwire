<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_cannot_update_profile(): void
    {
        $response = $this->put('/dashboard/profile', [
            'name' => 'Hacker',
            'email' => 'hacker@test.local',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_admin_can_view_edit_profile_page(): void
    {
        $admin = User::factory()->create([
            'name' => 'Page Viewer Admin',
            'email' => 'viewer_' . uniqid() . '@passwire.local',
        ]);

        $response = $this->actingAs($admin)->get('/dashboard/profile');

        $response->assertStatus(200);
        $response->assertSee('Edit Profile', false);
        $response->assertSee('Account Information', false);
        $response->assertSee('Change Password', false);
        $response->assertSee('Page Viewer Admin', false);
    }

    public function test_admin_can_update_name_and_email(): void
    {
        $admin = User::factory()->create([
            'name' => 'Original Admin',
            'email' => 'original@passwire.local',
            'password' => Hash::make('secret1234'),
        ]);

        $response = $this->actingAs($admin)->put('/dashboard/profile', [
            'name' => 'Updated Admin',
            'email' => 'updated@passwire.local',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $admin->refresh();
        $this->assertEquals('Updated Admin', $admin->name);
        $this->assertEquals('updated@passwire.local', $admin->email);
    }

    public function test_admin_cannot_use_existing_email(): void
    {
        $otherUser = User::factory()->create([
            'email' => 'existing_other_' . uniqid() . '@test.local',
        ]);

        $admin = User::factory()->create([
            'email' => 'admin_test_' . uniqid() . '@test.local',
        ]);

        $response = $this->actingAs($admin)->put('/dashboard/profile', [
            'name' => 'Admin User',
            'email' => $otherUser->email,
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_admin_can_view_change_password_page(): void
    {
        $admin = User::factory()->create([
            'email' => 'viewer_' . uniqid() . '@passwire.local',
        ]);

        $response = $this->actingAs($admin)->get('/dashboard/password');

        $response->assertStatus(200);
        $response->assertSee('Change Password', false);
        $response->assertSee('Current Password', false);
        $response->assertDontSee('Sign-in Security Preferences', false);
        $response->assertDontSee('Hide default password', false);
    }

    public function test_admin_can_update_password_with_valid_current_password(): void
    {
        $admin = User::factory()->create([
            'name' => 'Password Changer',
            'email' => 'changer@passwire.local',
            'password' => Hash::make('old-password-123'),
        ]);

        $response = $this->actingAs($admin)->put('/dashboard/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $admin->refresh();
        $this->assertTrue(Hash::check('new-secret-password', $admin->password));
    }

    public function test_admin_cannot_update_password_with_wrong_current_password(): void
    {
        $admin = User::factory()->create([
            'name' => 'Wrong Password Tester',
            'email' => 'wrong@passwire.local',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->actingAs($admin)->put('/dashboard/password', [
            'current_password' => 'invalid-password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $response->assertSessionHasErrors('current_password');

        $admin->refresh();
        $this->assertTrue(Hash::check('correct-password', $admin->password));
    }

    public function test_login_page_does_not_contain_default_credentials_card_or_autofill(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertDontSee('Default Credentials', false);
        $response->assertDontSee('fillDemoBtn', false);
        $response->assertDontSee('Pre-configured during setup', false);
        $response->assertDontSee('placeholder="admin@passwire.local"', false);
    }

    public function test_login_page_renders_create_account_option(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Create Account', false);
        $response->assertSee('Admin Name', false);
        $response->assertSee('Confirm Password', false);
    }

    public function test_user_can_view_register_page(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('Create Account', false);
        $response->assertSee('Admin Name', false);
        $response->assertSee('Confirm Password', false);
    }

    public function test_user_can_register_and_login_directly_without_email_verification(): void
    {
        $email = 'newuser_' . uniqid() . '@example.com';

        $response = $this->post('/register', [
            'name' => 'New Passwire User',
            'email' => $email,
            'password' => 'secure-pass-1234',
            'password_confirmation' => 'secure-pass-1234',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertEquals('New Passwire User', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secure-pass-1234', $user->password));
    }

    public function test_registration_requires_valid_and_unique_data(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing_' . uniqid() . '@example.com',
        ]);

        // Duplicate email
        $response = $this->post('/register', [
            'name' => 'Duplicate Email User',
            'email' => $existingUser->email,
            'password' => 'secret12345',
            'password_confirmation' => 'secret12345',
        ]);
        $response->assertSessionHasErrors('email');

        // Short password (< 8 chars)
        $response = $this->post('/register', [
            'name' => 'Short Pass User',
            'email' => 'shortpass_' . uniqid() . '@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
        $response->assertSessionHasErrors('password');

        // Password mismatch
        $response = $this->post('/register', [
            'name' => 'Mismatch Pass User',
            'email' => 'mismatch_' . uniqid() . '@example.com',
            'password' => 'secret12345',
            'password_confirmation' => 'different-secret',
        ]);
        $response->assertSessionHasErrors('password');
    }
}
