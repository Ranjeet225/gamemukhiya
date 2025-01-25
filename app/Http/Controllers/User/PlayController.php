<?php

namespace App\Http\Controllers\User;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\GuessBonus;
use App\Models\Transaction;
use App\Traits\CardFinding;
use App\Traits\ColorPrediction;
use App\Traits\CrazyTimes;
use App\Traits\Dice;
use App\Traits\DiceRolling;
use App\Traits\HeadTail;
use App\Traits\Keno;
use App\Traits\NumberGuess;
use App\Traits\NumberPool;
use App\Traits\NumberSlot;
use App\Traits\RockPaperScissors;
use App\Traits\Roulette;
use App\Traits\SpinWheel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlayController extends Controller {

    use ColorPrediction, Roulette, HeadTail, RockPaperScissors, SpinWheel, NumberGuess, DiceRolling, CardFinding, NumberSlot, NumberPool, Dice, Keno, CrazyTimes;

    public function playGame($alias) {
        $game      = Game::active()->where('alias', $alias)->firstOrFail();
        $pageTitle = "Play " . $game->name;
        return view('Template::user.games.' . $alias, compact('game', 'pageTitle'));
    }

    public function investGame(Request $request, $alias) {
        $game = Game::active()->where('alias', $alias)->first();
        if (!$game) {
            return response()->json(['error' => 'Game not found']);
        }
        $aliasName  = str_replace('_', ' ', $alias);
        $methodName = 'play' . str_replace(' ', '', ucwords($aliasName));
        return $this->$methodName($game, $request);
    }

    public function gameEnd(Request $request, $alias) {
        $game = Game::active()->where('alias', $alias)->first();
        if (!$game) {
            return response()->json(['error' => 'Game not found']);
        }
        $aliasName  = str_replace('_', ' ', $alias);
        $methodName = 'gameEnd' . str_replace(' ', '', ucwords($aliasName));
        return $this->$methodName($game, $request);
    }

    public function investValidation($request, $in) {
        return Validator::make($request->all(), [
            'invest' => 'required|numeric|gt:0',
            'choose' => 'required|in:' . $in,
        ]);
    }

    public function fallback($request, $user, $game) {

        if ($request->invest > $user->balance) {
            return ['error' => 'Oops! You have no sufficient balance'];
        }

        $running = GameLog::where('status', 0)->where('user_id', $user->id)->where('game_id', $game->id)->first();

        if ($running) {
            return ['error' => '1 game is in-complete. Please wait'];
        }

        if ($request->invest > $game->max_limit) {
            return ['error' => 'Please follow the maximum limit of invest'];
        }

        if ($request->invest < $game->min_limit) {
            return ['error' => 'Please follow the minimum limit of invest'];
        }

        return ['success'];
    }

    public function endValidation($request) {
        return Validator::make($request->all(), [
            'game_id' => 'required',
        ]);
    }

    public function runningGame() {
        return GameLog::where('user_id', auth()->id())->where('id', request()->game_id)->first();
    }

    public function gameResult($game, $gameLog) {
        $trx  = getTrx();
        $user = auth()->user();

        if ($gameLog->win_status == Status::WIN) {
            $winBon     = $gameLog->invest * $game->win / 100;
            $amount     = $winBon;
            $investBack = 0;

            if ($game->invest_back == Status::YES) {
                $investBack = $gameLog->invest;
                $user->balance += $gameLog->invest;
                $user->save();

                $transaction               = new Transaction();
                $transaction->user_id      = $user->id;
                $transaction->amount       = $investBack;
                $transaction->charge       = 0;
                $transaction->trx_type     = '+';
                $transaction->details      = 'Invest Back For ' . $game->name;
                $transaction->remark       = 'invest_back';
                $transaction->trx          = $trx;
                $transaction->post_balance = $user->balance;
                $transaction->save();
            }

            $user->balance += $amount;
            $user->save();

            $gameLog->win_amo = $amount;
            $gameLog->save();

            $transaction               = new Transaction();
            $transaction->user_id      = $user->id;
            $transaction->amount       = $winBon;
            $transaction->charge       = 0;
            $transaction->trx_type     = '+';
            $transaction->details      = 'Win bonus of ' . $game->name;
            $transaction->remark       = 'Win_Bonus';
            $transaction->trx          = $trx;
            $transaction->post_balance = $user->balance;
            $transaction->save();

            $res['message'] = 'Yahoo! You Win!!!';
            $res['type']    = 'success';
        } else {
            $res['message'] = 'Oops! You Lost!!';
            $res['type']    = 'danger';
        }

        $res['result']      = $gameLog->result;
        $res['user_choose'] = $gameLog->user_select;
        $res['bal']         = showAmount($user->balance, currencyFormat: false);

        $gameLog->status = Status::GAME_FINISHED;
        $gameLog->save();

        return $res;
    }

    public function invest($user, $request, $game, $result, $win, $winAmount = 0) {
        $user->balance -= $request->invest;
        $user->save();

        $transaction               = new Transaction();
        $transaction->user_id      = $user->id;
        $transaction->amount       = $request->invest;
        $transaction->charge       = 0;
        $transaction->trx_type     = '-';
        $transaction->details      = 'Invest to ' . $game->name;
        $transaction->remark       = 'invest';
        $transaction->trx          = getTrx();
        $transaction->post_balance = $user->balance;
        $transaction->save();

        $gameLog                 = new GameLog();
        $gameLog->user_id        = $user->id;
        $gameLog->game_id        = $game->id;
        $gameLog->user_select    = in_array($game->alias, ['keno']) ? json_encode($request->choose) : @$request->choose;
        $gameLog->result         = in_array($game->alias, ['number_slot', 'roulette', 'keno', 'poker']) ? json_encode($result) : $result;
        $gameLog->status         = 0;
        $gameLog->win_status     = $win;
        $gameLog->invest         = $request->invest;
        $gameLog->win_amo        = $winAmount;
        $gameLog->mines          = @$request->mines ?? 0;
        $gameLog->mine_available = @$request->mines ?? 0;
        $gameLog->save();
        return ['game_log' => $gameLog];
    }

    public function playBlackjack($game, $request) {
        $validator = Validator::make($request->all(), [
            'invest' => "required|numeric|gte:$game->min_limit",
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()]);
        }
        $user     = auth()->user();
        $fallback = $this->fallback($request, $user, $game);

        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $values = ["A", "2", "3", "4", "5", "6", "7", "8", "9", "10", "J", "Q", "K"];
        $types  = ["C", "D", "H", "S"];
        $deck   = [];
        for ($i = 0; $i < count($types); $i++) {
            for ($j = 0; $j < count($values); $j++) {
                $deck[] = $values[$j] . "-" . $types[$i];
            }
        }
        for ($a = 0; $a < count($deck); $a++) {
            $randValue = ((float) rand() / (float) getrandmax()) * count($deck);
            $b         = (int) floor($randValue);
            $temp      = $deck[$a];
            $deck[$a]  = $deck[$b];
            $deck[$b]  = $temp;
        }

        $dealerSum = 0;
        $userSum   = 0;

        $dealerAceCount = 0;
        $userAceCount   = 0;

        $hidden = array_pop($deck);
        $dealerSum += $this->getValue($hidden);
        $dealerAceCount += $this->checkAce($hidden);

        while ($dealerSum < 17) {
            $dealerCard      = array_pop($deck);
            $dealerCardImg[] = $dealerCard;
            $dealerSum       = $dealerSum + $this->getValue($dealerCard);
            $dealerAceCount += $this->checkAce($dealerCard);
        }

        for ($m = 0; $m < 2; $m++) {
            $card      = array_pop($deck);
            $cardImg[] = $card;
            $userSum += $this->getValue($card);
            $userAceCount += $this->checkAce($card);
        }

        $user->balance -= $request->invest;
        $user->save();

        $transaction               = new Transaction();
        $transaction->user_id      = $user->id;
        $transaction->amount       = $request->invest;
        $transaction->charge       = 0;
        $transaction->trx_type     = '-';
        $transaction->details      = 'Invest to ' . $game->name;
        $transaction->remark       = 'invest';
        $transaction->trx          = getTrx();
        $transaction->post_balance = $user->balance;
        $transaction->save();

        $dealerResult = array_merge([$hidden], $dealerCardImg);

        $gameLog              = new GameLog();
        $gameLog->user_id     = $user->id;
        $gameLog->game_id     = $game->id;
        $gameLog->user_select = json_encode($cardImg);
        $gameLog->result      = json_encode($dealerResult);
        $gameLog->status      = 0;
        $gameLog->win_status  = 0;
        $gameLog->invest      = $request->invest;
        $gameLog->save();

        return response()->json([
            'dealerSum'      => $dealerSum,
            'dealerAceCount' => $dealerAceCount,
            'userSum'        => $userSum,
            'userAceCount'   => $userAceCount,
            'dealerCardImg'  => $dealerCardImg,
            'cardImg'        => $cardImg,
            'game_log_id'    => $gameLog->id,
            'balance'        => showAmount($user->balance, currencyFormat: false),
            'card'           => $deck,
        ]);
    }

    protected function getValue($card) {
        $data  = explode("-", $card);
        $value = $data[0];
        if ($value == 'A' || $value == 'K' || $value == 'Q' || $value == 'J') {
            if ($value == "A") {
                return 11;
            }
            return 10;
        }
        return (int) $value;
    }

    protected function checkAce($card) {
        if ($card[0] == "A") {
            return 1;
        }
        return 0;
    }

    public function blackjackHit(Request $request) {

        $gameLog = GameLog::where('status', 0)->where('id', $request->game_log_id)->where('user_id', auth()->id())->first();
        if (!$gameLog) {
            return response()->json(['error' => 'Game not found']);
        }
        $userSum      = $request->userSum;
        $userAceCount = $request->userAceCount;
        $reduceAce    = $this->reduceAce($userSum, $userAceCount);
        if ($reduceAce > 21) {
            return response()->json(['error' => 'You can\'t hit more']);
        }
        $deck      = $request->card;
        $card      = array_pop($deck);
        $cardImg[] = $card;
        $userSum += $this->getValue($card);
        $userAceCount += $this->checkAce($card);

        $oldCard              = json_decode($gameLog->user_select);
        $newCard              = array_merge($oldCard, [$card]);
        $gameLog->user_select = json_encode($newCard);
        $gameLog->save();

        return response()->json([
            'dealerAceCount' => $request->dealerAceCount,
            'userSum'        => $userSum,
            'userAceCount'   => $userAceCount,
            'cardImg'        => $cardImg,
            'game_log_id'    => $gameLog->id,
            'card'           => $deck,
        ]);
    }

    public function blackjackStay(Request $request) {
        $gameLog = GameLog::where('status', 0)->where('id', $request->game_log_id)->where('user_id', auth()->id())->first();
        if (!$gameLog) {
            return response()->json(['error' => 'Game not found']);
        }

        $userSelectCard = json_decode($gameLog->user_select);
        $userCardSum    = 0;
        foreach ($userSelectCard as $userCard) {
            $userCardSum += $this->getValue($userCard);
        }

        $dealerSelectCard = json_decode($gameLog->result);
        $dealerCardSum    = 0;
        foreach ($dealerSelectCard as $dealerCard) {
            $dealerCardSum += $this->getValue($dealerCard);
        }

        $userAceCount   = $request->userAceCount;
        $dealerAceCount = $request->dealerAceCount;
        $hiddenImage    = $dealerSelectCard[0];

        $userSum   = $this->reduceAce($userCardSum, $userAceCount);
        $dealerSum = $this->reduceAce($dealerCardSum, $dealerAceCount);

        if ($userSum > 21) {
            $gameLog->win_status = Status::LOSS;
            $winStatus           = 'Loss';
        } else if ($dealerSum > 21) {
            $this->winBonus($gameLog, 'win');
            $gameLog->win_status = Status::WIN;
            $winStatus           = 'Win';
        } else if ($userSum == $dealerSum) {
            $this->winBonus($gameLog);
            $gameLog->win_status = Status::WIN;
            $winStatus           = 'Tie';
        } else if ($userSum > $dealerSum) {
            $this->winBonus($gameLog, 'win');
            $gameLog->win_status = Status::WIN;
            $winStatus           = 'Win';
        } else if ($userSum < $dealerSum) {
            $gameLog->win_status = Status::LOSS;
            $winStatus           = 'Loss';
        }

        $gameLog->status = Status::ENABLE;
        $gameLog->save();

        return response()->json([
            'hiddenImage' => $hiddenImage,
            'win_status'  => $winStatus,
            'userSum'     => $userSum,
            'dealerSum'   => $dealerSum,
            'game_log_id' => $gameLog->id,
        ]);
    }

    protected function winBonus($data, $status = null) {
        $gameLog = $data;
        $user    = $gameLog->user;
        $game    = $gameLog->game;
        $winBon  = $gameLog->invest;
        if ($status) {
            $winBon += $gameLog->invest * $game->win / 100;
        }

        $user->balance += $winBon;
        $user->save();

        $gameLog->win_amo = $winBon;
        $gameLog->save();

        $transaction           = new Transaction();
        $transaction->user_id  = $user->id;
        $transaction->amount   = $winBon;
        $transaction->charge   = 0;
        $transaction->trx_type = '+';
        if ($status) {
            $transaction->details = 'Win bonus of ' . $game->name;
            $transaction->remark  = 'Win_Bonus';
        } else {
            $transaction->details = 'Match Tie of ' . $game->name;
            $transaction->remark  = 'invest_back';
        }
        $transaction->trx          = getTrx();
        $transaction->post_balance = $user->balance;
        $transaction->save();
        return true;
    }

    protected function reduceAce($userSum, $userAceCount) {
        while ($userSum > 21 && $userAceCount > 0) {
            $userSum -= 10;
            $userAceCount -= 1;
        }
        return $userSum;
    }

    public function blackjackAgain($id) {
        $user    = auth()->user();
        $gameLog = GameLog::where('user_id', $user->id)->where('id', $id)->first();
        if (!$gameLog) {
            return response()->json(['error' => 'Game not found']);
        }

        $game = $gameLog->game;

        if ($gameLog->invest > $user->balance) {
            return response()->json(['error' => 'Insufficient balance on you account']);
        }

        $running = GameLog::where('status', 0)->where('user_id', $user->id)->where('game_id', $game->id)->first();

        if ($running) {
            return ['error' => '1 game is in-complete. Please wait'];
        }

        if ($gameLog->invest > $game->max_limit) {
            return ['error' => 'Please follow the maximum limit of invest'];
        }

        if ($gameLog->invest < $game->min_limit) {
            return ['error' => 'Please follow the minimum limit of invest'];
        }

        $values = ["A", "2", "3", "4", "5", "6", "7", "8", "9", "10", "J", "Q", "K"];
        $types  = ["C", "D", "H", "S"];
        $deck   = [];
        for ($i = 0; $i < count($types); $i++) {
            for ($j = 0; $j < count($values); $j++) {
                $deck[] = $values[$j] . "-" . $types[$i];
            }
        }
        for ($a = 0; $a < count($deck); $a++) {
            $randValue = ((float) rand() / (float) getrandmax()) * count($deck);
            $b         = (int) floor($randValue);
            $temp      = $deck[$a];
            $deck[$a]  = $deck[$b];
            $deck[$b]  = $temp;
        }

        $dealerSum = 0;
        $userSum   = 0;

        $dealerAceCount = 0;
        $userAceCount   = 0;

        $hidden = array_pop($deck);
        $dealerSum += $this->getValue($hidden);
        $dealerAceCount += $this->checkAce($hidden);

        while ($dealerSum < 17) {
            $dealerCard      = array_pop($deck);
            $dealerCardImg[] = $dealerCard;
            $dealerSum       = $dealerSum + $this->getValue($dealerCard);
            $dealerAceCount += $this->checkAce($dealerCard);
        }

        for ($m = 0; $m < 2; $m++) {
            $card      = array_pop($deck);
            $cardImg[] = $card;
            $userSum += $this->getValue($card);
            $userAceCount += $this->checkAce($card);
        }

        $user->balance -= $gameLog->invest;
        $user->save();

        $transaction               = new Transaction();
        $transaction->user_id      = $user->id;
        $transaction->amount       = $gameLog->invest;
        $transaction->charge       = 0;
        $transaction->trx_type     = '-';
        $transaction->details      = 'Invest to ' . $game->name;
        $transaction->remark       = 'invest';
        $transaction->trx          = getTrx();
        $transaction->post_balance = $user->balance;
        $transaction->save();

        $dealerResult = array_merge([$hidden], $dealerCardImg);

        $newGameLog              = new GameLog();
        $newGameLog->user_id     = $user->id;
        $newGameLog->game_id     = $game->id;
        $newGameLog->user_select = json_encode($cardImg);
        $newGameLog->result      = json_encode($dealerResult);
        $newGameLog->status      = 0;
        $newGameLog->win_status  = 0;
        $newGameLog->invest      = $gameLog->invest;
        $newGameLog->save();

        return response()->json([
            'dealerSum'      => $dealerSum,
            'dealerAceCount' => $dealerAceCount,
            'userSum'        => $userSum,
            'userAceCount'   => $userAceCount,
            'dealerCardImg'  => $dealerCardImg,
            'cardImg'        => $cardImg,
            'game_log_id'    => $newGameLog->id,
            'balance'        => $user->balance,
            'card'           => $deck,
        ]);
    }

    public function playMines($game, $request) {
        $validator = Validator::make($request->all(), [
            'invest' => 'required|numeric|gte:0',
            'mines'  => 'required|integer|min:1|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $user = auth()->user();

        $fallback = $this->fallback($request, $user, $game);

        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $random = mt_rand(0, 100);
        if ($random <= $game->probable_win) {
            $win           = Status::WIN;
            $result        = $random;
            $availableMine = floor($result / 4);

            if (($request->mines + $availableMine) > 25) {
                $moreMines = ($request->mines + $availableMine) - 25;
                $availableMine -= $moreMines;
            }
        } else {
            $win           = Status::LOSS;
            $result        = 0;
            $availableMine = 0;
        }

        $invest                  = $this->invest($user, $request, $game, $result, $win);
        $gameLog                 = $invest['game_log'];
        $gameLog->mine_available = $availableMine;
        $gameLog->save();

        $res['game_log_id'] = $invest['game_log']->id;
        $res['balance']     = showAmount($user->balance, currencyFormat: false);
        $res['random']      = $random;
        return response()->json($res);
    }

    public function gameEndMines($game, $request) {
        $validator = $this->endValidation($request);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $gameLog = $this->runningGame();
        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        if (!$gameLog->result) {
            $gameLog->status         = Status::GAME_FINISHED;
            $gameLog->win_status     = Status::LOSS;
            $gameLog->mine_available = 0;
            $gameLog->save();

            $res['type']    = 'danger';
            $res['sound']   = getImage('assets/audio/mine.mp3');
            $res['message'] = 'Oops! You Lost!!';
        } else {
            if ($gameLog->mine_available == 0) {
                $gameLog->status     = Status::GAME_FINISHED;
                $gameLog->win_status = Status::LOSS;

                $res['type']    = 'danger';
                $res['sound']   = getImage('assets/audio/mine.mp3');
                $res['message'] = 'Oops! You Lost!!';
            } else {
                $gameLog->gold_count += 1;
                $gameLog->mine_available -= 1;

                $winAmount = 0;
                $mineBonus = GuessBonus::where('alias', $game->alias)->where('chance', $gameLog->mines)->first();
                if ($mineBonus) {
                    $winAmount = $gameLog->invest + ($gameLog->invest * ($gameLog->gold_count * $mineBonus->percent) / 100);
                }
                $gameLog->win_amo = $winAmount;

                $res['type']  = 'success';
                $res['sound'] = getImage('assets/audio/win.wav');
            }
            $gameLog->save();
        }

        $res['mines']            = $gameLog->mines;
        $res['gold_count']       = $gameLog->gold_count;
        $res['mine_image']       = getImage(activeTemplate(true) . 'images/mines/mines.png');
        $res['gold_image']       = getImage(activeTemplate(true) . 'images/mines/gold.png');
        $res['gold_transparent'] = getImage(activeTemplate(true) . 'images/mines/gold_transparent.png');

        return response()->json($res);
    }

    public function mineCashout(Request $request) {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $gameLog = $this->runningGame();

        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        $gameLog->status     = Status::GAME_FINISHED;
        $gameLog->win_status = Status::WIN;
        $gameLog->save();

        $user = auth()->user();
        $user->balance += $gameLog->win_amo;
        $user->save();

        $game = $gameLog->game;

        $transaction               = new Transaction();
        $transaction->user_id      = $user->id;
        $transaction->amount       = $gameLog->win_amo;
        $transaction->charge       = 0;
        $transaction->trx_type     = '+';
        $transaction->details      = 'Win bonus of ' . $game->name;
        $transaction->remark       = 'Win_Bonus';
        $transaction->trx          = getTrx();
        $transaction->post_balance = $user->balance;
        $transaction->save();

        return response()->json([
            'balance' => showAmount($user->balance, currencyFormat: false),
            'sound'   => getImage('assets/audio/win.wav'),
            'success' => 'Congratulation! you won ' . getAmount($gameLog->win_amo) . ' ' . gs('cur_text'),
        ]);
    }

    public function playPoker($game, $request) {
        $validator = Validator::make($request->all(), [
            'invest' => 'required|numeric|gte:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $user = auth()->user();

        $fallback = $this->fallback($request, $user, $game);

        if (@$fallback['error']) {
            return response()->json($fallback);
        }

        $random = mt_rand(0, 100);

        if ($random <= $game->probable_win) {
            $win = Status::WIN;

            $rankName = [
                'royal_flush',
                'straight_flush',
                'four_of_a_kind',
                'full_house',
                'flush',
                'straight',
                'three_of_a_kind',
                'two_pair',
                'pair',
                'high_card',
            ];

            $targetRank = $rankName[rand(0, 9)];
            $rankGet    = true;
            while ($rankGet) {
                $hand = $this->generatePokerHand($targetRank);
                $rank = $this->hasSpecificHand($hand);
                if ($rank != 'no_match') {
                    $rankGet = false;
                }
            }
        } else {
            $win  = Status::LOSS;
            $deck = $this->initializeDeck();
            $hand = $this->dealCardsWithoutRank($deck);
            $rank = $this->hasSpecificHand($hand);
        }
        $result = $hand;
        $invest = $this->invest($user, $request, $game, $result, $win);

        $res['game_log_id'] = $invest['game_log']->id;
        $res['balance']     = showAmount($user->balance, currencyFormat: false);
        $res['message']     = getAmount($request->invest) . ' ' . gs('cur_text') . ' ' . 'betted successfully';
        return response()->json($res);
    }

    private function initializeDeck() {
        $suits = ['H', 'D', 'C', 'S'];
        $ranks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        $deck  = [];
        foreach ($suits as $suit) {
            foreach ($ranks as $rank) {
                $deck[] = $rank . '-' . $suit;
            }
        }
        shuffle($deck);
        return $deck;
    }

    function generatePokerHand($targetRank) {
        $suits = ['H', 'D', 'C', 'S'];
        $ranks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];

        $hand = [];

        switch ($targetRank) {
        case 'royal_flush':
            $suit = $suits[rand(0, 3)];
            $hand = ["A-$suit", "K-$suit", "Q-$suit", "J-$suit", "10-$suit"];
            break;

        case 'straight_flush':
            $suit       = $suits[rand(0, 3)];
            $startIndex = rand(0, 9); // To ensure a valid straight
            for ($i = $startIndex; $i < $startIndex + 5; $i++) {
                $hand[] = $ranks[$i % 13] . "-$suit";
            }

            usort($hand, function ($a, $b) use ($ranks) {
                $rankA = array_search(substr($a, 0, -2), $ranks);
                $rankB = array_search(substr($b, 0, -2), $ranks);
                return $rankB - $rankA;
            });
            break;

        case 'four_of_a_kind':
            $rank = $ranks[rand(0, 12)];
            $hand = [$rank . '-H', $rank . '-D', $rank . '-C', $rank . '-S', $ranks[rand(0, 12)] . '-H'];

            usort($hand, function ($a, $b) use ($rank) {
                return substr($a, 0, -2) === $rank ? -1 : 1;
            });
            break;

        case 'full_house':
            $rank1 = $ranks[rand(0, 12)];
            $rank2 = $ranks[rand(0, 12)];
            while ($rank2 == $rank1) {
                $rank2 = $ranks[rand(0, 12)];
            }
            $hand = [$rank1 . '-H', $rank1 . '-D', $rank1 . '-C', $rank2 . '-S', $rank2 . '-H'];

            usort($hand, function ($a, $b) use ($rank1, $rank2) {
                $rankA = array_search(substr($a, 0, -2), [$rank1, $rank2]);
                $rankB = array_search(substr($b, 0, -2), [$rank1, $rank2]);
                return $rankA - $rankB;
            });
            break;

        case 'flush':
            $suit = $suits[rand(0, 3)];
            for ($i = 0; $i < 5; $i++) {
                $hand[] = $ranks[rand(0, 12)] . "-$suit";
            }
            usort($hand, function ($a, $b) use ($ranks) {
                $rankA = array_search(substr($a, 0, -2), $ranks);
                $rankB = array_search(substr($b, 0, -2), $ranks);
                return $rankB - $rankA;
            });
            break;

        case 'straight':
            $startIndex = rand(0, 9);
            for ($i = $startIndex; $i < $startIndex + 5; $i++) {
                $hand[] = $ranks[$i % 13] . '-' . $suits[rand(0, 3)];
            }
            usort($hand, function ($a, $b) use ($ranks) {
                $rankA = array_search(substr($a, 0, -2), $ranks);
                $rankB = array_search(substr($b, 0, -2), $ranks);
                return $rankB - $rankA;
            });
            break;

        case 'three_of_a_kind':
            $rank = $ranks[rand(0, 12)];
            $hand = [$rank . '-H', $rank . '-D', $rank . '-C', $ranks[rand(0, 12)] . '-S', $ranks[rand(0, 12)] . '-H'];
            usort($hand, function ($a, $b) use ($rank) {
                if (substr($a, 0, -2) === $rank) {
                    return -1;
                } else if (substr($b, 0, -2) === $rank) {
                    return 1;
                } else {
                    return 0;
                }
            });
            break;

        case 'two_pair':
            $rank1 = $ranks[rand(0, 12)];
            $rank2 = $ranks[rand(0, 12)];
            while ($rank2 == $rank1) {
                $rank2 = $ranks[rand(0, 12)];
            }
            $hand = [$rank1 . '-H', $rank1 . '-D', $rank2 . '-C', $rank2 . '-S', $ranks[rand(0, 12)] . '-H'];
            usort($hand, function ($a, $b) use ($rank1, $rank2) {
                $rankA = array_search(substr($a, 0, -2), [$rank1, $rank2]);
                $rankB = array_search(substr($b, 0, -2), [$rank1, $rank2]);
                return $rankA - $rankB;
            });
            break;

        case 'pair':
            $rank = $ranks[rand(0, 12)];
            $hand = [$rank . '-H', $rank . '-D', $ranks[rand(0, 12)] . '-C', $ranks[rand(0, 12)] . '-S', $ranks[rand(0, 12)] . '-H'];
            usort($hand, function ($a, $b) use ($rank) {
                if (substr($a, 0, -2) === $rank) {
                    return -1;
                } else if (substr($b, 0, -2) === $rank) {
                    return 1;
                } else {
                    return 0;
                }
            });
            break;

        case 'high_card':
            for ($i = 0; $i < 5; $i++) {
                $hand[] = $ranks[rand(0, 12)] . '-' . $suits[rand(0, 3)];
            }
            usort($hand, function ($a, $b) use ($ranks) {
                $rankA = array_search(substr($a, 0, -2), $ranks);
                $rankB = array_search(substr($b, 0, -2), $ranks);
                return $rankB - $rankA;
            });
            break;

        default:
            break;
        }

        return $hand;
    }

    private function hasSpecificHand($hand) {
        $handTypes = [
            'royal_flush',
            'straight_flush',
            'four_of_a_kind',
            'full_house',
            'flush',
            'straight',
            'three_of_a_kind',
            'two_pair',
            'pair',
            'high_card',
        ];

        foreach ($handTypes as $handType) {
            $methodName = 'is' . str_replace('_', '', ucwords($handType, '_'));
            if ($this->$methodName($hand)) {
                return $handType;
            }
        }

        return 'no_match';
    }

    private function dealCardsWithoutRank($deck) {
        $hand = [];

        while (count($hand) < 5) {
            $card = array_shift($deck);

            $currentRank = explode('-', $card)[0];
            $ranksInHand = array_map(function ($c) {
                return explode('-', $c)[0];
            }, $hand);

            if (!in_array($currentRank, $ranksInHand)) {
                $hand[] = $card;
            }
        }

        return $hand;
    }

    public function isRoyalFlush($hand) {
        $requiredRanks = ['10', 'J', 'Q', 'K', 'A'];
        $requiredSuits = array_unique(array_map(function ($card) {
            return explode('-', $card)[1];
        }, $hand));

        return count(array_intersect($requiredRanks, $this->getRanks($hand))) === 5
        && count($requiredSuits) === 1;
    }

    public function isStraightFlush($hand) {
        $ranks = $this->getRanks($hand);
        $suit  = explode('-', $hand[0])[1];

        return count($ranks) === 5
        && count(array_diff($ranks, array_values(range(min($ranks), max($ranks))))) === 0
        && count(array_unique(array_map(function ($card) {
            return explode('-', $card)[1];
        }, $hand))) === 1;
    }

    public function isFourOfAKind($hand) {
        $rankCount = array_count_values($this->getRanks($hand));
        return in_array(4, $rankCount);
    }

    public function isFullHouse($hand) {
        $rankCount = array_count_values($this->getRanks($hand));
        return in_array(3, $rankCount) && in_array(2, $rankCount);
    }

    public function isFlush($hand) {
        $suits = array_map(function ($card) {
            return explode('-', $card)[1];
        }, $hand);

        return count(array_unique($suits)) === 1;
    }

    public function isStraight($hand) {
        $ranks = $this->getRanks($hand);

        return count($ranks) === 5
        && count(array_diff($ranks, array_values(range(min($ranks), max($ranks))))) === 0
        && count(array_unique($ranks)) === 5;
    }

    public function isThreeOfAKind($hand) {
        $rankCount = array_count_values($this->getRanks($hand));
        return in_array(3, $rankCount);
    }

    public function isTwoPair($hand) {
        $rankCount = array_count_values($this->getRanks($hand));
        return count(array_filter($rankCount, function ($count) {
            return $count === 2;
        })) === 2;
    }

    public function isPair($hand) {
        $rankCount = array_count_values($this->getRanks($hand));
        return in_array(2, $rankCount);
    }

    public function isHighCard($hand) {
        $ranks = $this->getRanks($hand);

        return count($ranks) === 5
        && count(array_diff($ranks, array_values(range(min($ranks), max($ranks))))) === 0
        && count(array_unique($ranks)) === 5;
    }

    private function getRanks($hand) {
        return array_map(function ($card) {
            return explode('-', $card)[0];
        }, $hand);
    }

    public function pokerDeal(Request $request) {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $gameLog = $this->runningGame();

        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }
        $res['result'] = array_slice(json_decode($gameLog->result), 0, 3);
        $res['path']   = asset(activeTemplate(true) . '/images/cards/');
        return response()->json($res);
    }
    public function pokerCall(Request $request) {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $gameLog = $this->runningGame();

        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        $rank = $this->hasSpecificHand(json_decode($gameLog->result));
        if ($rank == 'no_match' || $gameLog->win_status == Status::LOSS) {
            $gameLog->status = Status::GAME_FINISHED;
            $gameLog->save();

            $res['message'] = 'Oops! You Lost!!';
            $res['type']    = 'danger';
            $res['sound']   = getImage('assets/audio/lose.wav');
        } else {
            $ranks = [
                'royal_flush',
                'straight_flush',
                'four_of_a_kind',
                'full_house',
                'flush',
                'straight',
                'three_of_a_kind',
                'two_pair',
                'pair',
                'high_card',
            ];

            $rankNumber = array_search($rank, $ranks);
            $game       = $gameLog->game;
            $bonus      = 0;

            $rankBonus = GuessBonus::where('alias', $game->alias)->where('chance', $rankNumber + 1)->first();
            if ($rankBonus) {
                $bonus = $rankBonus->percent;
            }

            $winAmount = $gameLog->invest + ($gameLog->invest * $bonus / 100);

            $gameLog->win_amo    = $winAmount;
            $gameLog->win_status = Status::WIN;
            $gameLog->status     = Status::GAME_FINISHED;
            $gameLog->save();

            $user = $gameLog->user;
            $user->balance += $winAmount;
            $user->save();

            $transaction               = new Transaction();
            $transaction->user_id      = $user->id;
            $transaction->amount       = $winAmount;
            $transaction->charge       = 0;
            $transaction->trx_type     = '+';
            $transaction->details      = 'Win bonus of ' . $game->name;
            $transaction->remark       = 'Win_Bonus';
            $transaction->trx          = getTrx();
            $transaction->post_balance = $user->balance;
            $transaction->save();

            $res['message'] = 'Yahoo! You Win!!!';
            $res['type']    = 'success';
            $res['balance'] = showAmount($user->balance, currencyFormat: false);
            $res['sound']   = getImage('assets/audio/win.wav');
        }
        $res['rank']   = str_replace("_", " ", $rank);
        $res['result'] = array_slice(json_decode($gameLog->result), 3, 5);
        $res['path']   = asset(activeTemplate(true) . '/images/cards/');
        return response()->json($res);
    }

    public function pokerFold(Request $request) {
        $validator = Validator::make($request->all(), [
            'game_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()]);
        }

        $gameLog = $this->runningGame();

        if (!$gameLog) {
            return response()->json(['error' => 'Game Logs not found']);
        }

        $gameLog->status = Status::GAME_FINISHED;
        $gameLog->save();

        $res['message'] = 'Oops! You Lost!!';
        $res['type']    = 'danger';
        $res['sound']   = getImage('assets/audio/lose.wav');
        $res['rank']    = 'no rank';
        return response()->json($res);
    }
}
