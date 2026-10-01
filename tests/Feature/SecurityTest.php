<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security properties that are easy to break with a careless change.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * If is_admin were in \$fillable, adding it to the registration form
     * would be enough to hand yourself an administrator account.
     */
    public function test_the_admin_flag_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Sprytny',
            'email' => 'sprytny@example.com',
            'password' => bcrypt('haslo1234'),
            'is_admin' => true,
        ]);

        $this->assertFalse((bool) $user->fresh()->is_admin,
            'is_admin nie moze byc masowo przypisywalne');
    }

    public function test_the_package_cannot_be_raised_by_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Sprytny',
            'email' => 'sprytny2@example.com',
            'password' => bcrypt('haslo1234'),
            'plan' => 'pro',
        ]);

        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_registration_ignores_an_admin_flag(): void
    {
        $this->post('/rejestracja', [
            'first_name' => 'Sprytny',
            'email' => 'sprytny3@example.com',
            'password' => 'bardzoTajneHaslo1',
            'password_confirmation' => 'bardzoTajneHaslo1',
            'is_admin' => 1,
        ]);

        $this->assertFalse((bool) User::where('email', 'sprytny3@example.com')->first()->is_admin);
    }

    public function test_the_password_never_leaks_in_a_response(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/host')
            ->assertInertia(fn ($p) => $p->missing('auth.user.password'));
    }
}
