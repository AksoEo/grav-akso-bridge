import countryFields from './countries.eval.js';

export default function initAddressFields() {
    const feeCountrySelector = document.querySelector('#registration-field-fee-country');
    if (!feeCountrySelector) return;
    const countrySelector = document.querySelector('#codeholder-address-country');
    const splitCountry = document.querySelector('#registration-split-country');
    const fieldSel = document.querySelectorAll('.settings-field.is-address-field');
    const addressFields = [];
    for (let i = 0; i < fieldSel.length; i++) addressFields.push(fieldSel[i]);

    const update = () => {
        let country = countrySelector.value;
        const requiredFields = countryFields[country.toLowerCase()];
        for (const node of addressFields) {
            const field = node.dataset.addressField;
            const input = node.querySelector('input');

            if (requiredFields && requiredFields.includes(field)) {
                node.classList.remove('is-hidden-address-field');
                input.disabled = false;
                input.required = true;
            } else if (requiredFields) {
                node.classList.add('is-hidden-address-field');
                input.disabled = true;
                input.required = false;
                input.value = '';
            } else {
                // no country selected
                node.classList.remove('is-hidden-address-field');
                input.disabled = true;
                input.required = false;
            }
        }
    };

    splitCountry.addEventListener('change', update);
    feeCountrySelector.addEventListener('change', update);
    countrySelector.addEventListener('change', update);
    update();
}
