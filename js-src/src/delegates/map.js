import L from 'leaflet';
import { createMap, Marker } from '../common/map';
import { delegates as locale } from '../../../locale.ini';

export default function init() {
    const mapContainer = document.querySelector('.delegations-country-map-container');
    if (!mapContainer) return;

    const cities = {};
    const cityNodes = document.querySelectorAll('.delegation-cities .delegation-city[data-loc]');
    for (let i = 0; i < cityNodes.length; i++) {
        const node = cityNodes[i];
        const id = node.dataset.geoId;
        cities[id] = {
            id,
            node,
            loc: node.dataset.loc.split(',').map(x => +x),
            label: node.dataset.label,
            hash: node.id,
        };
    }
    if (!Object.keys(cities).length) return;

    const mapView = createMap(mapContainer);

    const scrollToY = end => {
        const start = window.scrollY;
        let t = 0;
        let lastTime = Date.now();
        const loop = () => {
            if (t < 1) requestAnimationFrame(loop);
            t += (Date.now() - lastTime) / 1000;
            lastTime = Date.now();
            const i = ((1 - 1 / (1 + Math.exp(10 * t - 5))) - 0.5) / 0.9866 + 0.5;
            if (t >= 1) window.scrollTo(window.scrollX, end);
            else window.scrollTo(window.scrollX, (end - start) * i + start);
        };
        loop();
    };

    for (const cityId in cities) {
        const city = cities[cityId];

        const cityInfo = city.node.querySelector('.city-info');
        const highlightButton = document.createElement('button');
        highlightButton.className = 'city-map-button';
        highlightButton.innerHTML = `<img src="/user/plugins/akso-bridge/assets/map-loc.svg" role="presentation" aria-hidden="true" draggable="false" />`;
        highlightButton.setAttribute('aria-label', locale.show_city_on_map);
        cityInfo.insertBefore(highlightButton, cityInfo.firstChild);

        const marker = new Marker();
        const lMarker = L.marker(city.loc, { icon: marker.portalIcon });
        lMarker.on('click', () => {
            city.node.classList.add('is-highlighted');
            setTimeout(() => {
                city.node.classList.remove('is-highlighted');
            }, 1500);
            const cityRect = city.node.getBoundingClientRect();
            const y = (cityRect.top + window.scrollY) - (window.innerHeight - cityRect.height) / 2;
            scrollToY(y);
        });
        lMarker.on('mouseover', () => {
            marker.highlighted = true;
            marker.didMutate();
        });
        lMarker.on('mouseout', () => {
            marker.highlighted = false;
            marker.didMutate();
        });
        lMarker.addTo(mapView);

        highlightButton.addEventListener('click', () => {
            const mapRect = mapContainer.getBoundingClientRect();
            const y = (mapRect.top + window.scrollY) - (window.innerHeight - mapRect.height) / 2;
            scrollToY(y);

            if (!mapView.getBounds().contains(city.loc)) {
                // make sure city is in view
                mapView.fitBounds(mapView.getBounds().extend(city.loc).pad(0.3));
            }

            // bounce the marker a bunch of times
            let bounces = 10;
            const interval = setInterval(() => {
                marker.highlighted = !marker.highlighted;
                marker.didMutate();
                bounces--;
                if (bounces == 0) clearInterval(interval);
            }, 150);
        });
    }

    mapView.fitBounds(L.latLngBounds(Object.values(cities).map(c => c.loc)).pad(0.3));
}
