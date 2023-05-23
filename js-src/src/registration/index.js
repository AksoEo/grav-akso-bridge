import './index.less';
import '../form';
import countryCurrencies from '../../../country_currencies.ini';
import initAddressFields from './address-fields';
import './manual-warning';

window.addEventListener('DOMContentLoaded', init);

function init() {
    const selectionBoxes = document.querySelectorAll('.selection-box');
    for (let i = 0; i < selectionBoxes.length; i++) {
        const box = selectionBoxes[i];
        const input = document.getElementById(box.getAttribute('for'));
        if (!input) continue;
        if (input.type === 'radio') {
            // add ability to un-check a radio button
            box.addEventListener('click', (e) => {
                if (input.checked) {
                    e.preventDefault();
                    input.checked = false;
                }
            });
        }
    }

    initAutoFeeCountry();
    initAutoCurrency();
    initAddressFields();
}

function initAutoFeeCountry() {
    const addressCountrySelector = document.querySelector('#codeholder-address-country');
    const feeCountrySelector = document.querySelector('#registration-field-fee-country');
    const splitCountry = document.querySelector('#registration-split-country');

    if (addressCountrySelector && feeCountrySelector && splitCountry) {
        splitCountry.addEventListener('change', () => {
            if (splitCountry.checked) {
                // split country was checked! set feeCountry to addressCountry if empty
                if (!feeCountrySelector.value) {
                    feeCountrySelector.value = addressCountrySelector.value;
                }
            }
        });
    }
}

function initAutoCurrency() {
    const addressCountrySelector = document.querySelector('#codeholder-address-country');
    const feeCountrySelector = document.querySelector('#registration-field-fee-country');
    const splitCountry = document.querySelector('#registration-split-country');
    const currencySelector = document.querySelector('#registration-settings-currency');
    if (addressCountrySelector && feeCountrySelector && currencySelector) {
        const availableCurrencies = [];
        const options = currencySelector.querySelectorAll('option');
        for (let i = 0; i < options.length; i++) {
            availableCurrencies.push(options[i].value);
        }

        const updateCurrency = () => {
            const country = splitCountry.checked
                ? feeCountrySelector.value
                : addressCountrySelector.value;

            const currency = countryCurrencies[country];
            if (currency && availableCurrencies.includes(currency)) {
                currencySelector.value = currency;
            }
        };
        updateCurrency();

        feeCountrySelector.addEventListener('change', updateCurrency);
        addressCountrySelector.addEventListener('change', updateCurrency);
        splitCountry.addEventListener('change', updateCurrency);
    }
}
