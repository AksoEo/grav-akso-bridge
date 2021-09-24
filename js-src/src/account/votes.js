import { account_votes } from '../../../locale.ini';

export default function initVotes() {
    initRankedOptions();
}

function initRankedOptions() {
    const CARD_MIME = 'text/plain';

    const methodDescription = document.querySelector('.method-description');
    const rankedOptions = document.querySelector('.ranked-options');
    if (!rankedOptions) return;
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
            if (method === 'stv' && (existingCard = getRankCard(index))) {
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
