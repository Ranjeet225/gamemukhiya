<?php

namespace App\Http\Controllers\Gateway\PayU;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Gateway\PaymentController;
use App\Models\Deposit;
use Illuminate\Http\Request;

class ProcessController extends Controller {
    /*
     * PayU Gateway
     */

     public static function process($deposit) {
        $payu = json_decode($deposit->gatewayCurrency()->gateway_parameter);
        // API credentials
        $api_key    = $payu->merchant_key;
        $api_secret = $payu->merchant_salt;

        $alias = $deposit->gateway->alias;
        $successURL = url('pay.u.response');
        $failURL = url('pay.u.cancel');
        // PayU parameters
        $params = [
            'key'             => $api_key,
            'txnid'           => $deposit->trx, // Unique transaction ID
            'amount'          => number_format((float) $deposit->final_amount, 2, '.', ''), // Amount in decimal format
            'productinfo'     => 'Payment for Order #' . $deposit->trx,
            'firstname'       => auth()->user()->firstname,
            'email'           => auth()->user()->email,
            'phone'           => auth()->user()->mobileNumber,
            'surl'            => $successURL,
            'furl'            => $failURL,
            'service_provider'=> 'payu_paisa',
        ];

        // Generate hash
        $hash_string = $params['key'] . '|' . $params['txnid'] . '|' . $params['amount'] . '|' . $params['productinfo'] . '|' . $params['firstname'] . '|' . $params['email'] . '|||||||||||' . $api_secret;
        $params['hash'] = strtolower(hash('sha512', $hash_string));

        // Prepare form data for redirecting to PayU
        $send['url']     = 'https://test.payu.in/_payment';  // Ensure you're using the correct URL
        $send['method']  = 'POST';
        $send['val']     = $params;
        $send['view']    = 'user.payment.' . $alias;

        return json_encode($send);
    }

    public function ipn(Request $request) {
        $deposit = Deposit::where('trx', $request->txnid)->first();

        if (!$deposit) {
            $notify[] = ['error', 'Invalid transaction'];
            return back()->withNotify($notify);
        }

        $payu = json_decode($deposit->gatewayCurrency()->gateway_parameter);

        // Verify hash
        $posted_hash = $request->hash;
        $hash_string = $request->key . '|' . $request->txnid . '|' . $request->amount . '|' . $request->productinfo . '|' . $request->firstname . '|' . $request->email . '|' . $request->udf1 . '|' . $request->udf2 . '|' . $request->udf3 . '|' . $request->udf4 . '|' . $request->udf5 . '|' . $request->udf6 . '|' . $request->udf7 . '|' . $request->udf8 . '|' . $request->udf9 . '|' . $request->udf10 . '|' . $payu->merchant_salt;
        $calculated_hash = strtolower(hash('sha512', $hash_string));

        $deposit->detail = $request->all();
        $deposit->save();

        if ($calculated_hash === $posted_hash && $request->status === 'success') {
            PaymentController::userDataUpdate($deposit);
            $notify[] = ['success', 'Transaction successful'];
            return redirect($deposit->success_url)->withNotify($notify);
        } else {
            $notify[] = ['error', 'Transaction failed or invalid signature'];
            return back()->withNotify($notify);
        }
    }
}

