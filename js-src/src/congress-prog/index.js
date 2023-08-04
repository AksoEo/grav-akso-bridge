import { SearchFilters, tagsFilter } from '../congress-loc/search-filters';
import { fuzzyScore } from '../util/fuzzy';
import './index.less';

const FILTERS = {
    tags: tagsFilter,
};

function initFilters() {
    const wholeProgram = document.querySelector('.congress-program-container .whole-program');
    const dayAgenda = document.querySelector('.congress-program-container .program-day-agenda');

    if (!wholeProgram || !dayAgenda) return;

    const searchFilterState = {};
    const searchFilters = new SearchFilters(FILTERS, document.location.pathname, searchFilterState);

    const container = wholeProgram || dayAgenda;
    container.parentNode.insertBefore(searchFilters.node, container);

    searchFilters.onFilter = (state) => {
        const filterItem = program => {
            if (state.filters.tags?.length) {
                let found = false;
                for (const tag of JSON.parse(program.dataset.tags)) {
                    if (state.filters.tags.includes(tag.id)) {
                        found = true;
                    }
                }
                if (!found) return false;
            }
            return true;
        };

        const programs = container.querySelectorAll('.program-item');
        for (let i = 0; i < programs.length; i++) {
            const program = programs[i];

            let score = filterItem(program) ? 1 : 0;
            if (state.query) {
                score *= fuzzyScore(program.dataset.title, state.query);
            }
            if (score > 0.3) {
                program.style.display = '';
            } else {
                program.style.display = 'none';
            }
        }
    };
}

function init() {
    initFilters();
}

if (document.readyState === 'complete') init();
else window.addEventListener('DOMContentLoaded', init);
