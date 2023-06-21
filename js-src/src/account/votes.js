import * as d3 from 'd3';
import 'd3-force';
import { account_votes } from '../../../locale.ini';

export default function initVotes() {
    initRankedOptions();
    initRankedPairsViz();
}

function initRankedOptions() {
    const CARD_MIME = 'text/plain';

    const methodDescription = document.querySelector('.method-description');
    const rankedOptions = document.querySelector('.ranked-options');
    if (!rankedOptions) return;
    const isTieBreaker = document.querySelector('.vote-form').dataset.isTieBreaker;
    const method = rankedOptions.dataset.type;

    methodDescription.textContent = account_votes[`vote_desc_${method}_js`];

    const options = [...rankedOptions.querySelectorAll('.ranked-option')]
        .map(option => ({
            rankInput: option.querySelector('.rank-input'),
            name: option.querySelector('.option-name').textContent,
            option,
        }));

    let renderRanks, dragStart, dragEnd;

    const cards = options.map((option, cardIndex) => {
        const card = document.createElement('div');
        card.className = 'option-card';
        const cardName = document.createElement('span');
        cardName.className = 'card-name';
        cardName.textContent = option.name;
        card.appendChild(cardName);

        option.rankInput.addEventListener('change', () => renderRanks(true));

        card.draggable = true;
        card.addEventListener('dragstart', e => {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData(CARD_MIME, cardIndex);
            dragStart();
        });
        card.addEventListener('dragend', () => dragEnd());

        return { option, rankInput: option.rankInput, node: card };
    });

    const rankDiagram = document.createElement('div');
    rankDiagram.className = 'rank-diagram';
    rankedOptions.parentNode.insertBefore(rankDiagram, rankedOptions);

    const unrankedContainer = document.createElement('div');
    unrankedContainer.className = 'unranked-remainder';
    const unrankedTitle = document.createElement('div');
    unrankedTitle.className = 'unranked-title';
    unrankedTitle.textContent = account_votes.interactive_rank_unranked;
    unrankedContainer.appendChild(unrankedTitle);
    const unrankedItems = document.createElement('div');
    unrankedItems.className = 'unranked-items';
    unrankedContainer.appendChild(unrankedItems);
    rankDiagram.appendChild(unrankedContainer);

    unrankedItems.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        unrankedContainer.classList.add('is-dragging-over');
    });
    unrankedItems.addEventListener('dragleave', e => {
        e.preventDefault();
        unrankedContainer.classList.remove('is-dragging-over');
    });
    unrankedItems.addEventListener('drop', e => {
        unrankedContainer.classList.remove('is-dragging-over');
        if (!e.dataTransfer.getData(CARD_MIME)) return;
        e.preventDefault();
        const card = cards[+e.dataTransfer.getData(CARD_MIME)];
        card.rankInput.value = '';
        renderRanks();
    });

    const createSpaceAfterRankWithCard = (index, card) => {
        for (const card of cards) {
            if (card.rankInput.value > index + 1) {
                card.rankInput.value = (+card.rankInput.value) + 1;
            }
        }
        card.rankInput.value = index + 2;
        renderRanks();
    }

    const getRankCard = index => {
        for (const card of cards) {
            if (+card.rankInput.value === index + 1) {
                return card;
            }
        }
        return null;
    };

    const ranks = [];
    const createRank = () => {
        const node = document.createElement('div');
        node.className = 'rank-container';
        const innerRank = document.createElement('div');
        innerRank.className = 'inner-rank';
        const rankLabel = document.createElement('div');
        rankLabel.className = 'rank-label';
        const cardArea = document.createElement('div');
        cardArea.className = 'card-area';
        innerRank.appendChild(rankLabel);
        innerRank.appendChild(cardArea);
        node.appendChild(innerRank);
        const spaceBetween = document.createElement('div');
        spaceBetween.className = 'space-between';
        spaceBetween.innerHTML = `<div class="space-drag-effect"></div><div class="space-add-icon"></div>`;
        node.appendChild(spaceBetween);
        rankDiagram.appendChild(node);

        let index = 0;
        const setIndex = i => {
            index = i;
            rankLabel.textContent = i + 1;
        }

        cardArea.addEventListener('dragover', e => {
            e.preventDefault();

            e.dataTransfer.dropEffect = 'move';
            innerRank.classList.add('is-dragging-over');
        });
        cardArea.addEventListener('dragleave', e => {
            e.preventDefault();
            innerRank.classList.remove('is-dragging-over');
        });
        cardArea.addEventListener('drop', e => {
            innerRank.classList.remove('is-dragging-over');
            if (!e.dataTransfer.getData(CARD_MIME)) return;
            e.preventDefault();
            const card = cards[+e.dataTransfer.getData(CARD_MIME)];

            let existingCard;
            if ((method === 'stv' || isTieBreaker) && (existingCard = getRankCard(index))) {
                // there's already a card here, replace!
                const thisValue = card.rankInput.value;
                card.rankInput.value = existingCard.rankInput.value;
                existingCard.rankInput.value = thisValue;
            } else {
                card.rankInput.value = index + 1;
            }
            renderRanks();
        });

        spaceBetween.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            spaceBetween.classList.add('is-dragging-over');
        });
        spaceBetween.addEventListener('dragleave', e => {
            e.preventDefault();
            spaceBetween.classList.remove('is-dragging-over');
        });
        spaceBetween.addEventListener('drop', e => {
            spaceBetween.classList.remove('is-dragging-over');
            if (!e.dataTransfer.getData(CARD_MIME)) return;
            e.preventDefault();
            const card = cards[+e.dataTransfer.getData(CARD_MIME)];
            createSpaceAfterRankWithCard(index, card);
        });

        return { node, cardArea, setIndex };
    };

    const removeRankSpaces = () => {
        const cardRanks = [];
        for (const card of cards) {
            if (card.rankInput.value > 0) {
                const rank = card.rankInput.value - 1;
                if (!cardRanks[rank]) cardRanks[rank] = [];
                cardRanks[rank].push(card);
            }
        }
        for (let i = 0; i < cardRanks.length; i++) {
            if (!cardRanks[i]) {
                // empty rank! remove
                for (const card of cards) {
                    if (card.rankInput.value > i + 1) {
                        card.rankInput.value -= 1;
                    }
                }
                // we need to rebuild the index now so we'll just call the function again
                return removeRankSpaces();
            }
        }
    };

    renderRanks = (skipRemoveSpaces = false) => {
        if (!skipRemoveSpaces) removeRankSpaces();

        const cardRanks = [];
        const unrankedCards = [];
        for (const card of cards) {
            if (card.node.parentNode) card.node.parentNode.removeChild(card.node);
            if (card.rankInput.value > 0) {
                const rank = card.rankInput.value - 1;
                if (!cardRanks[rank]) cardRanks[rank] = [];
                cardRanks[rank].push(card);
            } else {
                unrankedCards.push(card);
            }
        }

        const usedRanks = options.map(opt => +opt.rankInput.value);
        const minRank = usedRanks.reduce((a, b) => Math.min(a, b), Infinity);
        const maxRank = usedRanks.reduce((a, b) => Math.max(a, b), 0);

        rankDiagram.removeChild(unrankedContainer);

        const visibleRankCount = maxRank + (unrankedCards.length ? 1 : 0);
        for (let i = 0; i < visibleRankCount; i++) {
            if (!ranks[i]) {
                ranks[i] = createRank(i);
            }
        }
        while (ranks.length > visibleRankCount) {
            rankDiagram.removeChild(ranks.pop().node);
        }

        rankDiagram.appendChild(unrankedContainer);

        for (let i = 0; i < ranks.length; i++) {
            ranks[i].setIndex(i);
            ranks[i].cardArea.textContent = '';
            for (const card of (cardRanks[i] || [])) {
                ranks[i].cardArea.appendChild(card.node);
            }
            if (!cardRanks[i]) {
                const placeholderText = document.createElement('span');
                placeholderText.className = 'rank-placeholder';
                placeholderText.textContent = account_votes.interactive_rank_drop_here;
                ranks[i].cardArea.appendChild(placeholderText);
            }
        }
        for (const card of unrankedCards) {
            unrankedItems.appendChild(card.node);
        }
    };
    renderRanks();

    dragStart = () => {
        rankDiagram.classList.add('is-dragging');
    };
    dragEnd = () => {
        rankDiagram.classList.remove('is-dragging');
    };
}

function initRankedPairsViz() {
    const resultRp = document.querySelector('.result-rp-value');
    if (!resultRp) return;
    const rounds = [];
    for (const roundNode of resultRp.querySelectorAll('.rp-round[data-value]')) {
        const roundData = JSON.parse(roundNode.dataset.value);
        const nodesSet = new Set();
        for (const [a, b] of roundData.orderedPairs) {
            nodesSet.add(a);
            nodesSet.add(b);
        }
        const nodes = [...nodesSet].map(id => ({
            id: id.toString(),
            isWinner: roundData.winner === id,
        }));
        const edges = roundData.lockGraphEdges.map(edge => ({
            id: [edge.from, edge.to].sort().join('-'),
            source: edge.from.toString(),
            target: edge.to.toString(),
            diff: edge.diff,
        }));
        rounds.push({ nodes, edges, winner: roundData.winner });
    }

    const containerNode = resultRp.querySelector('.rp-interactive-insert');
    containerNode.classList.add('is-interactive');

    const voteOptions = JSON.parse(containerNode.dataset.options);
    const codeholders = JSON.parse(containerNode.dataset.codeholders);

    const svg = d3.create('svg');
    containerNode.appendChild(svg.node());

    const NODE_WIDTH = 104;
    const NODE_HEIGHT = 28;

    svg.append('defs')
        .append('marker')
        .attr('id', 'arrow-head')
        .attr('viewBox', '0 0 12 12')
        .attr('refX', '12')
        .attr('refY', '6')
        .attr('markerWidth', '6')
        .attr('markerHeight', '6')
        .attr('orient', 'auto-start-reverse')
        .append('path')
        .attr('d', 'M 0 0 L 12 6 L 0 12 z');

    const onResize = () => {
        const width = containerNode.offsetWidth;
        const height = containerNode.offsetHeight;

        svg
            .attr('width', width)
            .attr('height', height)
            .attr('viewBox', [-width / 2, -height / 2, width, height]);
    };
    onResize();
    window.addEventListener('resize', onResize);
    if (window.ResizeObserver) {
        new ResizeObserver(onResize).observe(containerNode);
    }

    const simulation = d3.forceSimulation()
        .force('charge', d3.forceManyBody())
        .force('collision', d3.forceCollide(NODE_WIDTH * 0.7))
        .force('link', d3.forceLink().id(d => d.id).distance(d => Math.sqrt(Math.abs(d.diff)) * 20))
        .force('x', d3.forceX())
        .force('y', d3.forceY())
        .on('tick', ticked);

    let node = svg.append('g').selectAll('g');
    let link = svg.append('g').selectAll('g');

    function rectPointFromAngle(width, height, t) {
        const cornerAngle = Math.atan(height / width);
        if (t < cornerAngle && t > -cornerAngle) {
            // right
            const x = width / 2;
            const y = Math.tan(t) * x;
            return [x, y];
        } else if (t > cornerAngle && t < Math.PI - cornerAngle) {
            // bottom
            const y = height / 2;
            const x = 1 / Math.tan(t) * y;
            return [x, y];
        } else if (t < -cornerAngle && t > -Math.PI + cornerAngle) {
            // top
            const y = -height / 2;
            const x = 1 / Math.tan(t) * y;
            return [x, y];
        } else {
            // left
            const x = -width / 2;
            const y = Math.tan(t) * x;
            return [x, y];
        }
    }

    function ticked() {
        node.attr('transform', d => `translate(${d.x}, ${d.y})`);

        link.each(function (d) {
            const { x: sx, y: sy } = d.source;
            const { x: tx, y: ty } = d.target;

            const angle = Math.atan2(ty - sy, tx - sx);
            const [srX, srY] = rectPointFromAngle(NODE_WIDTH, NODE_HEIGHT, angle);
            const [trX, trY] = rectPointFromAngle(NODE_WIDTH, NODE_HEIGHT, angle);

            const path = [
                'M',
                sx + srX,
                sy + srY,
                'L',
                tx - trX,
                ty - trY,
            ].join(' ');

            this.querySelector('path').setAttribute('d', path);

            const centerX = ((tx - trX) + (sx + srX)) / 2;
            const centerY = ((ty - trY) + (sy + srY)) / 2;
            this.querySelector('.edge-label').setAttribute('transform', `translate(${centerX}, ${centerY})`);
        });
    }

    function nodeDragStarted(event, d) {
        if (!event.active) simulation.alphaTarget(0.3).restart();
        d.fx = d.x;
        d.fy = d.y;
    }
    function nodeDragged(event, d) {
        d.fx = event.x;
        d.fy = event.y;
    }
    function nodeDragEnded(event, d) {
        if (!event.active) simulation.alphaTarget(0);
        d.fx = null;
        d.fy = null;
    }

    function loadRound(round) {
        const old = new Map(node.data().map(d => [d.id, d]));
        const nodes = round.nodes.map(node => ({ ...(old.get(node.id) || {}), ...node }));
        const edges = round.edges.map(edge => ({ ...edge }));

        node = node.data(nodes, d => d.id)
            .join(enter => enter.append('g')
                .attr('class', d => 'graph-node' + (d.isWinner ? ' is-winner' : ''))
                .call(d3.drag()
                    .on('start', nodeDragStarted)
                    .on('drag', nodeDragged)
                    .on('end', nodeDragEnded))
                .call(node => node
                    .append('rect')
                    .attr('x', -NODE_WIDTH / 2)
                    .attr('y', -NODE_HEIGHT / 2)
                    .attr('rx', 4)
                    .attr('width', NODE_WIDTH)
                    .attr('height', NODE_HEIGHT)
                )
                .call(node => node
                    .append('foreignObject')
                    .attr('x', -NODE_WIDTH / 2)
                    .attr('y', -NODE_HEIGHT / 2)
                    .attr('width', NODE_WIDTH)
                    .attr('height', NODE_HEIGHT)
                    .each(function (d) {
                        const div = document.createElement('div');
                        div.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
                        div.className = 'node-contents';
                        div.appendChild(renderOption(d.id));
                        this.appendChild(div);
                    })
                )
            );

        link = link.data(edges, d => [d.source, d.target])
            .join(enter => enter.append('g')
                .attr('class', 'graph-edge')
                .call(node => node
                    .append('path')
                    .attr('marker-end', 'url(#arrow-head)')
                )
                .call(node => node
                    .append('g')
                    .attr('class', 'edge-label')
                    .call(node => node
                        .append('circle')
                        .attr('class', 'edge-label-bg')
                        .attr('r', '10')
                    )
                    .append('text')
                    .attr('lengthAdjust', d => d.diff.toString().length > 2 ? 'spacingAndGlyphs' : '')
                    .attr('textLength', d => d.diff.toString().length > 2 ? '20' : '')
                    .text(d => d.diff)
                )
            );

        simulation.nodes(nodes);
        simulation.force('link').links(edges);
        simulation.alpha(1).restart().tick();
        ticked();
    }

    function renderOption(id) {
        const node = document.createElement('div');
        node.className = 'graph-option';
        const option = voteOptions[id];
        if (option.type === 'simple') {
            const label = document.createElement('span');
            label.className = 'option-label';
            label.textContent = option.name;
            node.appendChild(label);
        } else if (option.type === 'codeholder') {
            node.classList.add('is-codeholder');
            const codeholder = codeholders[option.codeholderId] || {};
            if (codeholder.icon_src) {
                const chImg = document.createElement('img');
                chImg.className = 'ch-icon';
                chImg.src = codeholder.icon_src;
                chImg.srcset = codeholder.icon_srcset;
                node.appendChild(chImg);
            }
            const label = document.createElement('span');
            label.className = 'ch-label';
            label.textContent = codeholder.name;
            node.appendChild(label);
        }

        return node;
    }

    if (rounds.length > 1) {
        const roundSwitcher = document.createElement('div');
        roundSwitcher.className = 'round-switcher';
        const prevButton = document.createElement('button');
        const label = document.createElement('div');
        const nextButton = document.createElement('button');
        prevButton.textContent = '←';
        nextButton.textContent = '→';

        roundSwitcher.appendChild(prevButton);
        roundSwitcher.appendChild(label);
        roundSwitcher.appendChild(nextButton);
        containerNode.insertBefore(roundSwitcher, containerNode.firstChild);

        let current = 0;

        function update() {
            label.textContent = account_votes.result_rp_round_n_title.replace(/%s/, (current + 1).toString());
            loadRound(rounds[current]);
        }

        prevButton.addEventListener('click', () => {
            current = Math.max(current - 1, 0);
            update();
        });
        nextButton.addEventListener('click', () => {
            current = Math.min(current + 1, rounds.length - 1);
            update();
        });

        update();
    } else {
        loadRound(rounds[0]);
    }
}
