import { registration_form as locale } from '../../../locale.ini';
import './index.less';

function showWarning(onComplete) {
    const dialog = document.createElement('div');
    dialog.className = 'payment-method-manual-warning-container';
    dialog.innerHTML = `
<div class="inner-dialog">
    <header>
        <button class="back-button inner-close-button">
            <span class="back-icon"></span>
            ${locale.payment_manual_warning_cancel}
        </button>
        <div class="inner-title">
            ${locale.payment_manual_warning_title}
        </div>
    </header>
    <div class="inner-content"></div>
</div>
    `;
    document.body.appendChild(dialog);

    const lines = [
        { text: locale.payment_manual_warning_line1, wait: 1000 },
        {
            text: locale.payment_manual_warning_line2,
            extra: `
<div class="inner-payment-button-preview">
    <div class="inner-payment-button">
        ${locale.payment_manual_warning_preview_button_label}
    </div>
</div>
            `,
            wait: 4000,
        },
        { text: locale.payment_manual_warning_line3, wait: 2000 },
        { text: locale.payment_manual_warning_line4, wait: 2000 },
    ];

    let nextLine;
    const appendLine = (line) => {
        const container = document.createElement('div');
        container.className = 'inner-line-container';
        container.innerHTML = `
<div class="inner-content">
    <div class="inner-text"></div>
    ${line.extra || ''}
</div>
<div class="inner-footer">
    <button class="inner-next-button">
        <div class="inner-button-label">
            ${locale.payment_manual_warning_button_next}
        </div>
        <div class="inner-button-prompt"></div>
    </button>
</div>
        `;
        container.querySelector('.inner-text').textContent = line.text;
        dialog.querySelector('.inner-content').appendChild(container);

        setTimeout(() => {
            container.querySelector('.inner-footer').classList.add('is-visible');
        }, line.wait);

        let didPressNext = false;
        container.querySelector('.inner-next-button').addEventListener('click', () => {
            if (didPressNext) return;
            didPressNext = true;
            container.querySelector('.inner-footer').classList.add('is-activated');
            setTimeout(() => {
                container.querySelector('.inner-footer').classList.add('is-next');
                nextLine();
            }, 500);
        });
    };

    const appendEnding = () => {
        const container = document.createElement('div');
        container.className = 'inner-end-container';
        const checkboxId = Math.random().toString();
        container.innerHTML = `
<div class="checkbox-confirmation">
    <input id="${checkboxId}" type="checkbox" class="inner-checkbox" />
    <label for="${checkboxId}">${locale.payment_manual_warning_checkbox}</label>
</div>
<div class="inner-footer">
    <button class="inner-submit-button is-primary">${locale.payment_manual_warning_confirm}</button>
</div>
        `;

        dialog.querySelector('.inner-content').appendChild(container);
        const checkbox = container.querySelector('.inner-checkbox');
        const submitButton = container.querySelector('.inner-submit-button');
        submitButton.addEventListener('click', () => {
            if (!checkbox.checked) {
                if (HTMLElement.prototype.scrollIntoView) {
                    dialog.querySelector('.inner-dialog').firstElementChild.scrollIntoView({ behavior: 'smooth' });
                } else {
                    dialog.querySelector('.inner-dialog').scrollTo(0, 0);
                }
                submitButton.classList.add('is-error');
                setTimeout(() => {
                    submitButton.classList.remove('is-error');
                }, 500);
                return;
            }

            onComplete(true);
        });
    };

    let cursor = 0;
    nextLine = () => {
        const line = lines[cursor];
        if (line) {
            appendLine(line);
        } else if (cursor === lines.length) {
            appendEnding();
        }
        cursor++;
    };
    nextLine();

    const closeButton = dialog.querySelector('.inner-close-button');
    closeButton.addEventListener('click', () => {
        document.body.removeChild(dialog);
        onComplete(false);
    });
}

function init() {
    const warningForms = document.querySelectorAll('.method-show-manual-warning');

    for (let i = 0; i < warningForms.length; i++) {
        const form = warningForms[i];
        let userDidAgree = false;

        form.addEventListener('submit', e => {
            if (userDidAgree) {
                userDidAgree = false;
                return;
            }

            e.preventDefault();
            showWarning((result) => {
                userDidAgree = result;
                if (userDidAgree) {
                    form.submit();
                    userDidAgree = false;
                }
            });
        });
    }
}

if (document.readyState === 'complete') init();
else window.addEventListener('DOMContentLoaded', init);