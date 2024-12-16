<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string', 
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $activationCode = mt_rand(100000, 999999);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'is_active' => false,  
            'activation_code' => $activationCode, 
        ]);

        $this->sendActivationCode($user);

        return response()->json([
            'message' => 'Usuario creado exitosamente. Codigo de acceso enviado a su celular.',
            'user' => $user,
        ]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            $user = Auth::user();
            if ($user->is_active) {
                $token = $user->createToken('API Token')->plainTextToken;
                return response()->json([
                    'message' => 'Login successful',
                    'token' => $token,
                ]);
            } else {
                return response()->json(['error' => 'Cuenta no activada.'], 403);
            }
        }

        return response()->json(['error' => 'Credenciales invalidas'], 401);
    }

    public function activate(Request $request)
    {
        $request->validate([
            'activation_code' => 'required|string|size:6',
        ]);

        $user = User::where('activation_code', $request->activation_code)->first();

        if (!$user) {
            return response()->json(['error' => 'Codigo de acceso invalido.'], 400);
        }

        $user->is_active = true;
        $user->activation_code = null; 
        $user->save();

        return response()->json(['message' => 'Cuenta activada exitosamente.']);
    }



    protected function sendActivationCode(User $user)
    {
        $sid = env('TWILIO_SID'); 
        $token = env('TWILIO_AUTH_TOKEN'); 
        $from =env('TWILIO_PHONE_NUMBER');
    
        $twilio = new Client($sid, $token);

        $message = $twilio->messages->create(
            "whatsapp:+5218711015826",
            array(
                'from' =>'whatsapp:'.$from,
                'body' =>'Tu codigo de activacion es: '.$user->activation_code,
            )
        );

            
    }
    
    
}
