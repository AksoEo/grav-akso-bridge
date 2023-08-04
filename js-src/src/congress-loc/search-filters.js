import { congress_locations as locale } from '../../../locale.ini';
import { fuzzyScore} from '../util/fuzzy';
import './search-filters.less';

export class SearchFilters {
    constructor(filters, basePath, state) {
        this.filters = filters;
        this.basePath = basePath;
        this.state = state;
        this.node = document.createElement('div');
        this.node.className = 'congress-loc-or-prog-filters';

        this.didMutate = () => this._didMutate();
        this.onFilter = () => {};

        this.nodes = {
            searchContainer: document.createElement('div'),
            searchInput: document.createElement('input'),
            filterBar: document.createElement('div'),
            filterBarTitle: document.createElement('label'),
            filterBarInner: document.createElement('div'),
            filterButton: document.createElement('button'),
            fullFilters: document.createElement('div'),
        };
        this.nodes.searchContainer.className = 'search-container';
        this.nodes.searchInput.className = 'search-input';
        this.nodes.filterBar.className = 'filter-bar';
        this.nodes.filterBarTitle.textContent = locale.f_title + ': ';
        this.nodes.filterBarTitle.className = 'filter-bar-title';
        this.nodes.filterBarInner.className = 'filter-bar-inner';
        this.nodes.filterButton.className = 'filter-button';
        this.nodes.fullFilters.className = 'full-filters';
        this.nodes.searchInput.placeholder = locale.search_placeholder;
        this.nodes.searchInput.type = 'text';
        this.nodes.searchInput.addEventListener('input', this.didMutate);
        this.nodes.searchContainer.appendChild(this.nodes.searchInput);
        this.node.appendChild(this.nodes.searchContainer);
        this.nodes.filterBar.appendChild(this.nodes.filterBarTitle);
        this.nodes.filterBar.appendChild(this.nodes.filterBarInner);
        this.nodes.filterBar.appendChild(this.nodes.filterButton);
        this.node.appendChild(this.nodes.filterBar);

        this.nodes.filterButton.addEventListener('click', () => {
            this.state.filtersOpen = !this.state.filtersOpen;
            this.didMutate();
        });

        this.render();
    }

    _didMutate() {
        if (this.scheduledRender) return;
        this.scheduledRender = requestAnimationFrame(() => {
            this.scheduledRender = null;
            this.render();
        });
    }

    render() {
        if (!this.state.filters) {
            this.state.filters = {};
            for (const f in this.filters) this.state.filters[f] = this.filters[f].default();
        }

        this.state.query = this.nodes.searchInput.value;

        if (!this._filterUI) this._filterUI = {};
        for (const f in this.state.filters) {
            if (!this._filterUI[f]) {
                const renderOptions = { basePath: this.basePath };
                const ui = {
                    blob: this.filters[f].renderBlob(newState => {
                        this.state.filters[f] = newState;
                        this.didMutate();
                    }, renderOptions),
                    ui: this.filters[f].renderUI(newState => {
                        this.state.filters[f] = newState;
                        this.didMutate();
                    }, renderOptions),
                };
                this._filterUI[f] = ui;

                this.nodes.fullFilters.appendChild(ui.ui.node);
            }

            const ui = this._filterUI[f];
            ui.blob.update(this.state.filters[f]);
            ui.ui.update(this.state.filters[f]);

            const showBlob = this.filters[f].shouldShowBlob(this.state.filters[f]);
            if (showBlob && !ui.blob.node.parentNode) {
                this.nodes.filterBarInner.appendChild(ui.blob.node);
            } else if (!showBlob && ui.blob.node.parentNode) {
                this.nodes.filterBarInner.removeChild(ui.blob.node);
            }
        }

        if (this.state.filtersOpen) {
            if (!this.nodes.fullFilters.parentNode) {
                this.node.appendChild(this.nodes.fullFilters);
                this.nodes.filterBar.classList.add('full-filters-open');
                this.nodes.filterButton.classList.add('is-open');
            }
        } else {
            if (this.nodes.fullFilters.parentNode) {
                this.node.removeChild(this.nodes.fullFilters);
                this.nodes.filterBar.classList.remove('full-filters-open');
                this.nodes.filterButton.classList.remove('is-open');
            }
        }

        this.onFilter(this.state);
    }
}

export const tagsFilter = {
    default: () => [],
    shouldShowBlob: (state) => !!state.length,
    renderBlob: (updateState, sharedOptions) => {
        const blob = document.createElement('button');
        blob.className = 'filter-blob filter-tags';

        blob.addEventListener('click', () => {
            updateState([]);
        });

        const update = (state) => {
            if (state.length) {
                blob.classList.add('is-active');

                blob.innerHTML = '';
                for (const item of state) {
                    const tag = sharedOptions.availableTags.find(i => i.id === item);
                    if (tag) {
                        const tagNode = document.createElement('span');
                        tagNode.className = 'filter-tag';
                        tagNode.textContent = tag.name;
                        blob.appendChild(tagNode);
                    }
                }
            } else {
                blob.classList.remove('is-active');
            }
        };

        return {
            node: blob,
            update,
        };
    },
    renderUI: (updateState, sharedOptions) => {
        const { basePath } = sharedOptions;
        const node = document.createElement('div');
        node.className = 'filter-item';

        let selectedTags = [];

        let loading = true;
        let availableTags = [];
        let error = null;

        const contentNode = document.createElement('div');
        contentNode.className = 'tags-filter';
        contentNode.innerHTML = `
            <div class="tags-search">
                <input class="i-input" type="text" />
            </div>
            <div class="tags-scroll-view">
                <ul class="tags-list"></ul>
            </div>
            `;
        const searchInput = contentNode.querySelector('.tags-search .i-input');
        const tagsListNode = contentNode.querySelector('.tags-list');
        let tagNodes = [];

        searchInput.placeholder = locale.f_tags_search;

        const renderFromLoad = () => {
            node.innerHTML = '';
            if (loading) {
                node.textContent = locale.f_tags_loading;
            } else if (error) {
                node.textContent = locale.f_tags_error;
            } else {
                sharedOptions.availableTags = availableTags;
                tagNodes = [];
                for (const tag of availableTags) {
                    const li = document.createElement('li');
                    li.className = 'tags-list-item';
                    const checkbox = document.createElement('input');
                    checkbox.id = Math.random().toString(36);
                    checkbox.type = 'checkbox';
                    const label = document.createElement('label');
                    label.htmlFor = checkbox.id;
                    label.className = 'i-label';
                    label.textContent = tag.name;

                    li.appendChild(checkbox);
                    li.appendChild(label);
                    tagNodes.push({ li, checkbox, id: tag.id, name: tag.name });
                    tagsListNode.appendChild(li);

                    checkbox.addEventListener('change', () => {
                        const tags = [...selectedTags];
                        if (tags.includes(tag.id)) {
                            tags.splice(tags.indexOf(tag.id), 1);
                        } else {
                            tags.push(tag.id);
                        }
                        updateState(tags);
                    });
                }
                searchInput.value = '';
                node.appendChild(contentNode);
            }
        };
        const updateCheckboxes = () => {
            for (const item of tagNodes) {
                item.checkbox.checked = selectedTags.includes(item.id);
            }
        };

        const updateSearch = () => {
            tagsListNode.innerHTML = '';
            if (!searchInput.value.trim()) {
                for (const item of tagNodes) tagsListNode.appendChild(item.li);
                return;
            }
            const items = [];
            for (const item of tagNodes) {
                const score = fuzzyScore(item.name, searchInput.value);
                if (score > 0.3) {
                    items.push([score, item]);
                }
            }
            const sortedItems = items.sort((a, b) => b[0] - a[0]).map(([, item]) => item);
            for (const item of sortedItems) tagsListNode.appendChild(item.li);
        };
        searchInput.addEventListener('input', updateSearch);
        searchInput.addEventListener('change', updateSearch);

        {
            renderFromLoad();
            fetch(basePath + '?tags').then(res => {
                if (!res.ok) {
                    return res.text().then(text => {
                        throw new Error(text);
                    });
                }
                return res.json();
            }).then(res => {
                availableTags = res;
            }).catch(err => {
                console.error(err);
                error = err;
            }).finally(() => {
                loading = false;
                renderFromLoad();
            });
        }

        return {
            node,
            update: (selected) => {
                selectedTags = selected;
                updateCheckboxes();
            },
        };
    },
};
