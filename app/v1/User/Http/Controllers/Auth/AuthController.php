<?php

namespace App\v1\User\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\v1\User\Http\Requests\LoginRequests;
use App\v1\User\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function loginUser(LoginRequests $request): JsonResponse
    {
     
        request()->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);
        $response = $request->authenticate();
       // $request->session()->regenerate();
       $user = User::where('email', request()->email)->firstOrFail();

       $token = $user->createToken('auth_token')->plainTextToken;


        return response()->json([
            'access_token' => $token,
            'message' => 'Login successfully'
        ]);
    
    }

}
