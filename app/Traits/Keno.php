<?php

namespace App\Traits;

use App\Constants\Status;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait Keno {
    public function kenoSubmit(Request $request) {
        $validator = Validator::make($request->all(), [
            'invest' => 'required|numeric|gt:0',
            'choose' => 'required|array|min:1|max:80',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $user = auth()->user();

        $game = Game::active()->where('alias', 'keno')->first();
        if (!$game) {
            return response()->json(['errors' => 'Game not found']);
        }

        $fallback = $this->fallback($request, $user, $game);
        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $random = mt_rand(0, 100);

        if ($random <= $game->probable_win) {
            $win    = Status::WIN;
            $result = $request->choose;
        } else {
            $win    = Status::LOSS;
            $result = $request->choose;
        }

        $winAmount       = 0;
        $maxSelectNumber = @$game->level->max_select_number;
        if ($win) {
            $getRandNumber    = rand(4, @$maxSelectNumber);
            $getNewSlotNumber = array_slice($result, 0, $getRandNumber, true);
            $matchNumber      = $getNewSlotNumber;

            while (count($getNewSlotNumber) < $maxSelectNumber) {
                $randomValue = rand(1, 80);
                if (!in_array($randomValue, $getNewSlotNumber) && !in_array($randomValue, $result)) {
                    array_push($getNewSlotNumber, (string) $randomValue);
                }
            }
            $result = $getNewSlotNumber;

            $commission = array_reduce($game->level->levels, function ($carry, $element) use ($getRandNumber) {
                if ((int) $element->level === $getRandNumber) {
                    $carry = $element->percent;
                }
                return $carry;
            });

            $winAmount = $request->invest + ($request->invest * $commission / 100);
        } else {
            $loseSlotNumber = [];
            while (count($loseSlotNumber) < $maxSelectNumber) {
                $randomValue = rand(1, 80);
                if (!in_array($randomValue, $loseSlotNumber) && !in_array($randomValue, $result)) {
                    array_push($loseSlotNumber, (string) $randomValue);
                }
            }
            $result      = $loseSlotNumber;
            $matchNumber = [];
        }

        $invest              = $this->invest($user, $request, $game, $result, $win, $winAmount);
        $res['game_log_id']  = $invest['game_log']->id;
        $res['user_select']  = json_decode($invest['game_log']->user_select);
        $res['match_number'] = $matchNumber;
        return response()->json($res);
    }

    public function kenoUpdate(Request $request) {
        $user    = auth()->user();
        $gameLog = GameLog::where('user_id', $user->id)->where('id', $request->gameLog_id)->first();
        if (!$gameLog) {
            return response()->json(['error' => 'Invalid game request']);
        }
        $gameLog->status = Status::GAME_FINISHED;
        $gameLog->save();

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

        return response()->json([
            'result' => json_decode($gameLog->result),
            'win'    => $gameLog->win_status,
        ]);
    }
}