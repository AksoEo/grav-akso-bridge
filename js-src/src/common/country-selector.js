import locale from '../../../locale.ini';
import { fuzzyScore } from '../util/fuzzy';
import './country-selector.less';

export default function init() {
    const overviews = document.querySelectorAll('.country-overview-selector');
    for (let i = 0; i < overviews.length; i++) {
        const overview = overviews[i];

        // scroll currently selected item into view
        {
            const selected = overview.querySelector('.country-item.is-current');
            if (selected) {
                // FIXME: this math is probably bad
                const selectedY = selected.getBoundingClientRect().top - overview.getBoundingClientRect().top;
                overview.scrollTop = selectedY - overview.offsetHeight / 3;
            }
        }

        // country search
        {
            const searchBoxContainer = document.createElement('div');
            searchBoxContainer.className = 'list-search-box-container';
            const searchBox = document.createElement('div');
            searchBox.className = 'list-search-box';
            const searchIcon = document.createElement('img');
            searchIcon.className = 'search-icon';
            searchIcon.src = '/user/themes/akso/images/search.svg';
            searchIcon.ariaHidden = true;
            searchBox.appendChild(searchIcon);
            const searchInput = document.createElement('input');
            searchInput.className = 'search-input';
            searchInput.type = 'text';
            searchInput.placeholder = locale.country_org_lists.search_label;
            searchBox.appendChild(searchInput);
            searchBoxContainer.appendChild(searchBox);
            overview.insertBefore(searchBoxContainer, overview.firstElementChild.nextElementSibling); // after title

            searchInput.addEventListener('input', () => {
                const query = searchInput.value;
                const threshold = query.length > 3 ? 0.3 : 0.1;

                const items = overview.querySelectorAll('.country-item');
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    if (!query || fuzzyScore(item.dataset.name, query) > threshold) {
                        item.classList.remove('search-hidden');
                    } else {
                        item.classList.add('search-hidden');
                    }
                }
            });
        }
    }
}
