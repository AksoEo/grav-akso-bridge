// javascript country selector with an obvious search box
import { registration as locale } from '../../../locale.ini';
import { fuzzyScore } from '../util/fuzzy';
import './country-selector.less';

export default function initCountrySelector(node) {
    const input = node.querySelector('select');
    const optionNodes = node.querySelectorAll('select option');
    const options = [];
    for (let i = 0; i < optionNodes.length; i++) {
        const option = optionNodes[i];
        options.push({
            value: option.value,
            label: option.textContent,
        });
    }

    const select = document.createElement('button');
    select.className = 'js-country-selector';
    select.type = 'button';
    node.appendChild(select);

    const update = () => {
        select.textContent = options.find(item => item.value === input.value)?.label;
    };
    input.addEventListener('change', update);

    const open = () => {
        const container = document.createElement('div');
        container.className = 'js-country-selector-dialog';

        const backdrop = document.createElement('div');
        backdrop.className = 'inner-backdrop';
        container.appendChild(backdrop);

        const dialog = document.createElement('div');
        dialog.className = 'inner-dialog';
        dialog.role = 'dialog';
        container.appendChild(dialog);

        dialog.innerHTML = `
<div class="inner-header">
    <input class="inner-search-box" type="text" />
</div>
<div class="inner-list">
    <ul class="inner-list-items"></ul>
</div>
        `;
        const searchBox = dialog.querySelector('.inner-search-box');
        searchBox.placeholder = locale.js_country_selector_search_placeholder;

        const close = () => {
            document.body.removeChild(container);
        };

        const listItems = dialog.querySelector('.inner-list-items');
        const items = [];
        for (const option of options) {
            const li = document.createElement('li');
            li.className = 'inner-list-item';
            li.dataset.value = option.value;
            li.role = 'button';
            li.textContent = option.label;

            li.addEventListener('click', () => {
                input.value = option.value;
                input.dispatchEvent(new Event('change'));
                close();
            });

            if (input.value === option.value) {
                li.classList.add('is-selected');
            }
            listItems.appendChild(li);
            items.push({ label: option.label, node: li });
        }

        const updateSearch = () => {
            const query = searchBox.value;

            listItems.innerHTML = '';
            let sortedItems = items;
            if (query) {
                sortedItems = items
                    .map(item => [fuzzyScore(item.label, query), item])
                    .filter(x => x[0] > 0.3);
                sortedItems.sort((a, b) => b[0] - a[0]);
                sortedItems = sortedItems.map(x => x[1]);
            }

            for (const item of sortedItems) {
                listItems.appendChild(item.node);
            }
            listItems.scrollTo(0, 0);
        };
        const onKeyDown = e => {
            if (e.key === 'Enter') {
                // just pick the first item for now
                input.value = listItems.firstElementChild?.dataset?.value;
                input.dispatchEvent(new Event('change'));
                close();
            }
        };

        document.body.appendChild(container);

        backdrop.addEventListener('click', close);
        searchBox.addEventListener('input', updateSearch);
        searchBox.addEventListener('keydown', onKeyDown);
        if (HTMLElement.prototype.scrollIntoView) {
            listItems.querySelector('.is-selected')?.scrollIntoView();
        }
        searchBox.focus();
    };

    select.addEventListener('click', open);
    input.style.display = 'none';
    update();
}
