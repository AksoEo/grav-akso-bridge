import L from 'leaflet';
export { Marker } from './map-marker';
import 'leaflet/dist/leaflet.css';

const TILE_LAYER_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const TILE_LAYER_ATTRIB = '&copy <a href="https://osm.org/copyright">OpenStreetMap</a> contributors';

export function createMap(container) {
    const mapView = L.map(container);
    mapView.setView([0, 0], 1);
    L.tileLayer(TILE_LAYER_URL, { attribution: TILE_LAYER_ATTRIB }).addTo(mapView);
    return mapView;
}
