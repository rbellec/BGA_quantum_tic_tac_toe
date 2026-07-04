/**
 * Quantum Tic Tac Toe — BGA client
 *
 * Based on "Quantum Tic-Tac-Toe: A teaching metaphor for superposition in quantum mechanics"
 * by Allan Goff (Novatia Labs), American Journal of Physics, Vol. 74, No. 11, November 2006.
 *
 * BGA implementation: © GoOn — BoardGameArena Studio
 */

// ─── State: PlayerTurn ────────────────────────────────────────────────────────

class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        this._selectedSquare = null; // index of first-clicked square (0-8)
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(
            isCurrentPlayerActive
                ? _('${you} must choose two squares to place your spooky mark')
                : _('${actplayer} is choosing two squares')
        );
        this._selectedSquare = null;
        this.game.clearHighlights();

        if (isCurrentPlayerActive) {
            this.game.enableSquareClicks(args.available_squares, (sq) => this._onSquareClick(sq));
        }
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearHighlights();
        this.game.disableSquareClicks();
        this._selectedSquare = null;
    }

    _onSquareClick(sq) {
        if (this._selectedSquare === null) {
            // First click: highlight the square
            this._selectedSquare = sq;
            document.getElementById(`qttt-square-${sq}`).classList.add('qttt-selected');
        } else {
            if (this._selectedSquare === sq) {
                // Deselect
                document.getElementById(`qttt-square-${sq}`).classList.remove('qttt-selected');
                this._selectedSquare = null;
                return;
            }
            // Second click: send action
            const sq1 = this._selectedSquare;
            const sq2 = sq;
            this._selectedSquare = null;
            this.game.clearHighlights();
            this.game.disableSquareClicks();

            this.bga.actions.performAction('actPlaceSpookyMarks', { sq1, sq2 });
        }
    }
}

// ─── State: CollapseChoice ───────────────────────────────────────────────────

class CollapseChoice {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(
            isCurrentPlayerActive
                ? _('${you} must choose how to collapse the entanglement')
                : _('${actplayer} is choosing the collapse direction')
        );

        if (isCurrentPlayerActive) {
            const labels = args.labels || {};
            this.bga.statusBar.addActionButton(
                _('Direction A') + (labels.A ? ` (${labels.A})` : ''),
                () => this.bga.actions.performAction('actChooseCollapse', { direction: 'A' }),
                { color: 'blue' }
            );
            this.bga.statusBar.addActionButton(
                _('Direction B') + (labels.B ? ` (${labels.B})` : ''),
                () => this.bga.actions.performAction('actChooseCollapse', { direction: 'B' }),
                { color: 'red' }
            );
        }
    }

    onLeavingState(args, isCurrentPlayerActive) {}
}

// ─── Main Game class ──────────────────────────────────────────────────────────

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.gamedatas = null;
        this._squareClickHandlers = {}; // sq => handler fn

        // Register state handlers
        this.playerTurn = new PlayerTurn(this, bga);
        this.collapseChoice = new CollapseChoice(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);
        this.bga.states.register('CollapseChoice', this.collapseChoice);
    }

    // ── Setup ────────────────────────────────────────────────────────────────

    setup(gamedatas) {
        console.log('Quantum Tic Tac Toe — setup', gamedatas);
        this.gamedatas = gamedatas;

        // Build board HTML
        const area = this.bga.gameArea.getElement();
        area.insertAdjacentHTML('beforeend', this._buildBoardHTML());

        // Render initial state
        this.renderBoard(gamedatas.board, gamedatas.moves);

        this.setupNotifications();
        console.log('Quantum Tic Tac Toe — setup complete');
    }

    // ── Board HTML ────────────────────────────────────────────────────────────

    _buildBoardHTML() {
        let cells = '';
        for (let i = 0; i < 9; i++) {
            cells += `<div class="qttt-square" id="qttt-square-${i}" data-sq="${i}"></div>`;
        }
        return `
            <div id="qttt-board">
                ${cells}
            </div>
            <div id="qttt-attribution">
                Based on <em>Quantum Tic-Tac-Toe</em> by Allan Goff
                (American Journal of Physics, 2006)
            </div>
        `;
    }

    // ── Board rendering ───────────────────────────────────────────────────────

    renderBoard(board, moves) {
        // Clear all squares
        for (let i = 0; i < 9; i++) {
            const el = document.getElementById(`qttt-square-${i}`);
            if (el) {
                el.innerHTML = '';
                el.className = 'qttt-square';
            }
        }

        // Index board by square_id
        const boardMap = {};
        for (const sq of Object.values(board)) {
            boardMap[sq.square_id] = sq;
        }

        // Render classical marks
        for (const [sqId, sq] of Object.entries(boardMap)) {
            if (sq.classical_player_id !== null) {
                const el = document.getElementById(`qttt-square-${sqId}`);
                if (!el) continue;
                const sym = sq.classical_symbol;
                const mn  = sq.classical_move_number;
                el.classList.add('qttt-classical', sym === 'X' ? 'qttt-x' : 'qttt-o');
                el.innerHTML = `<span class="qttt-mark-classical">${sym}<sub>${mn}</sub></span>`;
            }
        }

        // Render spooky marks (group by square)
        const spookyBySq = {}; // sq_id => [{symbol, move_number, partner}]
        for (const move of Object.values(moves)) {
            if (move.collapsed_to !== null) continue; // already classical
            const s1 = parseInt(move.square1, 10);
            const s2 = parseInt(move.square2, 10);
            const entry1 = { symbol: move.symbol, move_number: move.move_number, partner: s2 };
            const entry2 = { symbol: move.symbol, move_number: move.move_number, partner: s1 };
            if (!spookyBySq[s1]) spookyBySq[s1] = [];
            if (!spookyBySq[s2]) spookyBySq[s2] = [];
            spookyBySq[s1].push(entry1);
            spookyBySq[s2].push(entry2);
        }

        for (const [sqId, marks] of Object.entries(spookyBySq)) {
            // Skip if square has a classical mark
            if (boardMap[sqId] && boardMap[sqId].classical_player_id !== null) continue;
            const el = document.getElementById(`qttt-square-${sqId}`);
            if (!el) continue;
            el.classList.add('qttt-spooky');
            const marksHtml = marks
                .sort((a, b) => a.move_number - b.move_number)
                .map(m => `<span class="qttt-mark-spooky qttt-${m.symbol.toLowerCase()}">${m.symbol}<sub>${m.move_number}</sub></span>`)
                .join('');
            el.innerHTML = `<div class="qttt-spooky-marks">${marksHtml}</div>`;
        }
    }

    // ── Square interaction ────────────────────────────────────────────────────

    enableSquareClicks(availableSquares, callback) {
        this.disableSquareClicks();
        for (const sq of availableSquares) {
            const el = document.getElementById(`qttt-square-${sq}`);
            if (!el) continue;
            el.classList.add('qttt-available');
            const handler = () => callback(sq);
            this._squareClickHandlers[sq] = handler;
            el.addEventListener('click', handler);
        }
    }

    disableSquareClicks() {
        for (const [sq, handler] of Object.entries(this._squareClickHandlers)) {
            const el = document.getElementById(`qttt-square-${sq}`);
            if (el) {
                el.removeEventListener('click', handler);
                el.classList.remove('qttt-available');
            }
        }
        this._squareClickHandlers = {};
    }

    clearHighlights() {
        for (let i = 0; i < 9; i++) {
            const el = document.getElementById(`qttt-square-${i}`);
            if (el) el.classList.remove('qttt-selected', 'qttt-available');
        }
    }

    // ── Notifications ─────────────────────────────────────────────────────────

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications({});
    }

    async notif_spookyPlaced(args) {
        this.gamedatas.board  = args.board;
        this.gamedatas.moves  = args.moves;
        this.renderBoard(args.board, args.moves);
    }

    async notif_boardCollapsed(args) {
        this.gamedatas.board = args.board;
        this.gamedatas.moves = args.moves;
        this.renderBoard(args.board, args.moves);
    }

    async notif_lastMarkPlaced(args) {
        this.gamedatas.board = args.board;
        this.gamedatas.moves = args.moves;
        this.renderBoard(args.board, args.moves);
    }

    async notif_cycleDetected(args) {
        // Highlight the cycle path squares briefly
        const path = args.cycle_path || [];
        for (const sq of path) {
            const el = document.getElementById(`qttt-square-${sq}`);
            if (el) el.classList.add('qttt-cycle');
        }
        await new Promise(r => setTimeout(r, 800));
        for (const sq of path) {
            const el = document.getElementById(`qttt-square-${sq}`);
            if (el) el.classList.remove('qttt-cycle');
        }
    }

    async notif_gameResult(args) {
        // BGA framework handles game end display
    }
}
