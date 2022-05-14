import countryFields from './countries.eval.js';

export default function initAddressFields() {
    const feeCountrySelector = document.querySelector('#registration-field-fee-country');
    if (!feeCountrySelector) return;
    const countrySelector = document.querySelector('#codeholder-address-country');
    const splitCountry = document.querySelector('#registration-split-country');
    const fieldSel = document.querySelectorAll('.settings-field.is-address-field');
    const addressFields = [];
    for (let i = 0; i < fieldSel.length; i++) addressFields.push(fieldSel[i]);

    const originalValues = new Map();
    for (const node of addressFields) {
        originalValues.set(node, node.querySelector('input').value);
    }

    const addressValidity = document.querySelector('.settings-field.address-validity-field');

    const update = () => {
        const country = countrySelector.value;
        const requiredFields = countryFields[country.toLowerCase()];
        let valueDidChange = false;

        for (const node of addressFields) {
            const field = node.dataset.addressField;
            const input = node.querySelector('input');

            if (requiredFields && requiredFields.includes(field)) {
                node.classList.remove('is-hidden-address-field');
                input.disabled = false;
                input.required = true;

                valueDidChange = valueDidChange || (input.value !== originalValues.get(node));
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

        if (valueDidChange && addressValidity) {
            addressValidity.classList.add('address-did-change');
        } else if (!valueDidChange && addressValidity) {
            addressValidity.classList.remove('address-did-change');
        }
    };

    splitCountry.addEventListener('change', update);
    feeCountrySelector.addEventListener('change', update);
    countrySelector.addEventListener('change', update);
    update();

    if (addressValidity) {
        for (const node of addressFields) {
            node.querySelector('input').addEventListener('change', update);
        }
    }
}
