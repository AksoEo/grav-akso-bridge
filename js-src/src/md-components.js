import countrySelector from './common/country-selector.js';

function init() {
    countrySelector();
}

if (document.readyState === 'complete') init();
else window.addEventListener('DOMContentLoaded', init);
