<?php
namespace App\Traits;

use App\Constants\Status;
use App\Models\Transaction;

trait NumberSlot {
    public function PlayNumberSlot($game, $request) {
        $validator = $this->investValidation($request, '0,1,2,3,4,5,6,7,8,9');

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $user = auth()->user();

        $fallback = $this->fallback($request, $user, $game);

        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $random = mt_rand(1, 100);

        if ($game->probable_win[0] > $random) {
            $result = numberSlotResult(0, $request->choose);
            $win    = 0;
        } else if ($game->probable_win[0] + $game->probable_win[1] > $random) {
            $result = numberSlotResult(1, $request->choose);
            $win    = 1;
        } else if ($game->probable_win[0] + $game->probable_win[1] + $game->probable_win[2] > $random) {
            $result = numberSlotResult(2, $request->choose);
            $win    = 2;
        } else {
            $result = numberSlotResult(3, $request->choose);
            $win    = 3;
        }

        $invest = $this->invest($user, $request, $game, $result, $win);

        $res['game_id'] = $invest['game_log']->id;
        $res['number']  = $result;
        $res['win']     = $win;
        $res['balance'] = $user->balance;
        return response()->json($res);
    }

    public function gameEndNumberSlot($game, $request) {
        $validator = $this->endValidation($request);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $user    = auth()->user();
        $gameLog = $this->runningGame();

        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        $winner = 0;
        $trx    = getTrx();

        foreach ($game->level as $key => $data) {
            if ($gameLog->win_status == $key + 1) {
                $winBon = $gameLog->invest * $game->level[$key] / 100;
                $amo    = $winBon;
                $user->balance += $amo;
                $user->save();

                $gameLog->win_amo = $amo;
                $gameLog->save();

                $winner = 1;
                $lev    = $key + 1;

                $transaction               = new Transaction();
                $transaction->user_id      = $user->id;
                $transaction->amount       = $winBon;
                $transaction->charge       = 0;
                $transaction->trx_type     = '+';
                $transaction->details      = $game->level[$key] . '% Win bonus of Number Slot Game ' . $lev . ' Time';
                $transaction->remark       = 'win_bonus';
                $transaction->trx          = $trx;
                $transaction->post_balance = $user->balance;
                $transaction->save();
            }
        }

        if ($winner == 1) {
            $res['user_choose'] = $gameLog->user_select;
            $res['message']     = 'Yahoo! You Win for ' . $gameLog->win_status . ' Time !!!';
            $res['type']        = 'success';
            $res['bal']         = showAmount($user->balance, currencyFormat: false);
        } else {
            $res['user_choose'] = $gameLog->user_select;
            $res['message']     = 'Oops! You Lost!!';
            $res['type']        = 'danger';
            $res['bal']         = showAmount($user->balance, currencyFormat: false);
        }

        $gameLog->status = Status::ENABLE;
        $gameLog->save();

        return response()->json($res);
    }

}