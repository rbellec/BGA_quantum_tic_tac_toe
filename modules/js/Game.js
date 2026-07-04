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

        const options = args.options || {};

        // Soft-highlight the cycle squares for everyone while the choice is pending
        // (the union of both options' target squares is exactly the cycle)
        const cycleSquares = new Set([
            ...Object.values(options.A || {}),
            ...Object.values(options.B || {}),
        ]);
        for (const sq of cycleSquares) {
            document.getElementById(`qttt-square-${sq}`)?.classList.add('qttt-cycle-soft');
        }

        if (isCurrentPlayerActive) {
            const labels = args.labels || {};
            for (const dir of ['A', 'B']) {
                this.bga.statusBar.addActionButton(
                    dir === 'A' ? _('Collapse A') : _('Collapse B'),
                    () => this.bga.actions.performAction('actChooseCollapse', { direction: dir }),
                    { color: dir === 'A' ? 'primary' : 'secondary', id: `qttt-btn-collapse-${dir}` }
                );
                const btn = document.getElementById(`qttt-btn-collapse-${dir}`);
                if (btn && options[dir]) {
                    if (labels[dir]) btn.title = labels[dir];
                    btn.addEventListener('mouseenter', () => this.game.showCollapsePreview(options[dir]));
                    btn.addEventListener('mouseleave', () => this.game.clearCollapsePreview());
                }
            }
        }
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearCollapsePreview();
        for (const el of document.querySelectorAll('.qttt-cycle-soft')) {
            el.classList.remove('qttt-cycle-soft');
        }
    }
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

        // Hovering a spooky mark highlights its entanglement link + partner mark
        const board = document.getElementById('qttt-board');
        board.addEventListener('mouseover', (e) => {
            const mark = e.target.closest('.qttt-mark-spooky');
            if (mark) this._setLinkHighlight(mark.dataset.move, true);
        });
        board.addEventListener('mouseout', (e) => {
            const mark = e.target.closest('.qttt-mark-spooky');
            if (mark) this._setLinkHighlight(mark.dataset.move, false);
        });

        // Link positions depend on rendered cell size
        window.addEventListener('resize', () => {
            if (this.gamedatas) this._drawEntanglementLinks(this.gamedatas.moves);
        });

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
            <div id="qttt-board-wrap">
                <div id="qttt-board">
                    ${cells}
                </div>
                <svg id="qttt-links" aria-hidden="true"></svg>
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
                .map(m => `<span class="qttt-mark-spooky qttt-${m.symbol.toLowerCase()}" data-move="${m.move_number}">${m.symbol}<sub>${m.move_number}</sub></span>`)
                .join('');
            el.innerHTML = `<div class="qttt-spooky-marks">${marksHtml}</div>`;
        }

        this._drawEntanglementLinks(moves);
    }

    // ── Entanglement links ────────────────────────────────────────────────────

    /**
     * Draw one curved SVG link per uncollapsed move, connecting its two squares.
     * Parallel links between the same pair of squares fan out with distinct bows.
     */
    _drawEntanglementLinks(moves) {
        const svg  = document.getElementById('qttt-links');
        const wrap = document.getElementById('qttt-board-wrap');
        if (!svg || !wrap) return;

        const wrapRect = wrap.getBoundingClientRect();
        svg.setAttribute('viewBox', `0 0 ${wrapRect.width} ${wrapRect.height}`);
        svg.innerHTML = '';

        const center = (sq) => {
            const r = document.getElementById(`qttt-square-${sq}`).getBoundingClientRect();
            return {
                x: r.left - wrapRect.left + r.width / 2,
                y: r.top  - wrapRect.top  + r.height / 2,
            };
        };

        // Group uncollapsed moves by square pair so parallel links get distinct bows
        const groups = {};
        for (const move of Object.values(moves)) {
            if (move.collapsed_to !== null) continue;
            const s1 = parseInt(move.square1, 10);
            const s2 = parseInt(move.square2, 10);
            const key = `${Math.min(s1, s2)}_${Math.max(s1, s2)}`;
            if (!groups[key]) groups[key] = [];
            groups[key].push({ move_number: move.move_number, symbol: move.symbol, s1, s2 });
        }

        for (const group of Object.values(groups)) {
            group.forEach((move, i) => {
                const a = center(move.s1);
                const b = center(move.s2);
                // Bow each link perpendicular to the segment; siblings fan out
                const dx = b.x - a.x, dy = b.y - a.y;
                const len = Math.hypot(dx, dy) || 1;
                const nx = -dy / len, ny = dx / len;
                const bow = 16 + (i - (group.length - 1) / 2) * 26;
                const cx = (a.x + b.x) / 2 + nx * bow;
                const cy = (a.y + b.y) / 2 + ny * bow;

                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', `M ${a.x} ${a.y} Q ${cx} ${cy} ${b.x} ${b.y}`);
                path.classList.add('qttt-link', `qttt-link-${move.symbol.toLowerCase()}`);
                path.dataset.move = move.move_number;
                svg.appendChild(path);
            });
        }
    }

    // ── Collapse preview ──────────────────────────────────────────────────────

    /**
     * Show ghost classical marks for one collapse option: each entry of
     * `collapses` ({move_number: target_square}) becomes a translucent mark
     * overlaid on its target square, dimming the entanglement links.
     */
    showCollapsePreview(collapses) {
        this.clearCollapsePreview();
        for (const [moveNum, sq] of Object.entries(collapses)) {
            const el = document.getElementById(`qttt-square-${sq}`);
            if (!el) continue;
            const sym = (parseInt(moveNum, 10) % 2 === 1) ? 'X' : 'O';
            const ghost = document.createElement('div');
            ghost.className = `qttt-ghost qttt-${sym.toLowerCase()}`;
            ghost.innerHTML = `${sym}<sub>${moveNum}</sub>`;
            el.appendChild(ghost);
        }
        document.getElementById('qttt-board-wrap')?.classList.add('qttt-preview-active');
    }

    clearCollapsePreview() {
        for (const ghost of document.querySelectorAll('.qttt-ghost')) ghost.remove();
        document.getElementById('qttt-board-wrap')?.classList.remove('qttt-preview-active');
    }

    _setLinkHighlight(moveNum, on) {
        const method = on ? 'add' : 'remove';
        const path = document.querySelector(`#qttt-links path[data-move="${moveNum}"]`);
        if (path) path.classList[method]('qttt-link-hot');
        for (const mark of document.querySelectorAll(`.qttt-mark-spooky[data-move="${moveNum}"]`)) {
            mark.classList[method]('qttt-mark-hot');
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
        const oldBoard = this.gamedatas.board;
        const oldMoves = this.gamedatas.moves;
        this.gamedatas.board = args.board;
        this.gamedatas.moves = args.moves;

        const sequence = args.collapse_sequence || [];
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const animActive = this.bga.gameui?.bgaAnimationsActive
            ? this.bga.gameui.bgaAnimationsActive()
            : true;

        if (!sequence.length || reducedMotion || !animActive) {
            this.renderBoard(args.board, args.moves);
            return;
        }

        // Replay the collapse from the pre-collapse snapshot, freezing one move
        // per step so the cascade is readable.
        const board = JSON.parse(JSON.stringify(oldBoard));
        const moves = JSON.parse(JSON.stringify(oldMoves));
        const boardBySq = {};
        for (const sq of Object.values(board)) {
            boardBySq[parseInt(sq.square_id, 10)] = sq;
        }

        for (const step of sequence) {
            const move = Object.values(moves).find(m => parseInt(m.move_number, 10) === step.move);
            if (move) move.collapsed_to = step.square;
            const sq = boardBySq[step.square];
            if (sq && sq.classical_player_id === null) {
                sq.classical_player_id   = move ? move.player_id : '0';
                sq.classical_move_number = String(step.move);
                sq.classical_symbol      = (step.move % 2 === 1) ? 'X' : 'O';
            }
            this.renderBoard(board, moves);
            document.getElementById(`qttt-square-${step.square}`)
                ?.querySelector('.qttt-mark-classical')
                ?.classList.add('qttt-materialize');
            await new Promise(r => setTimeout(r, 450));
        }

        // Final authoritative render
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
