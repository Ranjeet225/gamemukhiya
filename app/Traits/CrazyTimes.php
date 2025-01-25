<?php

namespace App\Traits;

use App\Constants\Status;
use App\Models\Transaction;

trait CrazyTimes {
    public function playCrazyTimes($game, $request) {
        $validator = $this->investValidation($request, '1,2,5,10,coin_flip,pachinko,cash_hunt,crazy_times');
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }
        $user     = auth()->user();
        $fallback = $this->fallback($request, $user, $game);
        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $random = mt_rand(0, 100);
        if ($random <= $game->probable_win) {
            $win = Status::ENABLE;
        } else {
            $win = Status::DISABLE;
        }

        $resultOption = [1, 2, 5, 10, 'coin_flip', 'pachinko', 'cash_hunt', 'crazy_times'];
        $winAmount    = 0;
        $result       = null;

        if (in_array($request->choose, $resultOption)) {
            if ($win) {
                $result = $request->choose;
                switch ($result) {
                case 1:
                    $winAmount = $request->invest + $request->invest * 1;
                    break;
                case 2:
                    $winAmount = $request->invest + $request->invest * 2;
                    break;
                case 5:
                    $winAmount = $request->invest + $request->invest * 5;
                    break;
                case 10:
                    $winAmount = $request->invest + $request->invest * 10;
                    break;
                case 'coin_flip':
                    $winAmount = $request->invest + ($request->invest * ($game->level[0] ?? 0) / 100);
                    break;
                case 'pachinko':
                    $winAmount = $request->invest + ($request->invest * ($game->level[1] ?? 0) / 100);
                    break;
                case 'cash_hunt':
                    $winAmount = $request->invest + ($request->invest * ($game->level[2] ?? 0) / 100);
                    break;
                case 'crazy_times':
                    $winAmount = $request->invest + ($request->invest * ($game->level[3] ?? 0) / 100);
                    break;
                }
            } else {
                $otherResultOption = array_diff($resultOption, [$request->choose]);
                $result            = $otherResultOption[array_rand($otherResultOption)];
            }
        }

        $invest         = $this->invest($user, $request, $game, $result, $win, $winAmount);
        $res['game_id'] = $invest['game_log']->id;
        $res['balance'] = getAmount($user->balance);
        $res['point']   = $invest['game_log']->result;
        return response()->json($res);
    }

    public function gameEndCrazyTimes($game, $request) {
        $validator = $this->endValidation($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $gameLog = $this->runningGame();
        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        $res = $this->crazyTimesGameResult($game, $gameLog);
        return response()->json($res);
    }

    public function crazyTimesGameResult($game, $gameLog) {
        $trx  = getTrx();
        $user = auth()->user();

        if ($gameLog->win_status == Status::WIN) {
            $user->balance += $gameLog->win_amo;
            $user->save();

            $transaction               = new Transaction();
            $transaction->user_id      = $user->id;
            $transaction->amount       = $gameLog->win_amo;
            $transaction->charge       = 0;
            $transaction->trx_type     = '+';
            $transaction->details      = 'Win bonus of ' . $game->name;
            $transaction->remark       = 'Win_Bonus';
            $transaction->trx          = $trx;
            $transaction->post_balance = $user->balance;
            $transaction->save();
        }

        if ($gameLog->win_status == Status::WIN) {
            $res['message'] = 'Yahoo! You Win!!!';
            $res['type']    = 'success';
        } else {
            $res['message'] = 'Oops! You Lost!!';
            $res['type']    = 'danger';
        }

        $res['result']      = in_array($gameLog->result, ['coin_flip', 'pachinko', 'cash_hunt', 'crazy_times']) ? ucwords(str_replace('_', ' ', $gameLog->result)) : $gameLog->result;
        $res['user_choose'] = $gameLog->user_select;
        $res['bal']         = getAmount($user->balance);
        $gameLog->status    = Status::GAME_FINISHED;
        $gameLog->save();
        return $res;
    }
}