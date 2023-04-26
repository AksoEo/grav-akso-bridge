import { delegates as locale } from '../../../locale.ini';
import { fuzzyScore } from '../util/fuzzy';

export function initSubjectSearch() {
    const searchQuery = document.querySelector('.delegate-search-form .search-query');
    const subjectResultsList = document.querySelector('.delegation-subject-results .subject-list');

    // only activate on page with all tags and no search
    if (!searchQuery || searchQuery.value || !subjectResultsList) return;

    const searchSubmit = document.querySelector('.delegate-search-form .search-submit');

    const items = [...subjectResultsList.querySelectorAll('.subject-item')];

    const updateSearch = () => {
        const query = searchQuery.value.trim();
        const threshold = query.length > 3 ? 0.3 : 0.1;

        subjectResultsList.innerHTML = '';
        let filtered = [];

        if (query) {
            for (const item of items) {
                const score = fuzzyScore(item.dataset.name, query);
                if (score > threshold) {
                    filtered.push([item, score]);
                }
            }
            filtered.sort(([, a], [, b]) => b - a);
            filtered = filtered.map(([item]) => item);
        } else {
            filtered = items;
        }

        for (const item of filtered) subjectResultsList.appendChild(item);
    };

    searchSubmit.addEventListener('click', e => {
        e.preventDefault();
        updateSearch();
    });
    searchQuery.addEventListener('input', updateSearch);
    searchQuery.addEventListener('change', updateSearch);
}

export function initSubjectPicker() {
    const subjectResults = document.querySelector('.delegation-subject-results');
    if (!subjectResults) return;

    let update;

    const subjects = [];
    const subjectNodeList = subjectResults.querySelectorAll('.subject-list .subject-item');;
    for (let i = 0; i < subjectNodeList.length; i++) {
        const node = subjectNodeList[i];

        const check = document.createElement('input');
        check.type = 'checkbox';
        const name = node.querySelector('.subject-name');
        name.insertBefore(check, name.firstElementChild);
        node.classList.add('has-checkbox');
        check.addEventListener('change', () => update());

        subjects.push({ node, check, id: node.dataset.id });
    }

    if (!subjects.length) return;

    const selectionBar = document.createElement('div');
    selectionBar.className = 'subject-list-selection-bar';
    const clearButton = document.createElement('button');
    clearButton.setAttribute('aria-label', locale.subject_selection_clear);
    clearButton.type = 'button';
    clearButton.className = 'clear-button';
    selectionBar.appendChild(clearButton);
    const selectedLabel = document.createElement('div');
    selectionBar.appendChild(selectedLabel);
    const spacer = document.createElement('div');
    spacer.className = 'spacer';
    selectionBar.appendChild(spacer);
    const applyButton = document.createElement('button');
    applyButton.className = 'selection-apply-button is-primary';
    applyButton.type = 'button';
    applyButton.textContent = locale.subject_selection_apply;
    selectionBar.appendChild(applyButton);
    subjectResults.insertBefore(selectionBar, subjectResults.firstElementChild.nextElementSibling); // after title

    update = () => {
        const selectedCount = subjects.filter(s => s.check.checked).length;

        if (selectedCount) selectionBar.classList.add('has-selection');
        else selectionBar.classList.remove('has-selection');
        applyButton.disabled = !selectedCount;

        selectedLabel.textContent = locale.subject_selection_0 + selectedCount
            + (selectedCount === 1 ? locale.subject_selection_1s : locale.subject_selection_1);
    };
    update();

    clearButton.addEventListener('click', () => {
        for (const subject of subjects) {
            subject.check.checked = false;
        }
        update();
    });

    applyButton.addEventListener('click', () => {
        document.location = document.location.pathname + '?fako=' + subjects.filter(s => s.check.checked).map(s => s.id).join(',');
    });
}
