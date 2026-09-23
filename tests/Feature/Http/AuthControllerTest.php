<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_can_open_login_and_registration(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth')->where('register', false));
        $this->get('/register')->assertInertia(fn (Assert $page) => $page->component('Auth')->where('register', true));
    }

    public function test_registration_normalizes_email_hashes_password_and_authenticates(): void
    {
        $this->post('/register', ['name' => 'Alex', 'email' => 'ALEX@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password', 'id' => 999])
            ->assertRedirect('/appointments');
        $user = User::sole();
        $this->assertSame('alex@example.com', $user->email);
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertNotSame(999, $user->id);
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('invalidRegistrations')]
    public function test_registration_rejects_invalid_values(array $change, string $field): void
    {
        $this->post('/register', array_replace(['name' => 'Alex', 'email' => 'alex@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'], $change))
            ->assertSessionHasErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public static function invalidRegistrations(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'], 'long name' => [['name' => str_repeat('a', 101)], 'name'],
            'bad email' => [['email' => 'not-an-email'], 'email'], 'array email' => [['email' => []], 'email'],
            'short password' => [['password' => 'short'], 'password'], 'mismatch' => [['password_confirmation' => 'wrong'], 'password'],
            'bcrypt byte limit' => [['password' => str_repeat('é', 40), 'password_confirmation' => str_repeat('é', 40)], 'password'],
        ];
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'alex@example.com']);
        $this->post('/register', ['name' => 'Alex', 'email' => 'ALEX@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])
            ->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_and_logout_rotate_the_session(): void
    {
        $user = User::factory()->create(['email' => 'alex@example.com']);
        $this->post('/login', ['email' => 'ALEX@example.com', 'password' => 'password', 'remember' => true])->assertRedirect('/appointments');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/appointments')->assertRedirect('/login');
    }

    public function test_login_does_not_reveal_whether_an_account_exists(): void
    {
        User::factory()->create(['email' => 'alex@example.com']);
        foreach (['alex@example.com', 'missing@example.com'] as $email) {
            $this->post('/login', ['email' => $email, 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => 'The email or password is incorrect.']);
        }
        $this->assertGuest();
    }

    public function test_repeated_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'limit@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'limit@example.com', 'password' => 'wrong'])->assertTooManyRequests();
    }
}
