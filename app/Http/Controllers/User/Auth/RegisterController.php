<?php

namespace App\Http\Controllers\User\Auth;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Lib\Intended;
use App\Models\AdminNotification;
use App\Models\User;
use App\Models\UserLogin;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use App\Models\Transaction;

class RegisterController extends Controller {

    use RegistersUsers;

    public function __construct() {
        parent::__construct();
    }

    public function showRegistrationForm() {
        $pageTitle = "Register";
        Intended::identifyRoute();
        return view('Template::user.auth.register', compact('pageTitle'));
    }

    protected function validator(array $data) {
        $passwordValidation = Password::min(6);

        if (gs('secure_password')) {
            $passwordValidation = $passwordValidation->mixedCase()->numbers()->symbols()->uncompromised();
        }

        $agree = 'nullable';
        if (gs('agree')) {
            $agree = 'required';
        }

        return Validator::make($data, [
            'username' => 'required|unique:users|min:6',
            'mobile'   => ['required', 'regex:/^([0-9]*)$/'],
            'email'    => 'required|string|email|unique:users',
            'password' => ['required', 'confirmed', $passwordValidation],
            'captcha'  => 'sometimes|required',
            'agree'    => $agree,
        ]);
    }

    public function register(Request $request) {
        if (!gs('registration')) {
            $notify[] = ['error', 'Registration not allowed'];
            return back()->withNotify($notify);
        }
        $this->validator($request->all())->validate();

        $request->session()->regenerateToken();

        if (!verifyCaptcha()) {
            $notify[] = ['error', 'Invalid captcha provided'];
            return back()->withNotify($notify);
        }
        event(new Registered($user = $this->create(array_merge($request->all(), ['profile_complete' => Status::YES]))));
        $this->guard()->login($user);

        if (gs('rb')) {
            $user->balance += gs('register_bonus');
            $user->save();
            $transaction               = new Transaction();
            $transaction->user_id      = $user->id;
            $transaction->amount       = gs('register_bonus');
            $transaction->charge       = 0;
            $transaction->trx_type     = '+';
            $transaction->details      = 'You have got register bonus';
            $transaction->remark       = 'register_bonus';
            $transaction->trx          = getTrx();
            $transaction->post_balance = $user->balance;
            $transaction->save();

            notify($user, 'REGISTER_BONUS', [
                'username'     => $user->username,
                'amount'       => showAmount(gs('register_bonus')),
                'trx'          => $transaction->trx,
                'post_balance' => showAmount($user->balance),
            ]);
        }
        return to_route('user.home');
    }

    protected function create(array $data) {
        $referBy = session()->get('reference');
        if ($referBy) {
            $referUser = User::where('username', $referBy)->first();
        } else {
            $referUser = null;
        }

        //User Create
        $user            = new User();
        $user->email     = strtolower($data['email']);
        // $user->firstname = $data['firstname'];
        // $user->lastname  = $data['lastname'];
        $user->mobile    = $data['mobile'];
        $user->username  = strtolower($data['username']);
        $user->password  = Hash::make($data['password']);
        $user->ref_by    = $referUser ? $referUser->id : 0;
        $user->kv        = gs('kv') ? Status::NO : Status::YES;
        $user->ev        = gs('ev') ? Status::NO : Status::YES;
        $user->sv        = gs('sv') ? Status::NO : Status::YES;
        $user->ts        = Status::DISABLE;
        $user->tv        = Status::ENABLE;
        $user->save();

        $adminNotification            = new AdminNotification();
        $adminNotification->user_id   = $user->id;
        $adminNotification->title     = 'New member registered';
        $adminNotification->click_url = urlPath('admin.users.detail', $user->id);
        $adminNotification->save();

        //Login Log Create
        $ip        = getRealIP();
        $exist     = UserLogin::where('user_ip', $ip)->first();
        $userLogin = new UserLogin();

        if ($exist) {
            $userLogin->longitude    = $exist->longitude;
            $userLogin->latitude     = $exist->latitude;
            $userLogin->city         = $exist->city;
            $userLogin->country_code = $exist->country_code;
            $userLogin->country      = $exist->country;
        } else {
            $info                    = json_decode(json_encode(getIpInfo()), true);
            $userLogin->longitude    = @implode(',', $info['long']);
            $userLogin->latitude     = @implode(',', $info['lat']);
            $userLogin->city         = @implode(',', $info['city']);
            $userLogin->country_code = @implode(',', $info['code']);
            $userLogin->country      = @implode(',', $info['country']);
        }

        $userAgent          = osBrowser();
        $userLogin->user_id = $user->id;
        $userLogin->user_ip = $ip;

        $userLogin->browser = @$userAgent['browser'];
        $userLogin->os      = @$userAgent['os_platform'];
        $userLogin->save();

        return $user;
    }

    public function checkUser(Request $request) {
        $exist['data'] = false;
        $exist['type'] = null;
        if ($request->email) {
            $exist['data']  = User::where('email', $request->email)->exists();
            $exist['type']  = 'email';
            $exist['field'] = 'Email';
        }
        if ($request->mobile) {
            $exist['data']  = User::where('mobile', $request->mobile)->where('dial_code', $request->mobile_code)->exists();
            $exist['type']  = 'mobile';
            $exist['field'] = 'Mobile';
        }
        if ($request->username) {
            $exist['data']  = User::where('username', $request->username)->exists();
            $exist['type']  = 'username';
            $exist['field'] = 'Username';
        }
        return response($exist);
    }

    public function registered() {
        return to_route('user.home');
    }

}
