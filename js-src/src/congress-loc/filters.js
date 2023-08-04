import { stdlib } from '@tejo/akso-script';
import { initDateTimePolyfill } from '../form/date-editor';
import { renderRating } from './rating';
import { tagsFilter } from './search-filters';
import { congress_locations as locale } from '../../../locale.ini';

export const FILTERS = {
    // openAt
    // - null
    // - 'now' => open now
    // - Date => open at date
    openAt: {
        default: () => null,
        shouldShowBlob: () => true,
        renderBlob: (updateState) => {
            const blob = document.createElement('button');
            blob.className = 'filter-blob filter-open-at';

            let lastActiveState = 'now';
            let currentState;
            blob.addEventListener('click', () => {
                if (currentState === null) {
                    updateState(lastActiveState);
                } else {
                    updateState(null);
                }
            });

            let lastActiveStateLabel = locale.f_open_at_now;

            const renderState = state => {
                currentState = state;
                if (state === null) {
                    blob.classList.remove('is-active');
                } else if (state === 'now') {
                    blob.classList.add('is-active');
                    lastActiveStateLabel = locale.f_open_at_now;
                } else {
                    // state is Date
                    blob.classList.add('is-active');
                    lastActiveStateLabel = locale.f_open_at + ' ' + stdlib.ts_fmt.apply(null, [state]);
                }
                if (state) lastActiveState = state;
                blob.textContent = lastActiveStateLabel;
            };

            return {
                node: blob,
                update: renderState,
            };
        },
        renderUI: (updateState) => {
            const node = document.createElement('div');
            node.className = 'filter-item';

            let currentState;

            const stateSwitch = document.createElement('div');
            stateSwitch.className = 'state-switch';
            node.appendChild(stateSwitch);

            const switchNow = document.createElement('button');
            switchNow.className = 'switch-item';
            switchNow.textContent = locale.f_open_at_now;
            stateSwitch.appendChild(switchNow);

            switchNow.addEventListener('click', () => {
                if (currentState === 'now') {
                    updateState(null);
                    return;
                }
                updateState('now');
            });

            const switchTime = document.createElement('button');
            switchNow.className = 'switch-item';
            switchTime.textContent = locale.f_open_at_time;
            stateSwitch.appendChild(switchTime);

            switchTime.addEventListener('click', () => {
                if (currentState instanceof Date) {
                    updateState(null);
                    return;
                }
                updateState(new Date());
            });

            const dateEditor = document.createElement('div');
            dateEditor.className = 'date-editor-container';
            const innerDateEditor = document.createElement('input');
            innerDateEditor.className = 'native-date-editor';
            dateEditor.appendChild(innerDateEditor);
            innerDateEditor.type = 'datetime-local';
            const onDateChange = (localDate) => {
                const lDate = new Date(innerDateEditor.value + 'Z');
                const off = new Date().getTimezoneOffset();
                const date = new Date(+lDate + off * 60000);
                if (Number.isFinite(date.getFullYear())) updateState(date);
            };
            if (innerDateEditor.type !== 'datetime-local') {
                initDateTimePolyfill(innerDateEditor, () => {
                    onDateChange(innerDateEditor.value);
                });
            } else {
                innerDateEditor.addEventListener('input', () => {
                    onDateChange(innerDateEditor.value);
                });
            }

            const update = (state) => {
                currentState = state;

                switchNow.classList.remove('is-selected');
                switchTime.classList.remove('is-selected');
                if (state === 'now') {
                    switchNow.classList.add('is-selected');
                } else if (state instanceof Date) {
                    switchTime.classList.add('is-selected');
                }

                if (state instanceof Date && !dateEditor.parentNode) {
                    node.appendChild(dateEditor);
                } else if (!(state instanceof Date) && dateEditor.parentNode) {
                    node.removeChild(dateEditor);
                }
            };

            return {
                node,
                update,
            };
        },
    },
    // rating
    // - null
    // - (number) 0..1 => “above x%”
    rating: {
        default: () => null,
        shouldShowBlob: () => true,
        renderBlob: (updateState) => {
            const blob = document.createElement('button');
            blob.className = 'filter-blob filter-rating';

            let lastActiveState = 3 / 5;
            let currentState;
            blob.addEventListener('click', () => {
                if (currentState === null) {
                    updateState(lastActiveState);
                } else {
                    updateState(null);
                }
            });

            const update = (state) => {
                currentState = state;
                if (state === null) {
                    blob.classList.remove('is-active');
                } else {
                    blob.classList.add('is-active');
                    lastActiveState = state;
                }

                blob.innerHTML = '';
                const rendered = renderRating(lastActiveState * 5, 5, 'stars');
                rendered.classList.add('filter-rating');
                blob.appendChild(rendered);
            };

            return {
                node: blob,
                update,
            };
        },
        renderUI: (updateState) => {
            const node = document.createElement('div');
            node.className = 'filter-item';

            let currentState;

            const stateSwitch = document.createElement('div');
            stateSwitch.className = 'state-switch';
            node.appendChild(stateSwitch);

            const switchAny = document.createElement('button');
            switchAny.className = 'switch-item';
            switchAny.textContent = locale.f_rating_any;
            stateSwitch.appendChild(switchAny);
            const switchAbove = document.createElement('button');
            switchAbove.className = 'switch-item';
            switchAbove.textContent = locale.f_rating_above;
            stateSwitch.appendChild(switchAbove);

            switchAny.addEventListener('click', () => updateState(null));
            switchAbove.addEventListener('click', () => {
                if (currentState === null) updateState(3 / 5);
            });

            const ratingContainer = document.createElement('div');
            ratingContainer.className = 'rating-container';

            const update = state => {
                currentState = state;

                switchAny.classList.remove('is-selected');
                switchAbove.classList.remove('is-selected');
                if (state === null) {
                    switchAny.classList.add('is-selected');
                    if (ratingContainer.parentNode) node.removeChild(ratingContainer);
                } else {
                    switchAbove.classList.add('is-selected');
                    if (!ratingContainer.parentNode) node.appendChild(ratingContainer);
                }

                ratingContainer.innerHTML = '';
                const rendered = renderRating(state * 5, 5, 'stars', value => updateState(value / 5));
                ratingContainer.appendChild(rendered);
            };

            return {
                node,
                update,
            };
        },
    },
    tags: tagsFilter,
    // TODO: location tags
};
