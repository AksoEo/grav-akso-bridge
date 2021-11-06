import countrySelector from '../common/country-selector';
import initMap from './map';
import './index.less';

function init() {
    countrySelector();

    const backButton = document.querySelector('#delegate-detail-back-button');
    if (backButton && window.history) {
        backButton.addEventListener('click', e => {
            e.preventDefault();
            window.history.back();
        });
    }

    initMap();
}

window.addEventListener('DOMContentLoaded', init);
