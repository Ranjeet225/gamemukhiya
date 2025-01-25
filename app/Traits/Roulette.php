<?php

namespace App\Traits;

use App\Constants\Status;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait Roulette {
    public function rouletteSubmit(Request $request) {
        $validator = Validator::make($request->all(), [
            'invest' => 'required|numeric|gt:0',
            'choose' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()]);
        }
        $game = Game::where('id', 9)->first();
        if (!$game) {
            return response()->json(['error' => 'Game Not Found']);
        }
        if ($request->invest < $game->min_limit) {
            return response()->json(['error' => 'Minimum invest limit is ' . showAmount($game->min_limit)]);
        }

        if ($request->invest > $game->max_limit) {
            return response()->json(['error' => 'Maximum invest limit is ' . showAmount($game->max_limit)]);
        }
        $amount = $request->invest;
        $user   = auth()->user();
        if ($amount > $user->balance) {
            return response()->json(['error' => 'Insufficient balance']);
        }

        $running = GameLog::where('user_id', $user->id)->where('game_id', 9)->where('status', Status::GAME_RUNNING)->first();
        if ($running) {
            return response()->json(['error' => 'You have an unfinished game. Please wait']);
        }
        if ($request->choose == '1_12') {
            $numbers = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        } else if ($request->choose == '13_24') {
            $numbers = [13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24];
        } else if ($request->choose == '25_36') {
            $numbers = [25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36];
        } else if ($request->choose == '1_18') {
            $numbers = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18];
        } else if ($request->choose == '19_36') {
            $numbers = [19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36];
        } else if ($request->choose == 'even') {
            $numbers = [2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30, 32, 34, 36];
        } else if ($request->choose == 'odd') {
            $numbers = [1, 3, 5, 7, 9, 11, 13, 15, 17, 19, 21, 23, 25, 27, 29, 31, 33, 35];
        } else if ($request->choose == 'red') {
            $numbers = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];
        } else if ($request->choose == 'black') {
            $numbers = [2, 4, 6, 8, 10, 11, 13, 15, 17, 20, 22, 24, 26, 28, 29, 31, 33, 35];
        } else if ($request->choose == '2_1_1') {
            $numbers = [3, 6, 9, 12, 15, 18, 21, 24, 27, 30, 33, 36];
        } else if ($request->choose == '2_1_2') {
            $numbers = [2, 5, 8, 11, 14, 17, 20, 23, 26, 29, 32, 35];
        } else if ($request->choose == '2_1_3') {
            $numbers = [1, 4, 7, 10, 13, 16, 19, 22, 25, 28, 31, 34];
        } else {
            $numbers = [$request->choose];
        }

        $random = rand(1, 36);
        if (in_array($random, $numbers)) {
            $win = Status::WIN;
        } else {
            $win = Status::LOSS;
        }
        $winAmount         = $request->invest * (36 / count($numbers));
        $invest            = $this->invest($user, $request, $game, $random, $win, $winAmount); // random passed instead of number
        $res['gameLog_id'] = $invest['game_log']->id;
        $res['balance']    = showAmount($user->balance, currencyFormat: false);
        $res['result']     = $random;
        return response()->json($res);
    }

    public function rouletteResult(Request $request) {
        $validator = Validator::make($request->all(), [
            'gameLog_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()]);
        }
        $user    = auth()->user();
        $gameLog = GameLog::where('user_id', $user->id)->where('id', $request->gameLog_id)->where('game_id', 9)->where('status', 0)->first();
        if (!$gameLog) {
            return response()->json(['error' => 'Game not found']);
        }

        $notification = 'Oops! You Lost!';
        if ($gameLog->win_status == Status::WIN) {
            $user->balance += $gameLog->win_amo;
            $user->save();

            $transaction               = new Transaction();
            $transaction->user_id      = $user->id;
            $transaction->amount       = $gameLog->win_amo;
            $transaction->charge       = 0;
            $transaction->trx_type     = '+';
            $transaction->details      = 'Win bonus of ' . @$gameLog->game->name;
            $transaction->remark       = 'Win_Bonus';
            $transaction->trx          = getTrx();
            $transaction->post_balance = $user->balance;
            $transaction->save();
            $notification = 'Yahoo! You Win!';
        }
        $gameLog->status = Status::GAME_FINISHED;
        $gameLog->save();

        return response()->json([
            'result'       => $gameLog->result,
            'win'          => $gameLog->win_status,
            'balance'      => showAmount($user->balance, currencyFormat: false),
            'notification' => $notification,
        ]);
    }

}