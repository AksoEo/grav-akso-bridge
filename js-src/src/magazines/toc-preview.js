import locale from '../../../locale.ini';
import './toc-preview.less';
import 'regenerator-runtime/runtime.js';

function initEdition(edition) {
    const innerCard = edition.querySelector('.item-inner-card');
    const previewButtonContainer = document.createElement('div');
    previewButtonContainer.className = 'toc-preview-button-container';
    const previewButton = document.createElement('button');
    previewButton.textContent = locale.magazines.edition_toc_preview_button;
    previewButton.className = 'toc-preview-button';
    previewButtonContainer.appendChild(previewButton);
    innerCard.appendChild(previewButtonContainer);

    previewButton.addEventListener('click', () => {
        openTocPreview(edition.querySelector('.magazine-cover-container'), edition.dataset.name, edition.dataset.path);
    });
}

export default function initTocPreview() {
    const editions = document.querySelectorAll('.magazine-edition-item');
    for (let i = 0; i < editions.length; i++) {
        initEdition(editions[i]);
    }
}

function openTocPreview(originalCoverNode, editionName, originalPath) {
    const container = document.createElement('div');
    container.className = 'toc-preview-popup-container';
    const backdrop = document.createElement('div');
    backdrop.className = 'preview-backdrop';
    const preview = document.createElement('div');
    preview.className = 'toc-preview';
    container.appendChild(backdrop);
    container.appendChild(preview);
    document.body.appendChild(container);

    preview.innerHTML = `
<button class="close-button"></button>
<div class="cover-container"></div>
<div class="preview-inner">
    <div class="header-title">
        <h2 class="edition-title"></h2>
        <a class="link-button edition-open-link"></a>
    </div>
    <h3 class="contents-title"></h3>
    <div class="preview-contents"></div>
</div>
    `;

    preview.querySelector('.edition-title').textContent = editionName;
    preview.querySelector('.contents-title').textContent = locale.magazines.edition_toc_preview_title;
    preview.querySelector('.edition-open-link').textContent = locale.magazines.edition_toc_preview_open;
    preview.querySelector('.edition-open-link').href = originalPath;

    const destinationCoverNode = originalCoverNode.cloneNode(true);
    preview.querySelector('.cover-container').appendChild(destinationCoverNode);

    const transitionNode = document.createElement('div');
    container.appendChild(transitionNode);
    transitionNode.className = 'transition-node';
    const transitionCoverNode = document.createElement('div');
    transitionCoverNode.className = 'transition-cover-node';
    transitionCoverNode.appendChild(originalCoverNode.cloneNode(true));
    container.appendChild(transitionCoverNode);

    const clamp = (x, a, b) => Math.max(a, Math.min(x, b));
    const lerp = (a, b, t) => (b - a) * t + a;
    const renderOpenAnimation = (t, activity) => {
        container.style.opacity = t > 0.5 ? 1 : clamp(t * 50, 0, 1);
        transitionNode.style.opacity = t > 0.5 ? clamp(activity * 1000, 0, 1) : 1;
        preview.style.opacity = t > 0.5 ? 1 - clamp((1 - t) * 10, 0, 1) : 0;
        transitionCoverNode.style.opacity = t > 0.5 ? (activity ? 1 : 0) : 1;
        destinationCoverNode.style.opacity = activity ? 0 : 1;
        backdrop.style.opacity = clamp(t, 0, 1);

        const originalRect = originalCoverNode.getBoundingClientRect();
        const targetRect = preview.getBoundingClientRect();
        const targetCoverRect = destinationCoverNode.getBoundingClientRect();

        const px = lerp(originalRect.left, targetRect.left, t);
        const py = lerp(originalRect.top, targetRect.top, t);
        const pw = lerp(originalRect.width, targetRect.width, t);
        const ph = lerp(originalRect.height, targetRect.height, t);
        transitionNode.style.transform = `translate(${px}px, ${py}px)`;
        transitionNode.style.width = pw + 'px';
        transitionNode.style.height = ph + 'px';

        const cx = lerp(originalRect.left, targetCoverRect.left, t);
        const cy = lerp(originalRect.top, targetCoverRect.top, t);
        const csx = lerp(originalRect.width / targetCoverRect.width, 1, t);
        const csy = lerp(originalRect.height / targetCoverRect.height, 1, t);
        transitionCoverNode.style.transform = `translate(${cx}px, ${cy}px) scale(${csx}, ${csy})`;
    };

    const animationState = { key: 0, x: 0, v: 0, t: 1 };
    let lastTime = Date.now();
    const updateOpenAnimation = (key) => {
        if (animationState.key !== key) return;
        const dt = Math.min((Date.now() - lastTime) / 1000, 1 / 30);
        lastTime = Date.now();

        const k = 193;
        const c = 21;
        const f = -k * (animationState.x - animationState.t) - c * animationState.v;
        animationState.v += f * dt;
        animationState.x += animationState.v * dt;

        let activity = Math.abs(animationState.x - animationState.t) + Math.abs(animationState.v);
        if (activity >= 0.002) {
            requestAnimationFrame(() => updateOpenAnimation(key));
        } else {
            animationState.x = animationState.t;
            activity = 0;

            if (animationState.t == 0) {
                // closed
                container.parentNode.removeChild(container);
            }
        }
        renderOpenAnimation(animationState.x, activity);
    };
    updateOpenAnimation(++animationState.key);

    const close = () => {
        animationState.t = 0;
        updateOpenAnimation(++animationState.key);
    };
    backdrop.addEventListener('click', close);
    preview.querySelector('.close-button').addEventListener('click', close);

    const contents = preview.querySelector('.preview-contents');

    {
        const notice = document.createElement('div');
        notice.className = 'contents-notice';
        notice.textContent = locale.magazines.edition_toc_preview_loading;
        contents.appendChild(notice);
    }

    fetch(originalPath + '?js_toc_preview=1').then(async res => {
        if (!res.ok) throw await res.text();
        return res.json();
    }).then(data => {
        contents.innerHTML = '';

        for (const item of data.highlights) {
            const node = document.createElement('div');
            node.className = 'toc-item';
            contents.appendChild(node);

            node.innerHTML = `
            <div class="item-header">
                <span class="item-title"></span>
                <span class="item-dots"></span>
                <span class="item-page"></span>
            </div>
            `;
            node.querySelector('.item-title').innerHTML = item.title_rendered;
            node.querySelector('.item-page').textContent = item.page;
        }

        if (!data.highlights.length) {
            const notice = document.createElement('div');
            notice.className = 'contents-notice';
            notice.textContent = locale.magazines.edition_toc_preview_toc_empty;
            contents.appendChild(notice);
        }
    }).catch(error => {
        contents.innerHTML = '';
        const notice = document.createElement('div');
        notice.className = 'contents-notice is-error';
        notice.textContent = locale.magazines.edition_toc_preview_error;
        contents.appendChild(notice);
    });
}
