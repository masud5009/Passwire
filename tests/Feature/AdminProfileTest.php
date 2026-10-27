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
        $response->assertSee('Security & Password', false);
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

    public function test_admin_can_update_password_with_valid_current_password(): void
    {
        $admin = User::factory()->create([
            'name' => 'Password Changer',
            'email' => 'changer@passwire.local',
            'password' => Hash::make('old-password-123'),
        ]);

        $response = $this->actingAs($admin)->put('/dashboard/profile', [
            'name' => 'Password Changer',
            'email' => 'changer@passwire.local',
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

        $response = $this->actingAs($admin)->put('/dashboard/profile', [
            'name' => 'Wrong Password Tester',
            'email' => 'wrong@passwire.local',
            'current_password' => 'invalid-password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $response->assertSessionHasErrors('current_password');

        $admin->refresh();
        $this->assertTrue(Hash::check('correct-password', $admin->password));
    }
}
