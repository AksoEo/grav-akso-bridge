import locale from '../../../locale.ini';
import './index.less';
import AudioPlayer from './audio-player';
import initEditionTocPreview from './toc-preview';

function init() {
    if (window.history && window.history.pushState && window.Element.prototype.scrollIntoView) {
        initEditionYearScroll();
    }

    const recitationItems = document.querySelectorAll('.entry-recitation');
    const audioPlayers = [];
    for (let i = 0; i < recitationItems.length; i++) {
        audioPlayers.push(new AudioPlayer(recitationItems[i], audioPlayers));
    }

    initEditionTocPreview();
}

function initEditionYearScroll() {
    const editionYearLinks = document.querySelectorAll('.edition-year-link');
    for (let i = 0; i < editionYearLinks.length; i++) {
        const link = editionYearLinks[i];
        link.addEventListener('click', e => {
            const href = link.getAttribute('href');
            if (!href.startsWith('#')) return;
            const target = document.getElementById(href.substr(1));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth' });
                setTimeout(() => {
                    target.classList.add('pulse');
                    setTimeout(() => {
                        target.classList.remove('pulse');
                    }, 1500);
                }, 400);
            }
        });
    }
}
if (document.readyState === 'complete') init();
else window.addEventListener('DOMContentLoaded', init);
