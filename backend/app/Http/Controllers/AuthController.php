<?php

namespace App\Http\Controllers;

//use App\Http\Requests\BidStoreRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\CheckNumberRequest;
use App\Http\Requests\ConfirmNumberRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\ConfirmPassword;
use App\Models\CountryCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Propaganistas\LaravelPhone\PhoneNumber;

//use App\Http\Requests\UserUpdateInfoRequest;
//use App\Http\Resources\UserResource;
//use App\Models\Bid;

//use App\Models\Permission;

// use OsonSMS\SMSGateway\SMSGateway;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register', 'checkNumber', 'confirmNumber', 'createNewToken', 'changePassword']]);
    }

    /**
     * Get a JWT via given credentials.
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $checkCountry = $this->checkAndFormatNumberCountry($request);
        if ($checkCountry !== true) {
            return $checkCountry;
        }

        // if ($request->dashboard && !User::where('phone', $request->phone)->first()->hasPermission('dashboard')) {
        //     return response()->json([
        //         'message'=> 'У вас нет разрешения.'
        //     ], 403);
        // }

        if (!$token = auth()->setTTL(45000)->attempt([
            'phone' => $request->phone,
            'password' => $request->password,
            'is_active' => 1
        ])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $this->createNewToken($token);
    }

    /**
     * Register a User.
     *
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $checkCountry = $this->checkAndFormatNumberCountry($request);
        if ($checkCountry !== true) {
            return $checkCountry;
        }

        $confirm = ConfirmPassword::where('phone', $request->phone)->where('confirmed', 1)->first();

        if (!$confirm) {
            return response()->json([
                'message' => 'Введены некорректные данные.',
            ], 403);
        }

        User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'password' => $request->password,
            'is_active' => true,
            'country_code_id' => $request->country_code
        ]);

        $confirm->delete();

        if (!$token = auth()->setTTL(45000)->attempt([
            'phone' => $request->phone,
            'password' => $request->password,
            'is_active' => 1
        ])) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        return $this->createNewToken($token);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        auth()->logout();

        return response()->json(['message' => 'User successfully signed out']);
    }

    /**
     * Refresh a token.
     *
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        return $this->createNewToken(auth()->refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param string $token
     *
     * @return JsonResponse
     */
    protected function createNewToken(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => auth()->user()
        ]);
    }

    public function confirmNumber(ConfirmNumberRequest $request): JsonResponse
    {
        $checkCountry = $this->checkAndFormatNumberCountry($request);
        if ($checkCountry !== true) {
            return $checkCountry;
        }

        $confirm = ConfirmPassword::where('confirm_code', $request->confirm_code)
            ->where('phone', $request->phone)
            ->latest();

        if (!$confirm) {
            return response()->json([
                'message' => 'Введен неправильный код.',
            ], 403);
        }

        $confirm->update([
            'confirmed' => 1,
        ]);

        return response()->json([
            'confirmed' => true,
        ]);
    }

    public function checkNumber(CheckNumberRequest $request): JsonResponse
    {
        $checkCountry = $this->checkAndFormatNumberCountry($request);

         if ($checkCountry !== true) {
             return $checkCountry;
         }

        $userCount = User::where('phone', $request->phone)->count();

        if (isset($request->reset) && !($userCount > 0)) {
            return response()->json([
                'message' => 'Пользователь с таким телефоном не зарегистрирован.',
            ], 403);
        }

        if ((!isset($request->confirm_code) || !isset($request->reset)) && $userCount > 0) {
            return response()->json([
                'message' => 'Пользователь с таким телефоном уже зарегистрирован.',
            ], 403);

        }

        $sixteenMinutesBefore = Carbon::parse()->now()->subMinutes(60)->format('Y-m-d H:i:s');

        $confirmPasswordCount = ConfirmPassword::where('phone', $request->phone)
            ->where('qty', '>=', 5)
            ->where('updated_at', '>', $sixteenMinutesBefore)
            ->count();

        if ($confirmPasswordCount > 0) {
            return response()->json([
                'message' => 'Слишком много попыток, повторите позже!',
            ], 402);
        }

        $confirm_code = random_int(1000, 9999);

        ConfirmPassword::updateOrCreate(
            [
                'phone' => $request->phone,
            ],
            [
                'phone' => $request->phone,
                'confirm_code' => $confirm_code,
                'qty' => DB::raw('qty + 1'),
                'country_code_id' => $request->country_code
            ]
        );

        // $txn_id = uniqid();
        // $result = SMSGateway::Send($request->phone, 'Ваш код: ' . $confirm_code, $txn_id);

        // $smsResponse = sendSMS($request->phone, $confirm_code);

        // if (isset($smsResponse['transactions']['response'][0]['code'])) {
        //     return response()->json([
        //         'status'=> true
        //     ]);
        // }

        return response()->json([
            'confirm_code' => $confirm_code
            // 'status'=> false
        ]);
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        $checkCountry = $this->checkAndFormatNumberCountry($request);

        if ($checkCountry !== true) {
            return $checkCountry;
        }

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Пользователь с таким телефоном не зарегистрирован.',
            ], 403);
        }

        $user->update([
            'password' => $request->password
        ]);

        return response()->json([
            'message' => 'Ваш пароль успешно обновлен'
        ]);
    }

    private function checkAndFormatNumberCountry(&$request)
    {
        $object = new PhoneNumber($request->phone);
        $iso = CountryCode::select('iso')
            ->where('is_active', '=', true)
            ->where('id', '=', $request->country_code)
            ->get()
            ->toArray();

        try {
            $object = $object->ofCountry($iso[0]['iso']);
            $check = $object->isOfCountry($iso[0]['iso']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Введённый номер не совпадает с выбранной страной'
            ], 400);
        }

        if (!$check) {
            return response()->json([
                'phone' => [
                    'Введённый номер  не валиден'
                ]
            ], 422);
        }

        $request->phone = PhoneNumber::make($request->phone, $iso[0]['iso'])->formatE164();

        return $check;
    }
}
