import { evaluate, stdlib, loadCountryFmt, loadPhoneFmt } from '@tejo/akso-script';
import locale from '../../../locale.ini';
import Markdown from 'markdown-it';
import { initDatePolyfill, initTimePolyfill, initDateTimePolyfill } from './date-editor';
import './index.less';

{
    const noscriptItems = document.querySelectorAll('.congress-form-noscript');
    for (let i = 0; i < noscriptItems.length; i++) {
        noscriptItems[i].parentNode.removeChild(noscriptItems[i]);
    }
}

{
    const intentRedirect = document.querySelector('#payment-intent-redirect-button');
    if (intentRedirect) {
        intentRedirect.click();
    }
}

// TODO: load if needed/show progress?
loadCountryFmt().then(() => console.log('Loaded ASC countries'));
loadPhoneFmt().then(() => console.log('Loaded ASC phone-numbers'));

// TODO: validate number ranges/step anyway because browsers are often rather unhelpful with their
// error messages

let scrollAnimationLoop = 0;
function scrollNodeIntoView(node) {
    let windowTop = 0;
    let windowBottom = window.innerHeight;

    if (window.visualViewport) {
        windowTop = window.visualViewport.offsetTop;
        windowBottom = window.visualViewport.height;
    }

    const nodeRect = node.getBoundingClientRect();
    if (nodeRect.top > 0 && nodeRect.bottom < windowBottom) {
        // node is fully in frame
        return;
    }

    // target scrollY such that the target node is centered
    const centerY = (windowTop + windowBottom) / 2;
    let target = Math.max(0, nodeRect.top + nodeRect.height / 2 + window.scrollY - centerY);

    let value = window.scrollY;
    let velocity = 0;
    const F = 109.66;
    const D = 20.94;

    scrollAnimationLoop++;
    const loop = scrollAnimationLoop;
    let lastTime = Date.now();
    const loopFn = () => {
        if (loop === scrollAnimationLoop) requestAnimationFrame(loopFn);
        const dt = (Date.now() - lastTime) / 1000;
        lastTime = Date.now();

        velocity += ((target - value) * F - velocity * D) * dt;
        value += velocity * dt;
        window.scroll(window.scrollX, value);

        // stop if value is near target
        if (Math.abs(value - target) < 3) scrollAnimationLoop++;
    };
    loopFn();
}
window.addEventListener('touchstart', () => {
    // cancel scroll animation if the user taps the screen
    scrollAnimationLoop++;
}, { passive: true });
window.addEventListener('wheel', () => {
    // cancel scroll animation if the user scrolls
    scrollAnimationLoop++;
}, { passive: true });

const md = new Markdown();
const MAX_EVAL_STEPS = 4096;

function ascCastToString(value) {
    if (Array.isArray(value)) return value.join('');
    if (value) return value.toString();
    return '';
}

function decodeScript(script) {
    if (!script) return null;
    if (script.startsWith('!')) return JSON.parse(script.substr(1));
    return JSON.parse(atob(script));
}

const shouldHalt = () => {
    let count = 0;
    return () => {
        count++;
        if (count > MAX_EVAL_STEPS) return true;
        return false;
    };
};

function ascEval(scriptStack, formVars, expr) {
    const sym = Symbol('result');
    return evaluate(scriptStack.concat([{
        [sym]: expr,
    }]), sym, id => formVars[id] || null, {
        shouldHalt: shouldHalt(),
    });
}

function localize(key, ...args) {
    if (args.length) {
        let out = '';
        let i = 0;
        for (const p of args) {
            out += locale.registration_form[key + '_' + i];
            out += p;
            i++;
        }
        out += locale.registration_form[key + '_' + i];
        return out;
    }
    return locale.registration_form[key];
}

const RE_DATE_FMT = /^(\d{4})-(\d{2})-(\d{2})$/;
const RE_TIME_FMT = /^(\d{2}):(\d{2})$/;
const RE_DATETIME_FMT = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):(\d{2})$/;

class FormInput {
    constructor(node, onChange) {
        this.el = 'input';
        this.node = node;
        this.name = node.dataset.name;
        this.type = node.dataset.type;
        this.onChange = onChange;

        // don't show errors until the user interacted with the input
        this.didInteract = false;
        this.didChangeOnce = false;

        this.didChange = () => {
            this.didChangeOnce = true;
            this.onChange();
        };

        this.init();
    }

    init() {
        const { type } = this;
        const inputs = this.node.querySelectorAll('input');
        for (let i = 0; i < inputs.length; i++) {
            const input = inputs[i];
            input.addEventListener('change', this.didChange);

            if (type === 'number' || type === 'text' || type === 'money' || type === 'date' || type === 'time' || type === 'datetime') {
                input.addEventListener('blur', () => {
                    this.didInteract = true;
                    this.onChange();
                });
            } else if (type === 'boolean') {
                input.addEventListener('change', () => {
                    this.didInteract = true;
                });
            } else if (type === 'boolean_table') {
                input.addEventListener('change', () => {
                    if (this.node.dataset.minSelect) {
                        // only consider interacted after selecting minSelect items
                        const value = this.getValue();
                        let selected = 0;
                        for (const row of value) for (const col of row) if (col) selected++;
                        if (selected < this.node.dataset.minSelect) return;
                    }
                    this.didInteract = true;
                });
            }
        }

        if (type === 'enum') {
            if (this.node.dataset.variant === 'select') {
                this.node.querySelector('select').addEventListener('change', () => {
                    this.didInteract = true;
                    this.didChange();
                });
            }
        } else if (type === 'date') {
            const input = this.node.querySelector('input');
            // type="date" will fall back to "text" on browsers where date isn't supported
            if (input.type !== 'date') initDatePolyfill(input, () => {
                this.didInteract = true;
                this.didChange();
            });
        } else if (type === 'time') {
            const input = this.node.querySelector('input');
            if (input.type !== 'time') initTimePolyfill(input, () => {
                this.didInteract = true;
                this.didChange();
            });
        } else if (type === 'datetime') {
            const input = this.node.querySelector('input');
            if (input.type !== 'datetime-local') initDateTimePolyfill(input, () => {
                this.didInteract = true;
                this.didChange();
            });
        } else if (type === 'country') {
            this.node.querySelector('select').addEventListener('change', () => {
                this.didInteract = true;
                this.didChange();
            });
        } else if (type === 'money') {
            const conv = this.node.querySelector('.currency-approx-conversion');
            if (conv) {
                this.initCurrencyConv(conv);
            }
        }

        const { scriptDefault, scriptRequired, scriptDisabled } = this.node.dataset;
        if (scriptDefault) this.scriptDefault = JSON.parse(atob(scriptDefault));
        if (scriptRequired) this.scriptRequired = JSON.parse(atob(scriptRequired));
        if (scriptDisabled) this.scriptDisabled = JSON.parse(atob(scriptDisabled));
    }

    initCurrencyConv(conv) {
        this.currencyConv = conv;
        this.renderCurrencyConv();
    }
    renderCurrencyConv() {
        const conv = this.currencyConv;
        if (!conv) return;
        const displayCurrency = conv.dataset.currency;
        const val = this.getValue();
        if (Number.isFinite(val)) {
            const converted = Math.round((val * conv.dataset.approxRate) / 1000000);
            conv.textContent = '≈ ' + stdlib.currency_fmt.apply(null, [displayCurrency, converted]) + ' *';
        } else {
            conv.textContent = '*';
        }
    }

    update(scriptStack, formVars) {
        if (!this.didChangeOnce && this.scriptDefault) {
            // update the value with the default as long as it wasn't edited
            try {
                const value = ascEval(scriptStack, formVars, this.scriptDefault);
                this.setValue(value);
            } catch (err) {
                console.warn(`Error setting default value for ${this.name}`, err);
            }
        }
        if (this.scriptRequired) {
            try {
                const value = ascEval(scriptStack, formVars, this.scriptRequired);
                this.setRequired(value === true);
            } catch (err) {
                console.warn(`Error getting required parameter for ${this.name}`, err);
            }
        }
        if (this.scriptDisabled) {
            try {
                const value = ascEval(scriptStack, formVars, this.scriptDisabled);
                this.setDisabled(value === true);
            } catch (err) {
                console.warn(`Error getting disabled parameter for ${this.name}`, err);
            }
        }

        this.renderCurrencyConv();
    }

    getValue() {
        const { type } = this;
        if (type === 'boolean') {
            return this.node.querySelector('input').checked;
        } else if (type === 'number' || type === 'money') {
            const input = this.node.querySelector('input');
            const { value } = input;
            const parsed = parseFloat(value);
            if (Number.isFinite(parsed)) {
                if (type === 'money') {
                    return parsed * +input.dataset.currencyMultiplier;
                }
                return parsed;
            }
            return null;
        } else if (type === 'text') {
            const input = this.node.querySelector('input') || this.node.querySelector('textarea');
            return input.value || null;
        } else if (type === 'enum') {
            const { variant } = this.node.dataset;
            if (variant === 'select') {
                return this.node.querySelector('select').value || null;
            } else if (variant === 'radio') {
                const inputs = this.node.querySelectorAll('input[type=radio]');
                for (let i = 0; i < inputs.length; i++) {
                    if (inputs[i].checked) return inputs[i].value;
                }
                return null;
            }
        } else if (type === 'country') {
          const { value } = this.node.querySelector('select');
          return value || null;
        } else if (type === 'date') {
            const { value } = this.node.querySelector('input');
            const match = value.match(RE_DATE_FMT);
            if (match) return value;
            return null;
        } else if (type === 'time') {
            const { value } = this.node.querySelector('input');
            const match = value.match(RE_TIME_FMT);
            if (match) return value;
            return null;
        } else if (type === 'datetime') {
            const { tz } = this.node.dataset;
            const { value } = this.node.querySelector('input');
            const match = value.match(RE_DATETIME_FMT);
            if (match) {
                const tzOffset = Number.isFinite(tz) ? tz : (new Date().getTimezoneOffset());
                return stdlib.ts_from_date(match[1], tzOffset, +match[2], +match[3], +match[4]);
            }
            return null;
        } else if (type === 'boolean_table') {
            const { cols, rows } = this.node.dataset;
            const value = [];
            let isNull = true;
            for (let y = 0; y < rows; y++) {
                const row = [];
                for (let x = 0; x < cols; x++) {
                    const box = this.node.querySelector(`input[data-index="${x}-${y}"]`)
                    if (box && box.checked) isNull = false;
                    if (!box) row.push(null);
                    else row.push(box.checked);
                }
                value.push(row);
            }
            return isNull ? null : value;
        }
    }

    setValue(value) {
        // TODO: don't crash if the value is invalid

        const { type } = this;
        if (type === 'boolean') {
            this.node.querySelector('input').checked = !!value;
        } else if (type === 'number' || type === 'money') {
            const input = this.node.querySelector('input');
            if (type === 'money') input.value = Number.isFinite(value) ? value / +input.dataset.currencyMultiplier : '';
            else input.value = Number.isFinite(value) ? value : '';
        } else if (type === 'text') {
            const input = this.node.querySelector('input') || this.node.querySelector('textarea');
            input.value = value || '';
        } else if (type === 'enum') {
            const { variant } = this.node.dataset;
            if (variant === 'select') {
                this.node.querySelector('select').value = value || '';
            } else if (variant === 'radio') {
                const inputs = this.node.querySelectorAll('input[type=radio]');
                for (let i = 0; i < inputs.length; i++) {
                    inputs[i].checked = inputs[i].value === value;
                }
            }
        } else if (type === 'country') {
            // TODO
        } else if (type === 'date') {
            this.node.querySelector('input').value = value;
        } else if (type === 'time') {
            this.node.querySelector('input').value = value;
        } else if (type === 'datetime') {
            const { tz } = this.node.dataset;
            const tzOffset = Number.isFinite(tz) ? tz : (new Date().getTimezoneOffset());
            const da = new Date(+value + tzOffset * 60000);
            this.node.querySelector('input').value = da.toISOString().replace(/Z$/, '');
        } else if (type === 'boolean_table') {
            const { cols, rows } = this.node.dataset;
            for (let y = 0; y < rows; y++) {
                for (let x = 0; x < cols; x++) {
                    const box = this.node.querySelector(`input[data-index="${x}-${y}"]`)
                    if (box) {
                        box.checked = value[y][x];
                    }
                }
            }
        }
    }

    getRequired() {
        return !!this.node.dataset.required;
    }

    setRequired(required) {
        const label = this.node.querySelector('label');
        const oldReq = label.querySelector('.label-required');
        this.node.dataset.required = required ? 'true' : '';
        if (oldReq && !required) label.removeChild(oldReq);
        else if (!oldReq && required) {
            const req = document.createElement('span');
            req.className = 'label-required';
            req.textContent = ' *';
            label.appendChild(req);
        }
    }

    setDisabled(disabled) {
        const { type } = this;
        if (type === 'boolean' || type === 'number' || type === 'money' || type === 'date' || type === 'time' || type === 'datetime') {
            this.node.querySelector('input').disabled = disabled;
        } else if (type === 'text') {
            const input = this.node.querySelector('input') || this.node.querySelector('textarea');
            input.disabled = disabled;
        } else if (type === 'enum') {
            const { variant } = this.node.dataset;
            if (variant === 'select') {
                this.node.querySelector('select').disabled = disabled;
            } else if (variant === 'radio') {
                const inputs = this.node.querySelectorAll('input[type=radio]');
                for (let i = 0; i < inputs.length; i++) {
                    const radio = inputs[i];
                    radio.disabled = disabled || radio.dataset.disabled;
                }
            }
        } else if (type === 'country') {
            this.node.querySelector('select').disabled = disabled;
        } else if (type === 'boolean_table') {
            const { cols, rows } = this.node.dataset;
            for (let y = 0; y < rows; y++) {
                for (let x = 0; x < cols; x++) {
                    const box = this.node.querySelector(`input[data-index="${x}-${y}"]`)
                    if (box) box.disabled = disabled;
                }
            }
        }
    }

    getValidationError() {
        const { type } = this;
        const isRequired = this.getRequired();
        const value = this.getValue();

        if (isRequired && value === null) {
            return localize('err_field_is_required');
        }

        if (value !== null) {
            if (type === 'boolean') {
                if (isRequired && !value) {
                    // booleans are special; required means they must be true
                    return localize('err_field_is_required');
                }
            } else if (type === 'number') {
                // Don't need to validate min/max/step because that's handled in HTML
            } else if (type === 'text') {
                const input = this.node.querySelector('input') || this.node.querySelector('textarea');
                const pattern = input.getAttribute('pattern');
                if (pattern) {
                    try {
                        const re = new RegExp(pattern);
                        if (!re.test(value)) {
                            return input.dataset.patternError || localize('err_text_pattern_generic');
                        }
                    } catch { /* ignore invalid regex */ }
                }

                // min/maxLength handled in HTML
            } else if (type === 'money') {
                // min/max/step handled in HTML
            } else if (type === 'enum') {
                // it might be that the server sent a bad default value
                // so we need to verify that the value is valid
                if (value) {
                    let options = [];
                    if (this.node.dataset.variant === 'select') {
                        options = this.node.querySelectorAll('select option');
                    } else if (this.node.dataset.variant === 'radio') {
                        options = this.node.querySelectorAll('input[type="radio"]');
                    }
                    for (let i = 0; i < options.length; i++) {
                        const option = options[i];
                        if (option.value === value) {
                            if (option.disabled) return localize('err_enum_not_in_set');
                        }
                    }
                }
            } else if (type === 'country') {
                // should always be valid
            } else if (type === 'date') {
                if ((value || isRequired) && !RE_DATE_FMT.test(value)) {
                    return localize('err_date_fmt');
                }
                // TODO: validate date range in Safari
            } else if (type === 'time') {
                if ((value || isRequired) && !RE_TIME_FMT.test(value)) {
                    return localize('err_time_fmt');
                }
                // TODO: validate time range in Safari
            } else if (type === 'datetime') {
                if ((value || isRequired) && !RE_DATETIME_FMT.test(value)) {
                    return localize('err_datetime_fmt');
                }
                // TODO: validate datetime range in Safari
            } else if (type === 'boolean_table') {
                let selected = 0;
                for (const row of value) {
                    for (const col of row) {
                        if (col) selected++;
                    }
                }

                const { minSelect, maxSelect } = this.node.dataset;

                let fulfillsMin = minSelect ? selected >= minSelect : true;
                let fulfillsMax = maxSelect ? selected <= maxSelect : true;

                if (minSelect && maxSelect) {
                    if (!fulfillsMin || !fulfillsMax) {
                        return localize('err_bool_table_select_range', minSelect, maxSelect);
                    }
                } else if (!fulfillsMin) {
                    return localize('err_bool_table_select_min', minSelect);
                } else if (!fulfillsMax) {
                    return localize('err_bool_table_select_max', maxSelect);
                }
            }
        }
        return null;
    }

    validate() {
        if (!this.didInteract) return;
        const error = this.getValidationError();
        this.setError(error);
        return !error;
    }

    setError(str) {
        const errorNode = this.node.querySelector('.field-error');
        if (errorNode && str) errorNode.textContent = str;
        else if (str) {
            const errorNode = document.createElement('div');
            errorNode.className = 'field-error';
            errorNode.textContent = str;
            this.node.appendChild(errorNode);
        } else if (errorNode) {
            this.node.removeChild(errorNode);
        }
    }
}

function initFormItem(node, onChange) {
    if (node.dataset.el === 'input') {
        const name = node.dataset.name;
        const type = node.dataset.type;

        return new FormInput(node, onChange);
    } else if (node.dataset.el === 'text') {
        let text = decodeScript(node.dataset.text);

        return {
            el: 'text',
            node,
            text,
        };
    } else if (node.dataset.el === 'script') {
        return {
            el: 'script',
            script: decodeScript(node.dataset.script),
        }
    } else {
        return { el: '' };
    }
}

function init() {
    const form = document.querySelector('form.akso-form');
    if (!form) return;
    const submitButton = form.querySelector('.submit-button');

    const meta = form.querySelector('.form-meta') || ({ dataset: {} });

    // these are probably in the correct order
    const qaFormItems = form.querySelectorAll('.form-item');
    const formItems = [];

    let update;
    let onChange = () => update();
    for (let i = 0; i < qaFormItems.length; i++) formItems.push(initFormItem(qaFormItems[i], onChange));

    update = (isSubmissionAttempt) => {
        let isValid = true;

        const scriptStack = [];
        const formVars = {
            '@created_time': meta.dataset.createdTime ? new Date(meta.dataset.createdTime * 1000) : null,
            '@edited_time': meta.dataset.editedTime ? new Date(meta.dataset.editedTime * 1000) : null,
            '@is_member': meta.dataset.isMember || false,
            ...JSON.parse(meta.dataset.customFormVars || '{}'),
        };

        const getFormValue = id => {
            return formVars[id] || null;
        };

        let firstInvalidInput = null;

        for (const item of formItems) {
            if (item.el === 'text') {
                if (item.text.includes('{{')) {
                    try {
                        item.node.innerHTML = md.render(renderScriptText(scriptStack, formVars, item.text));
                    } catch (err) {
                        console.error('Script eval error', err);
                        // error
                        // TODO: handle
                    }
                }

                if (item.script) {
                    try {

                        if (result) {
                            item.node.innerHTML = md.render(ascCastToString(result));
                        } else {
                            item.node.innerHTML = '';
                        }
                    } catch (err) {
                        console.error('Script eval error', err);
                        // error
                        // TODO: handle
                    }
                }
            } else if (item.el === 'input') {
                item.update(scriptStack, formVars);
                formVars[item.name] = item.getValue();
                if (!item.validate()) {
                    isValid = false;
                    if (!firstInvalidInput) firstInvalidInput = item;
                }
            } else if (item.el === 'script') {
                scriptStack.push(item.script);
            }
        }

        if (!isValid && isSubmissionAttempt && firstInvalidInput) {
            scrollNodeIntoView(firstInvalidInput.node);
        }

        return isValid;
    };
    update();

    if (submitButton) {
        submitButton.addEventListener('click', (e) => {
            // show all errors
            for (const item of formItems) item.didInteract = true;

            if (!update(true)) e.preventDefault();
        });
    }
}

function renderScriptText(scriptStack, formVars, text) {
  const ifRegex = /\{\{#if\s+(.+?)}}(.+?)(?:\{\{#else}}(.+?))?\{\{\/if}}/gs;
  const identifierRegex = /\{\{([^#/].*?)}}/gs;

  const emptyString = Symbol('empty string');
  const emptyStringDefs = {
      [emptyString]: { t: 's', v: '' },
  };

  return (text || '')
      .replace(ifRegex, (m, cond, body, body2) => {
          const condValue = ascEval(scriptStack, formVars, {
              t: 'c',
              f: 'id',
              a: [cond],
          });
          if (condValue) {
              return body;
          } else {
              return body2;
          }
      })
      .replace(identifierRegex, (m, id) => {
          const expr = {
              t: 'c',
              f: '++',
              a: [emptyString, id],
          };
          return ascEval(scriptStack.concat([emptyStringDefs]), formVars, expr);
      });
}

if (document.readyState === 'complete') init();
else window.addEventListener('DOMContentLoaded', init);
