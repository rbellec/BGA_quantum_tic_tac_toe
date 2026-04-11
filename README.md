# Quantum Tic Tac Toe — BGA Implementation

> **Context** — This implementation was produced as a test of [Claude Code](https://claude.ai/claude-code): implementing a board game on BGA Studio entirely with AI assistance, from the published rules to a working first test game, without any manual code writing. The method, skill file, and workflow used for this experiment are documented in [claude-code-bga](https://github.com/rbellec/claude-code-bga).

---

**Based on:** *Quantum Tic-Tac-Toe: A teaching metaphor for superposition in quantum mechanics*  
Allan Goff, Novatia Labs — American Journal of Physics, Vol. 74, No. 11, November 2006  
https://doi.org/10.1119/1.2213635

**BoardGameGeek:** https://boardgamegeek.com/boardgame/171143/quantum-tic-tac-toe

---

## Scope of this implementation

For this test, we implemented a **simplified variant** of Allan Goff's rules, focused on getting a fully playable game running end-to-end as quickly as possible:

- Simultaneous wins resolve by **lowest maximum subscript wins** (no half-point split as in the original paper)
- No tutorial or player-guidance overlay
- Minimal UI for collapse choice (text labels only, no board highlighting)
- 2-player only, no AI opponent

The goal was to validate the complete BGA framework integration — game setup, state machine, quantum move logic, cycle detection, collapse cascade, and victory detection — in a single Claude Code session.

See the [TODO](#todo) section for planned improvements.

---

## Rules (Allan Goff, simplified variant)

1. **Placement** — each turn, the active player places two *spooky marks* (X₁ or O₂, etc.) in two different squares that have no classical mark.
2. **Entanglement** — each move creates an edge in an entanglement graph (squares = nodes, moves = edges).
3. **Cycle** — after each move, if a cycle is created in the graph, the *opponent* chooses the collapse direction (2 options).
4. **Collapse** — the cycle collapses deterministically: each move in the cycle becomes a classical mark on one square. Cascade continues until stable.
5. **Victory** — first player to have three classical marks in a line (row, column, diagonal). If both players complete a line simultaneously (possible after a collapse), the player whose line has the **lowest maximum subscript** wins.
6. **Draw** — board full with no winner.

---

## License

The game concept and rules of *Quantum Tic-Tac-Toe* are the intellectual property of Allan Goff, published in the American Journal of Physics (2006). The paper is available through the AIP/AJP under standard academic licensing.

Board game rules are generally not copyrightable (in most jurisdictions, including the US), so adapting and implementing the rules is permitted. However, this implementation:

- Credits Allan Goff prominently as the inventor
- Is non-commercial (BGA Studio, free tier)
- Makes no claim of ownership over the game concept
- Links to the original published work

This code (the BGA implementation itself) is released under the **MIT License**.

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
│   │   ├── material.inc.php        — static data (empty, auto-included by BGA)
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

CREATE TABLE IF NOT EXISTS `q_moves` (
  `move_number`  INT(3)  NOT NULL,
  `player_id`    INT(10) NOT NULL,
  `square1`      INT(2)  NOT NULL,
  `square2`      INT(2)  NOT NULL,
  `collapsed_to` INT(2)  DEFAULT NULL,
  PRIMARY KEY (`move_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Key design decisions:
- Symbol (X or O) derived from `move_number` parity (odd = X, even = O) — not stored
- Named `q_moves` not `moves` — BGA has an internal `moves` table with a different schema
- No SQL comments inside `CREATE TABLE` — BGA strips newlines before executing, turning any `--` comment into an end-of-line comment that silently truncates all subsequent columns

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
- `CheckVictory` → `PlayerTurn` (continues) or `ComputeScores` (win/draw)
- `ComputeScores` → 99

---

## Entanglement graph and cycle detection

### Graph

Each uncollapsed move is an edge. `buildGraph()` returns an adjacency list from `q_moves WHERE collapsed_to IS NULL`.

> **BGA pitfall:** `getCollectionFromDb()` uses the **first selected column** as the PHP array key. Two moves sharing the same `square1` would silently overwrite each other, breaking the graph. Fix: always put `move_number` first so each row has a unique key.
> ```sql
> SELECT move_number, square1, square2 FROM q_moves WHERE collapsed_to IS NULL
> ```

### Cycle detection

Before inserting a new move `(sq1, sq2)`, check if `sq1` and `sq2` are already connected in the graph via DFS. If yes, a cycle would be created → transition to `CollapseChoice`.

### Collapse options

Given cycle path `[sq1, p1, …, pN, sq2]` and the new move number:
- **Option A** — new move collapses to `sq1`; each edge collapses forward along the path
- **Option B** — new move collapses to `sq2`; each edge collapses backward

### Cascade

After the chosen collapse, any uncollapsed move with one square now classical is forced to collapse to the other square. Repeat until stable.

---

## Bugs found during development

Three bugs were encountered and fixed during the BGA Studio test session:

### 1. Inline SQL comments truncating the schema

`dbmodel.sql` is executed by BGA with newlines stripped. Inline `--` comments eat all subsequent columns. Fixed by removing all comments from inside `CREATE TABLE`.

### 2. Table name conflict (`moves`)

BGA creates an internal `moves` table. `CREATE TABLE IF NOT EXISTS` silently succeeds on the wrong table. Fixed by renaming to `q_moves`.

### 3. `getCollectionFromDb` dropping duplicate `square1` rows

`SELECT square1, square2 FROM q_moves` used `square1` as array key. Two moves with the same `square1` overwrote each other, making the cycle-detection graph incomplete. Fixed by selecting `move_number` first.

---

## Deploy

```bash
make deploy   # PHP lint + SCP to BGA Studio
```

Requires `~/.ssh/id_rsa` configured for BGA Studio SFTP (port 2022).

---

## TODO

Planned improvements beyond this alpha / proof-of-concept:

- **Full rules variant** — implement the original half-point scoring for simultaneous wins (winner gets 1 pt, loser gets 0.5 pt), as described in the Goff paper
- **Better collapse UI** — highlight the cycle path on the board, show which squares each option would fill, let the player preview before confirming
- **BGA Tutorial** — integrate BGA's built-in tutorial system to guide new players through the quantum mechanics concept step by step
- **Move animation** — animate the collapse cascade visually (marks appearing one by one) rather than updating the board atomically
- **Accessibility** — color-blind friendly symbols, keyboard navigation for square selection

---

## Attribution

*Quantum Tic-Tac-Toe* was invented by Allan Goff (Novatia Labs) and published in:

> Goff, A. (2006). Quantum tic-tac-toe: A teaching metaphor for superposition in quantum mechanics. *American Journal of Physics*, 74(11), 962–973. https://doi.org/10.1119/1.2213635
