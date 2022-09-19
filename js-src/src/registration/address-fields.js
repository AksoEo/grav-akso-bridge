import { getValidationRules } from '@cpsdqs/google-i18n-address';
import locale from '../../../locale.ini';

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

    const getValue = () => {
        const address = { country: countrySelector.value };
        for (const node of addressFields) {
            address[node.dataset.addressField] = node.querySelector('input').value;
        }
        return address;
    };

    const renderError = () => {
        for (const node of addressFields) {
            node.classList.remove('is-loading');
            node.classList.remove('is-hidden-address-field');
            const input = node.querySelector('input');
            input.disabled = false;
            input.required = true;
        }
    };

    const setFieldError = (field, error) => {
        let errorElem = field._aksoError;
        if (errorElem && !error) {
            field.removeChild(errorElem);
            field._aksoError = null;
        } else if (error && !errorElem) {
            errorElem = document.createElement('div');
            errorElem.className = 'address-field-error';
            field.appendChild(errorElem);
            field._aksoError = errorElem;
        }
        if (errorElem) errorElem.textContent = error;
    };

    let update;
    let triedSubmitting = false;

    const setInputChoices = (node, choices) => {
        if (choices && !choices.length) choices = null;

        const innerField = node.querySelector('.inner-field');
        const isCurrentlySelect = innerField.classList.contains('is-select');
        const input = innerField.querySelector('input');

        if (choices && !isCurrentlySelect) {
            innerField.removeChild(input);
            const container = document.createElement('span');
            container.className = 'js-select-container';
            const select = document.createElement('select');
            container.appendChild(select);
            container.appendChild(input);
            innerField.appendChild(container);
            innerField.classList.add('is-select');

            select.addEventListener('change', () => {
                input.value = select.value;
                input._userDidTouch = true;
                update();
            });
        } else if (!choices && isCurrentlySelect) {
            const container = innerField.querySelector('.js-select-container');
            innerField.removeChild(container);
            container.removeChild(input);
            innerField.appendChild(input);
            innerField.classList.remove('is-select');
        }

        if (choices) {
            const select = innerField.querySelector('select');
            select.innerHTML = '';

            const noneOption = document.createElement('option');
            noneOption.value = '';
            noneOption.textContent = '—';
            select.appendChild(noneOption);

            for (const item of choices) {
                const option = document.createElement('option');
                option.value = item[0].toLowerCase();
                option.textContent = (item[0] !== item[1]) ? `${item[0]} - ${item[1]}` : item[0];
                select.appendChild(option);
            }
            select.value = input.value.toLowerCase();
            select.required = input.required;
            select.disabled = input.disabled;
        }
    };

    let isValid = true;
    let updateId = 0;
    update = async () => {
        const thisUpdateId = ++updateId;
        const country = countrySelector.value;

        for (const node of addressFields) {
            node.classList.add('is-loading');
        }

        let rules;
        try {
            if (country) {
                rules = await getValidationRules({
                    countryCode: country,
                    ...getValue(),
                });
            }
        } catch (err) {
            if (thisUpdateId === updateId) renderError();
            return;
        }
        let valueDidChange = false;

        for (const node of addressFields) {
            node.classList.remove('is-loading');
        }

        for (const node of addressFields) {
            const field = node.dataset.addressField;
            const input = node.querySelector('input');

            const isAllowed = rules ? rules.allowedFields.includes(field) : true;
            const isRequired = rules ? rules.requiredFields.includes(field) : false;
            const choices = rules ? rules[field + 'Choices'] : null;

            input.required = isRequired;
            input.disabled = !rules; // disable when no country selected

            node.classList.remove('is-hidden-address-field');
            if (rules && !isAllowed) {
                node.classList.add('is-hidden-address-field');
            }

            const fieldValueDidChange = input.value !== originalValues.get(node);
            if (isAllowed) {
                valueDidChange = valueDidChange || fieldValueDidChange;
            } else {
                input.value = '';
            }

            setInputChoices(node, choices);
            let error = null;

            if (choices && choices.length) {
                let value = input.value.toLowerCase();
                let found = false;
                for (const choice of choices) {
                    if (value === choice[0].toLowerCase() || value === choice[1].toLowerCase()) {
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    isValid = false;
                    error = locale.registration['codeholder_js_error_address_invalid_' + field] || '?';
                }
            }
            if (field === 'postalCode' && rules && rules.postalCodeMatchers && rules.postalCodeMatchers.length) {
                for (const matcher of rules.postalCodeMatchers) {
                    if (!input.value.match(matcher)) {
                        error = locale.registration.codeholder_js_error_address_invalid_postalCode;
                        break;
                    }
                }
            }

            if (triedSubmitting || input._userDidTouch) {
                setFieldError(node, error);
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
    for (const node of addressFields) {
        const input = node.querySelector('input');
        input.addEventListener('change', update);
        input.addEventListener('blur', () => {
            input._userDidTouch = true;
            update();
        });
    }
    update();

    feeCountrySelector.form.addEventListener('submit', e => {
        triedSubmitting = true;
        if (!isValid) {
            e.preventDefault();
            document.querySelector('.address-field-error').scrollIntoView({
                behavior: 'smooth', block: 'center',
            });
        }
    });
}
