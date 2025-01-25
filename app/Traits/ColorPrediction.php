<?php

namespace App\Traits;

use App\Constants\Status;
use App\Models\Transaction;

trait ColorPrediction {

    public function playColorPrediction($game, $request) {
        $validator = $this->investValidation($request, 'green,violet,red,0,1,2,3,4,5,6,7,8,9');
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

        $ratio        = 0;
        $resultOption = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];

        if ($request->choose == 'green') {
            $greenResultOption = [1, 3, 5, 7, 9];
            if ($win) {
                $result = $greenResultOption[array_rand($greenResultOption)];
                $ratio  = $result == 5 ? 1.5 : 2;
            } else {
                $otherResultOption = array_diff($resultOption, $greenResultOption);
                $result            = $otherResultOption[array_rand($otherResultOption)];
            }
        } else if ($request->choose == 'violet') {
            $violetResultOption = [0, 5];
            if ($win) {
                $result = $violetResultOption[array_rand($violetResultOption)];
                $ratio  = 4.5;
            } else {
                $otherResultOption = array_diff($resultOption, $violetResultOption);
                $result            = $otherResultOption[array_rand($otherResultOption)];
            }
        } else if ($request->choose == 'red') {
            $redResultOption = [2, 4, 6, 8, 0];
            if ($win) {
                $result = $redResultOption[array_rand($redResultOption)];
                $ratio  = $result == 0 ? 1.5 : 2;
            } else {
                $otherResultOption = array_diff($resultOption, $redResultOption);
                $result            = $otherResultOption[array_rand($otherResultOption)];
            }
        } else {
            if ($win) {
                $result = $request->choose;
                $ratio  = 9;
            } else {
                $otherResultOption = array_diff($resultOption, [$request->choose]);
                $result            = $otherResultOption[array_rand($otherResultOption)];
            }
        }

        $winAmount      = $request->invest * $ratio;
        $invest         = $this->invest($user, $request, $game, $result, $win, $winAmount);
        $res['game_id'] = $invest['game_log']->id;
        $res['balance'] = showAmount($user->balance);
        return response()->json($res);
    }

    public function gameEndColorPrediction($game, $request) {
        $validator = $this->endValidation($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $gameLog = $this->runningGame();
        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        $res = $this->colorGameResult($game, $gameLog);
        return response()->json($res);
    }

    public function colorGameResult($game, $gameLog) {
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
            $res['message'] = 'Congratulations! You won ' . showAmount($gameLog->win_amo);
        } else {
            $res['message'] = 'Sorry!, you lost';
        }

        $res['result']      = $gameLog->result;
        $res['win_status']  = $gameLog->win_status;
        $res['user_choose'] = $gameLog->user_select;
        $res['bal']         = showAmount($user->balance);
        $gameLog->status    = Status::GAME_FINISHED;
        $gameLog->save();
        return $res;
    }

}