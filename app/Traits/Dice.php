<?php

namespace App\Traits;

use App\Constants\Status;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait Dice {
    public function diceSubmit(Request $request) {
        $validator = Validator::make($request->all(), [
            'percent' => 'required|numeric|gt:0',
            'invest'  => 'required|numeric|gt:0',
            'range'   => 'required|in:low,high',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()]);
        }

        $user    = auth()->user();
        $running = GameLog::where('user_id', $user->id)->where('game_id', 10)->where('status', Status::GAME_RUNNING)->count();
        if ($running) {
            return response()->json(['error' => 'You have a unfinished game. please wait']);
        }
        $general = gs();
        $game    = Game::findOrFail(10);
        if ($request->invest < $game->min_limit) {
            return response()->json(['error' => 'Minimum invest limit is ' . showAmount($game->min_limit) . ' ' . $general->cur_text]);
        }
        if ($request->invest > $game->max_limit) {
            return response()->json(['error' => 'Maximum invest limit is ' . showAmount($game->max_limit) . ' ' . $general->cur_text]);
        }
        if ($request->invest > $user->balance) {
            return response()->json(['error' => 'Insufficient balance']);
        }

        $winChance   = $request->percent;
        $amount      = $request->invest;
        $lessThan    = $winChance * 100;
        $greaterThan = 9900 - ($winChance * 100) + 99;
        $payout      = round(99 / $winChance, 4);
        $winAmo      = $amount * $payout;
        $allChances  = rand(1, 98);
        $choose      = $request->range;

        if ($winChance >= $allChances) {
            $win = Status::WIN;
        } else {
            $win = Status::LOSS;
        }

        if ($win == 1) {
            if ($choose == 'low') {
                $number = rand(100, $lessThan);
            } else {
                $number = rand($greaterThan, 9899);
            }
        } else {
            if ($choose == 'low') {
                $number = rand(($lessThan + 1), 9899);
            } else {
                $number = rand(100, ($greaterThan - 1));
            }
        }
        if (strlen((string) $number) < 4) {
            $number = '0' . $number;
        }

        $invest            = $this->invest($user, $request, $game, $number, $win, $winAmo);
        $res['gameLog_id'] = $invest['game_log']->id;
        $res['balance']    = showAmount($user->balance, currencyFormat: false);
        return response()->json($res);
    }

    public function diceResult(Request $request) {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }
        $user    = auth()->user();
        $gameLog = GameLog::where('user_id', $user->id)->where('id', $request->game_id)->where('status', Status::GAME_RUNNING)->first();

        if (!$gameLog) {
            return response()->json(['error' => 'Game not found']);
        }

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
        }
        $gameLog->status = 1;
        $gameLog->save();

        return response()->json([
            'result'  => $gameLog->result,
            'win'     => $gameLog->win_status,
            'balance' => showAmount($user->balance, currencyFormat: false),
        ]);
    }
}