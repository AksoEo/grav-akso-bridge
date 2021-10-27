import locale from '../../locale.ini';
import { fuzzyScore } from './util/fuzzy';
import './country-selector.less';

export default function init() {
    const overviews = document.querySelectorAll('.country-overview-selector');
    for (let i = 0; i < overviews.length; i++) {
        const overview = overviews[i];
        // country search
        {
            const searchBox = document.createElement('div');
            searchBox.className = 'list-search-box';
            const searchIcon = document.createElement('img');
            searchIcon.className = 'search-icon';
            searchIcon.src = '/user/themes/uea/images/search.svg';
            searchIcon.ariaHidden = true;
            searchBox.appendChild(searchIcon);
            const searchInput = document.createElement('input');
            searchInput.className = 'search-input';
            searchInput.type = 'text';
            searchInput.placeholder = locale.country_org_lists.search_label;
            searchBox.appendChild(searchInput);
            overview.insertBefore(searchBox, overview.firstElementChild.nextElementSibling); // after title

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
