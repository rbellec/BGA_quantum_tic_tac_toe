<?php
/**
 * Quantum Tic Tac Toe — BGA implementation
 *
 * Based on "Quantum Tic-Tac-Toe: A teaching metaphor for superposition in quantum mechanics"
 * by Allan Goff (Novatia Labs), American Journal of Physics, Vol. 74, No. 11, November 2006.
 *
 * BGA implementation: © GoOn — BoardGameArena Studio
 */
declare(strict_types=1);

namespace Bga\Games\QuanticTacToe;

use Bga\Games\QuanticTacToe\States\PlayerTurn;
use Bga\Games\QuanticTacToe\States\ComputeScores;

class Game extends \Bga\GameFramework\Table
{
    // Win lines (square indices 0-8, row-major)
    private const WIN_LINES = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8], // rows
        [0, 3, 6], [1, 4, 7], [2, 5, 8], // cols
        [0, 4, 8], [2, 4, 6],             // diags
    ];

    // =========================================================
    // BGA framework entry points
    // =========================================================

    protected function setupNewGame($players, $options = [])
    {
        // Insert players with fixed colors: first = red (X), second = blue (O)
        $colors = ['ff0000', '0000ff'];
        $queryValues = [];

        foreach ($players as $pid => $player) {
            $color = array_shift($colors);
            $queryValues[] = vsprintf("(%s, '%s', '%s')", [
                $pid,
                $color,
                addslashes($player['player_name']),
            ]);
        }

        $this->DbQuery(sprintf(
            "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
            implode(',', $queryValues)
        ));
        $this->reloadPlayersBasicInfos();

        // Initialize board (9 empty squares)
        for ($sq = 0; $sq < 9; $sq++) {
            $this->DbQuery("INSERT INTO board (square_id) VALUES ({$sq})");
        }

        // Initialize globals
        $this->bga->globals->set('move_number', 0);
        $this->bga->globals->set('collapse_options', null);
        $this->bga->globals->set('collapse_cycle_path', null);

        // Stats
        $this->bga->tableStats->init('turns_number', 0);

        $this->activeNextPlayer();
        return PlayerTurn::class;
    }

    public function getAllDatas(int $currentPlayerId): array
    {
        $board = $this->getCollectionFromDb(
            "SELECT square_id, classical_player_id, classical_move_number, classical_symbol FROM board"
        );
        $moves = $this->getMovesData(); // symbol derived from move_number parity
        $players = $this->getCollectionFromDb(
            "SELECT player_id AS id, player_score AS score, player_color AS color, player_name AS name FROM player"
        );

        return [
            'board'   => $board,
            'moves'   => $moves,
            'players' => $players,
        ];
    }

    public function getGameProgression(): int
    {
        $classical = (int)$this->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM board WHERE classical_player_id IS NOT NULL"
        );
        return (int)($classical / 9 * 100);
    }

    // =========================================================
    // Graph helpers (used by state classes)
    // =========================================================

    /**
     * Build adjacency graph from all uncollapsed moves currently in DB.
     * Returns array[square_id] => array of neighboring square_ids.
     */
    public function buildGraph(): array
    {
        $graph = array_fill(0, 9, []);
        $rows = $this->getCollectionFromDb(
            "SELECT move_number, square1, square2 FROM q_moves WHERE collapsed_to IS NULL"
        );
        foreach ($rows as $row) {
            $s1 = (int)$row['square1'];
            $s2 = (int)$row['square2'];
            $graph[$s1][] = $s2;
            $graph[$s2][] = $s1;
        }
        return $graph;
    }

    /**
     * DFS path search. Returns array [start, ..., end] or null if unreachable.
     */
    public function findPath(array $graph, int $start, int $end, array $visited): ?array
    {
        if ($start === $end) {
            return [$end];
        }
        $visited[$start] = true;
        foreach ($graph[$start] as $neighbor) {
            if (!isset($visited[$neighbor])) {
                $sub = $this->findPath($graph, $neighbor, $end, $visited);
                if ($sub !== null) {
                    return array_merge([$start], $sub);
                }
            }
        }
        return null;
    }

    /**
     * Check if adding edge (sq1, sq2) to the CURRENT graph creates a cycle.
     * Call this BEFORE inserting the new move into the DB.
     * Returns the cycle path [sq1, ..., sq2] if cycle detected, else null.
     */
    public function detectCycle(int $sq1, int $sq2): ?array
    {
        $graph = $this->buildGraph();
        return $this->findPath($graph, $sq1, $sq2, []);
    }

    /**
     * Compute the two possible collapse options for a cycle.
     *
     * @param array $path  Cycle path [sq1=p0, p1, ..., pN=sq2] found in existing graph.
     * @param int $newMoveNum  Move number of the new (cycle-closing) move.
     * @return array ['A' => [move_num => square, ...], 'B' => [move_num => square, ...]]
     */
    public function computeCollapseOptions(array $path, int $newMoveNum): array
    {
        // Build edge → move_number map from existing uncollapsed moves, excluding the
        // new move itself: by the time this runs it is already in the DB, and when the
        // cycle is a direct 2-square repeat (path length 2), its edge is identical to
        // the pre-existing edge it closes — including it here would overwrite that
        // move's entry in $edgeToMove and lose its collapse mapping.
        $rows = $this->getCollectionFromDb(
            "SELECT move_number, square1, square2 FROM q_moves"
            . " WHERE collapsed_to IS NULL AND move_number != {$newMoveNum}"
        );
        $edgeToMove = [];
        foreach ($rows as $row) {
            $a = (int)$row['square1'];
            $b = (int)$row['square2'];
            $mn = (int)$row['move_number'];
            $edgeToMove["{$a}_{$b}"] = $mn;
            $edgeToMove["{$b}_{$a}"] = $mn;
        }

        $n = count($path);

        // Option A: new move collapses to path[0] (sq1)
        // Each edge [path[i], path[i+1]] collapses to path[i+1]
        $optionA = [$newMoveNum => $path[0]];
        for ($i = 0; $i < $n - 1; $i++) {
            $key = "{$path[$i]}_{$path[$i + 1]}";
            $optionA[(int)$edgeToMove[$key]] = $path[$i + 1];
        }

        // Option B: new move collapses to path[N-1] (sq2)
        // Each edge [path[i], path[i-1]] collapses to path[i-1]
        $optionB = [$newMoveNum => $path[$n - 1]];
        for ($i = $n - 1; $i > 0; $i--) {
            $key = "{$path[$i]}_{$path[$i - 1]}";
            $optionB[(int)$edgeToMove[$key]] = $path[$i - 1];
        }

        return ['A' => $optionA, 'B' => $optionB];
    }

    /** Compute symbol from move number parity: odd = X, even = O */
    public function symbolFromMoveNumber(int $moveNum): string
    {
        return ($moveNum % 2 === 1) ? 'X' : 'O';
    }

    /**
     * Execute collapse and cascade.
     * @param array $collapses  [move_number => square_to_collapse_to, ...]
     * @return array Ordered collapse sequence [['move' => n, 'square' => s], ...]
     *               (chosen-option collapses first, then cascade), for client animation.
     */
    public function performCollapse(array $collapses): array
    {
        $sequence = [];
        foreach ($collapses as $moveNum => $square) {
            $this->DbQuery("UPDATE q_moves SET collapsed_to={$square} WHERE move_number={$moveNum}");
            $move = $this->getObjectFromDB(
                "SELECT player_id FROM q_moves WHERE move_number={$moveNum}"
            );
            $pid = (int)$move['player_id'];
            $sym = $this->symbolFromMoveNumber($moveNum);
            $this->DbQuery(
                "UPDATE board SET classical_player_id={$pid}, classical_move_number={$moveNum}, classical_symbol='{$sym}'"
                . " WHERE square_id={$square} AND classical_player_id IS NULL"
            );
            $sequence[] = ['move' => (int)$moveNum, 'square' => (int)$square];
        }
        return array_merge($sequence, $this->cascadeCollapse());
    }

    /**
     * Cascade: any uncollapsed move whose one square is now classical must collapse to the other.
     * Repeat until stable.
     * @return array Ordered collapse sequence, same shape as performCollapse().
     */
    private function cascadeCollapse(): array
    {
        $sequence = [];
        $changed = true;
        while ($changed) {
            $changed = false;
            $uncollapsed = $this->getCollectionFromDb(
                "SELECT move_number, player_id, square1, square2 FROM q_moves WHERE collapsed_to IS NULL"
            );
            foreach ($uncollapsed as $row) {
                $s1 = (int)$row['square1'];
                $s2 = (int)$row['square2'];
                $mn = (int)$row['move_number'];
                $pid = (int)$row['player_id'];
                $sym = $this->symbolFromMoveNumber($mn);

                $c1 = (int)$this->getUniqueValueFromDB(
                    "SELECT COUNT(*) FROM board WHERE square_id={$s1} AND classical_player_id IS NOT NULL"
                );
                $c2 = (int)$this->getUniqueValueFromDB(
                    "SELECT COUNT(*) FROM board WHERE square_id={$s2} AND classical_player_id IS NOT NULL"
                );

                if ($c1 && !$c2) {
                    $this->DbQuery("UPDATE q_moves SET collapsed_to={$s2} WHERE move_number={$mn}");
                    $this->DbQuery(
                        "UPDATE board SET classical_player_id={$pid}, classical_move_number={$mn}, classical_symbol='{$sym}'"
                        . " WHERE square_id={$s2} AND classical_player_id IS NULL"
                    );
                    $sequence[] = ['move' => $mn, 'square' => $s2];
                    $changed = true;
                } elseif ($c2 && !$c1) {
                    $this->DbQuery("UPDATE q_moves SET collapsed_to={$s1} WHERE move_number={$mn}");
                    $this->DbQuery(
                        "UPDATE board SET classical_player_id={$pid}, classical_move_number={$mn}, classical_symbol='{$sym}'"
                        . " WHERE square_id={$s1} AND classical_player_id IS NULL"
                    );
                    $sequence[] = ['move' => $mn, 'square' => $s1];
                    $changed = true;
                }
            }
        }
        return $sequence;
    }

    /**
     * Goff rule: when a single free square remains, the next move is forced —
     * a single classical mark placed on that square by the active player.
     * Called from CheckVictory (GAME state), where the active player is the one to move.
     */
    public function placeForcedLastMark(): void
    {
        $square = (int)$this->getUniqueValueFromDB(
            "SELECT square_id FROM board WHERE classical_player_id IS NULL"
        );
        $moveNum  = $this->incrementAndGetMoveNumber();
        $playerId = (int)$this->getActivePlayerId();
        $sym      = $this->symbolFromMoveNumber($moveNum);

        $this->DbQuery(
            "INSERT INTO q_moves (move_number, player_id, square1, square2, collapsed_to)"
            . " VALUES ({$moveNum}, {$playerId}, {$square}, {$square}, {$square})"
        );
        $this->DbQuery(
            "UPDATE board SET classical_player_id={$playerId}, classical_move_number={$moveNum}, classical_symbol='{$sym}'"
            . " WHERE square_id={$square}"
        );

        $this->bga->notify->all('lastMarkPlaced', clienttranslate('${player_name} places the final mark ${symbol}${move_num} on the last free square'), [
            'player_id'   => $playerId,
            'player_name' => $this->getPlayerNameById($playerId),
            'symbol'      => $sym,
            'move_num'    => $moveNum,
            'square'      => $square,
            'board'       => $this->getBoardData(),
            'moves'       => $this->getMovesData(),
        ]);
    }

    // =========================================================
    // Victory check (used by CheckVictory state)
    // =========================================================

    /**
     * Check victory conditions.
     * Returns: player_id (int) for winner, 'draw' for full board no winner, null for game continues.
     */
    public function checkVictory(): mixed
    {
        $rows = $this->getCollectionFromDb(
            "SELECT square_id, classical_player_id, classical_move_number FROM board"
        );
        $squares = [];
        foreach ($rows as $row) {
            $squares[(int)$row['square_id']] = $row;
        }

        // Find winning lines per player: store the best (lowest max subscript) winning line
        $winners = []; // player_id => lowest max_subscript among their winning lines
        foreach (self::WIN_LINES as $line) {
            $pids = [];
            $maxSub = 0;
            foreach ($line as $sq) {
                $pid = $squares[$sq]['classical_player_id'];
                if ($pid === null) {
                    $pids = null;
                    break;
                }
                $pids[] = (int)$pid;
                $maxSub = max($maxSub, (int)$squares[$sq]['classical_move_number']);
            }
            if ($pids !== null && count(array_unique($pids)) === 1) {
                $pid = $pids[0];
                if (!isset($winners[$pid]) || $maxSub < $winners[$pid]) {
                    $winners[$pid] = $maxSub;
                }
            }
        }

        if (empty($winners)) {
            // Check draw: board full with no winner
            $empty = (int)$this->getUniqueValueFromDB(
                "SELECT COUNT(*) FROM board WHERE classical_player_id IS NULL"
            );
            return $empty === 0 ? 'draw' : null;
        }

        if (count($winners) === 1) {
            return (int)array_key_first($winners);
        }

        // Simultaneous win: player with LOWER max subscript wins
        asort($winners); // ascending — first entry = lowest subscript = winner
        return (int)array_key_first($winners);
    }

    // =========================================================
    // Helper
    // =========================================================

    public function getCurrentMoveNumber(): int
    {
        return (int)$this->bga->globals->get('move_number');
    }

    public function incrementAndGetMoveNumber(): int
    {
        $n = $this->getCurrentMoveNumber() + 1;
        $this->bga->globals->set('move_number', $n);
        return $n;
    }

    public function getBoardData(): array
    {
        return $this->getCollectionFromDb(
            "SELECT square_id, classical_player_id, classical_move_number, classical_symbol FROM board"
        );
    }

    public function getMovesData(): array
    {
        $rows = $this->getCollectionFromDb(
            "SELECT move_number, player_id, square1, square2, collapsed_to FROM q_moves"
        );
        // Compute symbol from move_number parity (odd = X, even = O)
        return array_map(function ($row) {
            $row['symbol'] = ((int)$row['move_number'] % 2 === 1) ? 'X' : 'O';
            return $row;
        }, $rows);
    }
}
