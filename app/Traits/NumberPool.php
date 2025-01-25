<?php

namespace App\Traits;

use App\Constants\Status;

trait NumberPool {
    public function playNumberPool($game, $request) {
        $validator = $this->investValidation($request, '1,2,3,4,5,6,7,8');

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $user = auth()->user();

        $fallback = $this->fallback($request, $user, $game);

        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $random = mt_rand(0, 100);
        $result = 8;

        if ($random <= $game->probable_win) {
            $win    = Status::ENABLE;
            $result = $request->choose;
        } else {
            $win = Status::DISABLE;

            for ($i = 0; $i < 100; $i++) {
                $randWin = rand(1, 8);

                if ($randWin != $request->choose) {
                    $result = $randWin;
                    break;
                }
            }
        }

        $invest = $this->invest($user, $request, $game, $result, $win);

        $res['game_id'] = $invest['game_log']->id;

        $res['invest']  = $request->invest;
        $res['result']  = $result;
        $res['win']     = $win;
        $res['balance'] = $user->balance;
        return response()->json($res);
    }

    public function gameEndNumberPool($game, $request) {
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