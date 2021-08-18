import './index.less';
import '../form';
import countryCurrencies from '../../../country_currencies.ini';
import initAddressFields from './address-fields';

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

    initAutoCurrency();
    initAddressFields();
}

function initAutoCurrency() {
    const feeCountrySelector = document.querySelector('#registration-field-fee-country');
    const currencySelector = document.querySelector('#registration-settings-currency');
    if (feeCountrySelector && currencySelector) {
        const availableCurrencies = [];
        const options = currencySelector.querySelectorAll('option');
        for (let i = 0; i < options.length; i++) {
            availableCurrencies.push(options[i].value);
        }

        const updateCurrency = () => {
            const currency = countryCurrencies[feeCountrySelector.value];
            if (currency && availableCurrencies.includes(currency)) {
                currencySelector.value = currency;
            }
        };
        updateCurrency();
        feeCountrySelector.addEventListener('change', updateCurrency);
    }
}
