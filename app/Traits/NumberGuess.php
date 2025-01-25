<?php
namespace App\Traits;
use App\Constants\Status;
use App\Models\Game;
use App\Models\GuessBonus;
use App\Models\Transaction;
use Illuminate\Support\Facades\Validator;

trait NumberGuess {

    public function playNumberGuess($game, $request) {
        $validator = Validator::make($request->all(), [
            'invest' => 'required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $user = auth()->user();

        $fallback = $this->fallback($request, $user, $game);

        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $num = mt_rand(1, 100);

        $invest = $this->invest($user, $request, $game, $num, 0);

        $res['game_id'] = $invest['game_log']->id;
        $res['invest']  = $request->invest;
        $res['balance'] = $user->balance;

        return response()->json($res);
    }

    public function gameEndNumberGuess($game, $request) {

        $validator = Validator::make($request->all(), [
            'game_id' => 'required',
            'number'  => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        if ($request->number < 1 || $request->number > 100) {
            return response()->json(['error' => 'Enter a number between 1 and 100']);
        }

        $user    = auth()->user();
        $gameLog = $this->runningGame();

        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        if ($gameLog->user_select != null) {
            $userSelect = json_decode($gameLog->user_select);
            array_push($userSelect, $request->number);
        } else {
            $userSelect[] = $request->number;
        }

        $data  = GuessBonus::where('alias', $game->alias)->get();
        $count = $data->count();

        if ($gameLog->status == 1) {
            $mes['gameSt']  = 1;
            $mes['message'] = 'Time Over';
            return response()->json($mes);
        }

        $gameLog->try         = $gameLog->try + 1;
        $gameLog->user_select = json_encode($userSelect);

        if ($gameLog->try >= $count) {
            $gameLog->status = Status::ENABLE;
        }

        $gameLog->save();

        $bonus = GuessBonus::where('alias', $game->alias)->where('chance', $gameLog->try)->first()->percent;

        $amount = $gameLog->invest * $bonus / 100;

        $user = auth()->user();
        $game = Game::find($gameLog->game_id);

        $result = $gameLog->result;

        if ($request->number < $result) {
            $mes['message'] = 'The Number is short';
            $win            = Status::LOSS;
            $mes['type']    = 0;
        }

        if ($request->number > $result) {
            $mes['message'] = 'The Number is high';
            $win            = Status::LOSS;
            $mes['type']    = 1;
        }

        if ($gameLog->status == 1) {
            $mes['gameSt']     = 1;
            $mes['message']    = 'Oops You Lost! The Number was ' . $gameLog->result;
            $mes['win_status'] = 0;
            $mes['win_number'] = $gameLog->result;
        } else {
            $nextBonus   = GuessBonus::where('alias', $game->alias)->where('chance', $gameLog->try + 1)->first();
            $mes['data'] = $nextBonus->percent . '%';
        }

        if ($request->number == $result) {

            $gameLog->win_status = Status::WIN;
            $gameLog->status     = Status::ENABLE;
            $gameLog->win_amo    = $amount;
            $gameLog->save();

            $user->balance += $amount;
            $user->save();

            $transaction               = new Transaction();
            $transaction->user_id      = $user->id;
            $transaction->amount       = $amount;
            $transaction->charge       = 0;
            $transaction->trx_type     = '+';
            $transaction->details      = $bonus . '% Bonus For Number Guessing Game';
            $transaction->remark       = 'Win_Bonus';
            $transaction->trx          = getTrx();
            $transaction->post_balance = $user->balance;
            $transaction->save();

            $mes['gameSt']     = 1;
            $mes['message']    = 'This is the number';
            $mes['win_status'] = 1;
            $mes['win_number'] = $gameLog->result;
        }

        $mes['bal'] = showAmount($user->balance, currencyFormat: false);
        return response()->json($mes);
    }
}