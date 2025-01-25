<?php
namespace App\Traits;
use App\Constants\Status;

trait RockPaperScissors {
    public function playRockPaperScissors($game, $request) {

        $validator = $this->investValidation($request, 'rock,paper,scissors');

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $user = auth()->user();

        $fallback = $this->fallback($request, $user, $game);

        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $userChoose = $request->choose;
        $random     = mt_rand(0, 100);

        if ($random <= $game->probable_win) {
            $win = Status::WIN;

            if ($userChoose == 'rock') {
                $result = 'scissors';
            }

            if ($userChoose == 'paper') {
                $result = 'rock';
            }

            if ($userChoose == 'scissors') {
                $result = 'paper';
            }
        } else {
            $win = Status::LOSS;

            if ($userChoose == 'rock') {
                $result = 'paper';
            }

            if ($userChoose == 'paper') {
                $result = 'scissors';
            }

            if ($userChoose == 'scissors') {
                $result = 'rock';
            }
        }

        $invest = $this->invest($user, $request, $game, $result, $win);

        $res['game_id'] = $invest['game_log']->id;
        $res['balance'] = $user->balance;
        return response()->json($res);
    }

    public function gameEndRockPaperScissors($game, $request) {
        $validator = $this->endValidation($request);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $gameLog = $this->runningGame();

        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        $res = $this->gameResult($game, $gameLog);

        return response()->json($res);
    }
}