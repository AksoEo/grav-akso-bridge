if (!window.requestAnimationFrame) window.requestAnimationFrame = window.webkitRequestAnimationFrame || (r => setTimeout(r, 16));

import L from 'leaflet';
import { createMap, Marker } from '../common/map';
import { SearchFilters } from './search-filters';
import { initGlobals } from './globals';
import { renderRating } from './rating';
import { fuzzyScore } from '../util/fuzzy';
import './index.less';

function init() {
    const container = document.querySelector('.congress-location-container');
    const initialRender = document.querySelector('.congress-locations-rendered');
    if (!initialRender) return;

    const mapContainer = document.createElement('div');
    mapContainer.className = 'map-container';
    container.appendChild(mapContainer);
    const mapView = createMap(mapContainer);

    window.mapView = mapView;

    const basePath = initialRender.dataset.basePath;
    const qLoc = initialRender.dataset.queryLoc;
    const iconsPathPrefix = initialRender.dataset.iconsPathPrefix;
    const iconsPathSuffix = initialRender.dataset.iconsPathSuffix;
    initGlobals(iconsPathPrefix, iconsPathSuffix);
    const makeIconSrc = icon => iconsPathPrefix + icon + iconsPathSuffix;

    const fetchPartial = (locationId) => {
        let path = basePath + '?partial=true';
        if (locationId) path += '&' + qLoc + '=' + locationId;
        return fetch(path).then(res => {
            return res.text().then(text => [res, text]);
        }).then(([res, contents]) => {
            if (!res.ok) throw new Error(contents);

            const div = document.createElement('div');
            div.innerHTML = contents;
            const node = div.children[0];
            div.removeChild(node);
            return node;
        });
    };

    let rendered = initialRender;

    const openLoc = (locId, href) => {
        rendered.classList.add('is-loading');
        fetchPartial(locId).then(result => {
            rendered.parentNode.insertBefore(result, rendered);
            rendered.parentNode.removeChild(rendered);
            rendered = result;
            init(result);

            let path = basePath;
            if (locId) path += '?' + qLoc + '=' + locId;
            window.history.pushState({}, '', path);
        }).catch(err => {
            console.error(err);
            // failed to load for some reason; fall back to browser navigation
            const a = document.createElement('a');
            a.href = href;
            a.click();
        });
    };

    const initLinks = node => {
        const anchors = node.querySelectorAll('a');
        for (let i = 0; i < anchors.length; i++) {
            const anchor = anchors[i];
            if (typeof anchor.dataset.locId === 'string') {
                anchor.addEventListener('click', e => {
                    if (e.metaKey || e.ctrlKey || e.altKey) return;
                    e.preventDefault();
                    openLoc(anchor.dataset.locId, anchor.href);
                });
            }
        }
    };

    window.addEventListener('popstate', () => {
        const locId = window.location.search.match(/[?&]loc=(\d+)\b/);
        if (locId) {
            openLoc(locId[1], window.location.href);
        } else {
            openLoc(null, window.location.href);
        }
    });

    let isFirstMapView = true;
    const getMapAnimation = () => {
        if (isFirstMapView) {
            isFirstMapView = false;
            return {};
        }
        return { animate: true, duration: 0.5 };
    };

    let layers = [];
    const addLayer = (layer) => {
        layer.addTo(mapView);
        layers.push(layer);
    };
    const clearLayers = () => {
        for (const layer of layers) layer.remove();
        layers = [];
    };

    const searchFilterState = {};

    const initList = list => {
        initLinks(list);
        clearLayers();

        const tzOffsets = JSON.parse(atob(list.dataset.tzOffsets));

        const searchFilters = new SearchFilters(searchFilterState);
        list.parentNode.insertBefore(searchFilters.node, list);

        let lls = [];
        const domItems = list.querySelectorAll('.location-list-item');
        const items = [];
        for (let i = 0; i < domItems.length; i++) {
            const item = domItems[i];
            if (!item.dataset.ll) continue;
            const ll = item.dataset.ll.split(',').map(x => +x);
            lls.push(ll);

            const internalList = item.querySelector('.internal-locations-list');
            const internalItems = [];
            const domInternalItems = item.querySelectorAll('.internal-location-list-item');
            for (let j = 0; j < domInternalItems.length; j++) {
                const ii = domInternalItems[j];
                internalItems.push({
                    internal: true,
                    node: ii,
                    name: ii.dataset.name,
                });
            }

            item.classList.add('is-interactive');

            const marker = new Marker(makeIconSrc);
            marker.icon = item.dataset.icon;
            marker.didMutate();

            const lMarker = L.marker(ll, { icon: marker.portalIcon });
            lMarker.on('click', () => {
                item.querySelector('a[data-loc-id]').click();
            });
            let scrollIntoViewTimeout = null;
            lMarker.on('mouseover', () => {
                marker.highlighted = true;
                marker.didMutate();

                scrollIntoViewTimeout = setTimeout(() => {
                    // don't scroll immediately, in case the user is just moving their mouse through
                    // the map without meaning to select a marker
                    if (item.scrollIntoView) {
                        item.scrollIntoView({
                            behavior: 'smooth',
                            block: 'nearest',
                            inline: 'center',
                        });
                    }
                }, 300);
                item.classList.add('is-highlighted');
            });
            lMarker.on('mouseout', () => {
                clearTimeout(scrollIntoViewTimeout);
                marker.highlighted = false;
                marker.didMutate();

                item.classList.remove('is-highlighted');
            });
            addLayer(lMarker);

            items.push({
                node: item,
                ll,
                name: item.dataset.name,
                layer: lMarker,
                internalList,
                internalItems,
                openHours: item.dataset.openHours ? JSON.parse(atob(item.dataset.openHours)) : {},
                rating: item.dataset.rating ? item.dataset.rating.split('/').map(x => +x) : [0, 0],
                tz: item.dataset.tzOffset,
            });

            item.addEventListener('mouseover', () => {
                marker.highlighted = true;
                marker.didMutate();
            });
            item.addEventListener('mouseout', () => {
                marker.highlighted = false;
                marker.didMutate();
            });
        }

        try {
            mapView.fitBounds(L.latLngBounds(lls).pad(0.3), getMapAnimation());
        } catch (e) {
            // fit bounds may fail if the list is empty
            console.warn(e);
        }

        const isLocationOpenAtTime = (loc, time) => {
            if (!loc.openHours) return false;
            for (const day in loc.openHours) {
                const offset = tzOffsets[day] | 0;
                const convertedTime = new Date(+time - offset * 60000);

                const convertedDate = convertedTime.toISOString().split('T')[0];
                if (day !== convertedDate) continue;
                const convertedHour = convertedTime.getUTCHours() + convertedTime.getUTCMinutes() / 60;

                for (const span of loc.openHours[day]) {
                    const parts = span.split('-').map(p => p.split(':'));
                    const start = +parts[0][0] + (+parts[0][1] / 60);
                    const end = +parts[1][0] + (+parts[1][1] / 60);
                    console.log(day, start, convertedHour, end);

                    if (start <= convertedHour && convertedHour <= end) {
                        return true;
                    }
                }
            }
            return false;
        };

        searchFilters.onFilter = (state) => {
            let scoreThreshold = 0.3;
            if (state.query.length < 2) scoreThreshold = 0.1;

            const filterItem = item => {
                if (!state.filters) return true;
                if (!item.internal && state.filters.openAt) {
                    const openAt = state.filters.openAt;
                    if (openAt === 'now') {
                        if (!isLocationOpenAtTime(item, new Date())) return false;
                    } else if (openAt instanceof Date) {
                        if (!isLocationOpenAtTime(item, openAt)) return false;
                    }
                }
                if (!item.internal && state.filters.rating) {
                    const rating = state.filters.rating;
                    if (!item.rating[1] || item.rating[0] / item.rating[1] < rating) return false;
                }
                return true;
            };

            const scoreItem = (item, outerScore) => {
                let score = filterItem(item) ? outerScore : 0;
                if (state.query) {
                    score *= fuzzyScore(item.name, state.query);
                }
                return score;
            };

            const renderRatings = !!state.filters.rating;

            list.innerHTML = '';
            const scoreList = [];
            for (const item of items) {
                const outerScore = filterItem(item) ? 1 : 0;
                let innerScore = 0;
                if (item.internalList) {
                    item.internalList.innerHTML = '';

                    const innerScoreList = [];
                    for (const innerItem of item.internalItems) {
                        const score = scoreItem(innerItem, outerScore);
                        if (score > scoreThreshold) {
                            innerScore += score;
                            innerScoreList.push({ node: innerItem.node, score, name: innerItem.name });
                        }
                    }

                    if (state.query) {
                        innerScoreList.sort((a, b) => b.score - a.score);
                    } else {
                        innerScoreList.sort((a, b) => a.name.localeCompare(b.name));
                    }
                    for (const x of innerScoreList) item.internalList.appendChild(x.node);
                }

                const score = innerScore + scoreItem(item, 1);
                if (score > scoreThreshold) {
                    if (renderRatings) {
                        if (!item.ratingNode) {
                            item.ratingNode = renderRating(item.rating[0], item.rating[1], item.node.dataset.ratingType);
                        }
                        if (!item.ratingNode.parentNode) item.node.querySelector('.location-name').appendChild(item.ratingNode);
                    } else if (item.ratingNode && item.ratingNode.parentNode) {
                        item.ratingNode.parentNode.removeChild(item.ratingNode);
                    }

                    scoreList.push({
                        node: item.node,
                        score,
                        name: item.name,
                        layer: item.layer,
                    });
                }
            }
            if (state.query) {
                scoreList.sort((a, b) => b.score - a.score);
            } else {
                scoreList.sort((a, b) => a.name.localeCompare(b.name));
            }

            clearLayers();
            for (const x of scoreList) {
                list.appendChild(x.node);
                addLayer(x.layer);
            }
        };

        // run filter once at the beginning
        searchFilters.onFilter(searchFilters.state);
    };

    const initDetail = node => {
        initLinks(node);

        if (node.dataset.ll) {
            const ll = node.dataset.ll.split(',').map(x => +x);
            if (!layers.length) {
                // first render, probably
                mapView.setView(ll, 12, getMapAnimation());

                const marker = new Marker(makeIconSrc);
                marker.icon = node.dataset.icon;
                marker.didMutate();
                addLayer(L.marker(ll, { icon: marker.portalIcon }));
            } else {
                if (mapView.getZoom() < 10) {
                    mapView.setView(ll, 10, getMapAnimation());
                } else {
                    mapView.panTo(ll, getMapAnimation());
                }
            }
        }
    };
    const init = rendered => {
        rendered.classList.add('is-interactive');
        if (rendered.children[0].classList.contains('congress-location')) {
            initDetail(rendered.children[0]);
        } else {
            initList(rendered.children[0]);
        }
    };

    init(initialRender);
}

if (document.readyState === 'complete') init();
else window.addEventListener('DOMContentLoaded', init);
