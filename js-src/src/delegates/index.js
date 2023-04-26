import countrySelector from '../common/country-selector';
import initMap from './map';
import { initSubjectSearch, initSubjectPicker } from './subjects';
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

    // when closing the "Selected subjects" mode, we need to reset the url parameter to *
    const subjectSearchToggle = document.querySelector('#subject-search-item');
    if (subjectSearchToggle) {
        subjectSearchToggle.addEventListener('change', () => {
            if (!subjectSearchToggle.checked) {
                const subjects = document.querySelector('#subject-search-subjects');
                if (subjects) subjects.value = '*';
            }
        });
    }

    initMap();
    initSubjectSearch();
    initSubjectPicker();
}

window.addEventListener('DOMContentLoaded', init);

