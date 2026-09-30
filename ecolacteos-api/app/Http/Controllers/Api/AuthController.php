<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/login  { dni, password, dispositivo }
     * Devuelve un token Sanctum de larga vida (la app de campo puede pasar
     * días sin conexión), los datos del usuario y su rol.
     */
    public function login(Request $r)
    {
        $data = $r->validate([
            'dni'         => ['required', 'digits:8'],
            'password'    => ['required', 'string'],
            'dispositivo' => ['nullable', 'string', 'max:100'],
        ]);

        $usuario = User::where('dni', $data['dni'])->first();

        if (! $usuario || ! $usuario->activo || ! Hash::check($data['password'], $usuario->password)) {
            return response()->json(['message' => 'DNI o contraseña incorrectos.'], 401);
        }

        $token = $usuario->createToken($data['dispositivo'] ?? 'app-campo')->plainTextToken;

        return response()->json([
            'token'   => $token,
            'usuario' => [
                'id'     => $usuario->id,
                'nombre' => $usuario->name,
                'dni'    => $usuario->dni,
            ],
            'rol' => $usuario->rol,
        ]);
    }

    /** POST /api/v1/logout — revoca solo el token del dispositivo actual. */
    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /** GET /api/v1/yo — para que la app verifique que su token sigue vigente. */
    public function yo(Request $r)
    {
        $u = $r->user();

        return response()->json([
            'id' => $u->id, 'nombre' => $u->name, 'dni' => $u->dni, 'rol' => $u->rol,
        ]);
    }
}
