# Quantum Tic Tac Toe — BGA Implementation

Implementation of **Quantum Tic-Tac-Toe** on [Board Game Arena Studio](https://studio.boardgamearena.com), using the new BGA framework (2025+).

> Based on *Quantum Tic-Tac-Toe: A teaching metaphor for superposition in quantum mechanics*  
> Allan Goff, Novatia Labs — American Journal of Physics, Vol. 74, No. 11, November 2006  
> https://doi.org/10.1119/1.2213635

BGA game page: https://boardgamegeek.com/boardgame/171143/quantum-tic-tac-toe

---

## Rules (Allan Goff simplified)

1. **Placement** — each turn, the active player places two *spooky marks* (X₁ or O₂, etc.) in two different squares that have no classical mark.
2. **Entanglement** — each move creates an edge in an entanglement graph (squares = nodes, moves = edges).
3. **Cycle** — after each move, if a cycle is created in the graph, the *opponent* chooses the collapse direction (2 options).
4. **Collapse** — the cycle collapses deterministically: each move in the cycle becomes a classical mark on one square. Cascade continues until stable.
5. **Victory** — first player to have three classical marks in a line (row, column, diagonal). If both players complete a line simultaneously (possible after a collapse), the player whose line has the **lowest maximum subscript** wins (no half-point split).
6. **Draw** — board full with no winner.

---

## Project structure

```
quantictactoe/
├── Makefile                        — check (PHP lint) + deploy (SCP)
├── gameinfos.inc.php               — game metadata (name, BGG id, colors)
├── dbmodel.sql                     — custom MySQL tables
├── stats.json                      — game statistics
├── gameoptions.json                — game options
├── gamepreferences.json            — player preferences
├── quantictactoe.css               — board and marks styles
├── modules/
│   ├── php/
│   │   ├── Game.php                — main class: graph logic, collapse, victory
│   │   ├── material.inc.php        — static data (empty, included auto by BGA)
│   │   └── States/
│   │       ├── PlayerTurn.php      — state 20: place 2 spooky marks
│   │       ├── CollapseChoice.php  — state 30: choose collapse direction
│   │       ├── CheckVictory.php    — state 40: auto check win/draw
│   │       └── ComputeScores.php   — state 98: record scores before gameEnd
│   └── js/
│       └── Game.js                 — ES6 client: board render + actions
└── img/
    └── board.svg                   — 3×3 grid SVG
```

---

## Database schema

```sql
-- 9 squares of the board (0–8, row-major)
CREATE TABLE IF NOT EXISTS `board` (
  `square_id`             INT(2)  NOT NULL,
  `classical_player_id`   INT(10) DEFAULT NULL,
  `classical_move_number` INT(3)  DEFAULT NULL,
  `classical_symbol`      CHAR(1) DEFAULT NULL,
  PRIMARY KEY (`square_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each quantum move = one edge in the entanglement graph
-- Named q_moves (not moves) to avoid conflict with BGA internal tables
CREATE TABLE IF NOT EXISTS `q_moves` (
  `move_number`  INT(3)  NOT NULL,
  `player_id`    INT(10) NOT NULL,
  `square1`      INT(2)  NOT NULL,
  `square2`      INT(2)  NOT NULL,
  `collapsed_to` INT(2)  DEFAULT NULL,
  PRIMARY KEY (`move_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Key design decisions:**
- Symbol (X or O) is derived from `move_number` parity (odd = X, even = O), not stored.
- Table named `q_moves` not `moves` — BGA has an internal `moves` table with a different schema.
- No inline `--` comments inside `CREATE TABLE` — BGA strips newlines before executing SQL, turning inline comments into end-of-line comments that truncate the rest of the statement.

---

## State machine

| ID | Class | Type | Role |
|----|-------|------|------|
| 1 | — | — | gameSetup (reserved) |
| 20 | `PlayerTurn` | ACTIVE_PLAYER | Place 2 spooky marks |
| 30 | `CollapseChoice` | ACTIVE_PLAYER | Choose collapse direction |
| 40 | `CheckVictory` | GAME | Auto check win/draw |
| 98 | `ComputeScores` | GAME | Record scores before gameEnd |
| 99 | — | — | gameEnd (reserved) |

Transitions:
- `PlayerTurn` → `CollapseChoice` (cycle created) or `CheckVictory`
- `CollapseChoice` → `CheckVictory`
- `CheckVictory` → `PlayerTurn` (game continues) or `ComputeScores` (win/draw)
- `ComputeScores` → 99

---

## Entanglement graph and cycle detection

### Graph representation

Each uncollapsed move is an edge. `Game::buildGraph()` builds an adjacency list from `q_moves WHERE collapsed_to IS NULL`.

```php
// IMPORTANT: select move_number FIRST so getCollectionFromDb() uses it as key.
// If two moves share the same square1, selecting square1 first would silently
// drop one row (getCollectionFromDb uses the first column as array key).
$rows = $this->getCollectionFromDb(
    "SELECT move_number, square1, square2 FROM q_moves WHERE collapsed_to IS NULL"
);
```

### Cycle detection

Before inserting a new move (sq1, sq2), check if sq1 and sq2 are already connected in the existing graph via DFS (`Game::findPath`). If a path exists, a cycle would be created.

### Computing collapse options

Given the cycle path `[sq1, p1, ..., pN, sq2]` and the new move number:

- **Option A** — new move collapses to `sq1`; each subsequent edge collapses to the next node in path.
- **Option B** — new move collapses to `sq2`; each edge collapses backwards.

### Cascade collapse

After the initial collapse, any uncollapsed move whose one square just became classical is forced to collapse to the other square. Repeat until stable (`Game::cascadeCollapse`).

---

## Bugs encountered and fixed

### 1. Inline SQL comments truncating schema

**Symptom:** `Unknown column 'square1' in 'field list'` — table created with only 2 columns.

**Cause:** BGA strips newlines from `dbmodel.sql` before executing SQL. Inline `-- comment` after a column definition becomes an end-of-line comment that eats all subsequent columns:

```sql
-- WRONG: after newline stripping this becomes one long comment
CREATE TABLE `q_moves` (
  `move_number` INT(3) NOT NULL, -- the move number
  `player_id`   INT(10) NOT NULL, -- truncated here!
  `square1`     INT(2) NOT NULL,
  ...
```

**Fix:** Remove all inline `--` comments from inside `CREATE TABLE`. Only keep block comments before the statement.

### 2. Table name conflict with BGA internals

**Symptom:** Wrong column set when querying `moves`.

**Cause:** BGA has an internal `moves` table with a different schema. `CREATE TABLE IF NOT EXISTS` silently succeeds but the table already exists with wrong columns.

**Fix:** Rename to `q_moves`.

### 3. Cycle detection failing when two moves share square1

**Symptom:** Cycle not detected even when mathematically present (4 moves forming a closed loop).

**Cause:** `getCollectionFromDb("SELECT square1, square2 FROM q_moves ...")` uses `square1` as the array key. Two moves with the same `square1` (e.g., moves 0↔4 and 0↔8) silently overwrite each other, making the graph incomplete.

**Fix:** Add `move_number` as the first selected column so each row has a unique key:
```sql
SELECT move_number, square1, square2 FROM q_moves WHERE collapsed_to IS NULL
```

---

## BGA framework notes (new 2025+ framework)

- `getCollectionFromDb()` uses the **first selected column** as the PHP array key — always put a unique column first.
- No inline `--` comments inside `CREATE TABLE` in `dbmodel.sql`.
- Never name a custom table `moves`, `player`, `global`, `stats`, or `gamelog` — these conflict with BGA internals.
- State constructor takes only `id` and `type` — no name, description, or transitions.
- Act methods return the **next state class** (e.g., `return CollapseChoice::class`).
- `initGameStateLabels` goes in `initTable()`, not the constructor.
- Use `$this->bga->globals->set/get()` not `setGameStateValue/getGameStateValue`.
- Never override `argGameEnd()` or `stGameEnd()` (both are `final`). Use a state 98 to compute scores.

---

## Deploy

```bash
# PHP lint + deploy via SCP
make deploy
```

Requires `~/.ssh/id_rsa` configured for BGA Studio SFTP (port 2022).

---

## Attribution

*Quantum Tic-Tac-Toe* was invented by Allan Goff (Novatia Labs) and published in:

> Goff, A. (2006). Quantum tic-tac-toe: A teaching metaphor for superposition in quantum mechanics. *American Journal of Physics*, 74(11), 962–973. https://doi.org/10.1119/1.2213635

This BGA implementation is a faithful adaptation of the published rules, made for educational and non-commercial purposes with full attribution to the original author.
