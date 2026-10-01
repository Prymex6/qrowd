<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function show()
    {
        return Inertia::render('Host/SignIn');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Nie znamy takiego adresu albo hasło się nie zgadza.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('host.dashboard'));
    }

    public function register(Request $request)
    {
        // Every new account reaches for our YouTube quota, which cannot be
        // topped up. Until payments filter the traffic, the host can close
        // registration and create accounts by hand.
        if (config('qrowd.registration') !== 'open') {
            throw ValidationException::withMessages([
                'email' => 'Rejestracja jest chwilowo zamknięta. Napisz do nas, otworzymy Ci konto.',
            ]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'email.unique' => 'Na ten adres jest już założone konto.',
        ]);

        $user = User::create([
            'name' => $data['first_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        event(new Registered($user));
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('host.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
