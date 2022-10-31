import locale from '../../../locale.ini';
import playIcon from './audio-play.svg';
import pauseIcon from './audio-pause.svg';
import audioBack15Icon from './audio-back15.svg';
import audioFwd15Icon from './audio-fwd15.svg';

const ICONS = {
    fwd15: '/user/plugins/akso-bridge/assets/audio-fwd15.svg',
    back15: '/user/plugins/akso-bridge/assets/audio-back15.svg',
};

function formatAudioDuration(s, total = 0) {
    total = Math.max(s, total);
    let out = '';
    const hourDigits = Math.floor(total / 3600).length;
    if (hourDigits) {
        let p = '';
        for (let i = 0; i < hourDigits; i++) p += '0';
        out += (p + Math.floor(s / 3600)).substr(-hourDigits) + ':';
    }
    out += ('00' + Math.floor((s % 3600) / 60)).substr(-2) + ':';
    out += ('00' + Math.floor(s)).substr(-2);
    return out;
}

export default class AudioPlayer {
    constructor (node, audioPlayers) {
        this.node = node;
        this.audioPlayers = audioPlayers;

        this.init();
    }

    init() {
        this.audio = this.node.querySelector('audio');
        this.author = this.node.querySelector('.recitation-author').textContent;

        this.node.classList.add('is-interactive-audio-player');
        this.audio.parentNode.removeChild(this.audio);
        this.node.innerHTML = `
<div class="magazines-inline-audio-player">
    <div class="audio-player-timeline-container">
        <div class="audio-player-timeline">
            <div class="player-loading"></div>
            <div class="player-buffers"></div>
            <div class="player-progress"></div>
            <div class="player-playhead"></div>
        </div>
    </div>
    <div class="audio-player-header">
        <div class="audio-buttons">
            <div class="audio-button-container">
                <button class="audio-button audio-back-button">
                    <div class="inner-icon inner-svg-container">
                        ${audioBack15Icon}
                    </div>
                </button>
            </div><!--
            --><div class="audio-button-container play-pause-container">
                <button class="audio-button play-pause-button">
                    <div class="inner-icon-container">
                        <div class="inner-icon is-playing inner-svg-container">
                            ${pauseIcon}
                        </div>
                        <div class="inner-icon is-paused inner-svg-container">
                            ${playIcon}
                        </div>
                    </div>
                </button>
            </div><!--
            --><div class="audio-button-container">
                <button class="audio-button audio-fwd-button">
                    <div class="inner-icon inner-svg-container">
                        ${audioFwd15Icon}
                    </div>
                </button>
            </div>
        </div>
        <div class="audio-player-description">
            <div class="audio-player-label"></div>
            <div class="audio-player-time">
                <span class="player-time-current"></span>
                <span class="player-time-separator">/</span>
                <span class="player-time-duration"></span>
            </div>
        </div>
    </div>
    <div class="audio-player-details-container">
        <div class="audio-player-details">
            <div class="audio-author"></div>
        </div>
    </div>
</div>
        `;

        this.player = this.node.querySelector('.magazines-inline-audio-player');
        this.player.classList.add('is-collapsed');
        this.audio.controls = false;
        this.node.appendChild(this.audio);
        this.timelineContainer = this.node.querySelector('.audio-player-timeline-container');
        this.timeline = this.node.querySelector('.audio-player-timeline');
        this.timelineProgress = this.node.querySelector('.audio-player-timeline .player-progress');
        this.timelinePlayhead = this.node.querySelector('.audio-player-timeline .player-playhead');
        this.timelineBuffers = this.node.querySelector('.audio-player-timeline .player-buffers');
        this.timelineBufferElements = [];
        this.playPauseButton = this.node.querySelector('.play-pause-button');
        this.node.querySelector('.audio-player-label').textContent = locale.magazines.entry_recitation_header;
        this.timeCurrent = this.node.querySelector('.player-time-current');
        this.timeDuration = this.node.querySelector('.player-time-duration');
        this.node.querySelector('.audio-author').textContent = locale.magazines.entry_recitation_read_by_0
            + this.author + locale.magazines.entry_recitation_read_by_1;
        this.node.querySelector('.play-pause-button').addEventListener('click', () => this.togglePlaying());
        this.node.querySelector('.audio-back-button').addEventListener('click', () => this.jumpRelative(-15));
        this.node.querySelector('.audio-fwd-button').addEventListener('click', () => this.jumpRelative(15));
        this.node.querySelector('.audio-back-button').disabled = true;
        this.node.querySelector('.audio-fwd-button').disabled = true;
        this.node.querySelector('.audio-back-button').setAttribute('aria-label', locale.magazines.entry_recitation_btn_back15);
        this.node.querySelector('.audio-fwd-button').setAttribute('aria-label', locale.magazines.entry_recitation_btn_fwd15);
        this.audio.addEventListener('timeupdate', () => this.update());
        this.audio.addEventListener('pause', () => this.update());
        this.audio.addEventListener('play', () => this.update());
        this.audio.addEventListener('loadedmetadata', () => this.update());
        this.audio.addEventListener('loadeddata', () => this.update());
        this.audio.addEventListener('error', () => this.update());
        this.audio.addEventListener('waiting', () => {
            this.waiting = true;
            this.update();
        });
        this.audio.addEventListener('playing', () => {
            this.waiting = false;
            this.update();
        });
        this.timelineContainer.addEventListener('touchstart', e => {
            e.preventDefault();
            this.pointerDown(e.touches[0].clientX, e.touches[0].clientY);
        });
        this.timelineContainer.addEventListener('touchmove', e => {
            e.preventDefault();
            this.pointerMove(e.touches[0].clientX, e.touches[0].clientY);
        });
        this.timelineContainer.addEventListener('touchend', () => this.pointerUp());
        const onMouseMove = e => {
            e.preventDefault();
            this.pointerMove(e.clientX, e.clientY);
        };
        const onMouseUp = () => {
            this.pointerUp();
            window.removeEventListener('mousemove', onMouseMove)
            window.removeEventListener('mouseup', onMouseUp);
        };
        this.timelineContainer.addEventListener('mousedown', e => {
            e.preventDefault();
            this.pointerDown(e.clientX, e.clientY);
            window.addEventListener('mousemove', onMouseMove)
            window.addEventListener('mouseup', onMouseUp);
        });
        this.update();
    }

    pointerDown(x, y) {
        this.player.classList.remove('is-collapsed');
        this.timelineContainer.classList.add('is-dragging');
        this.pointerIsDown = true;
        this.pointerMove(x, y);
    }
    pointerMove(x, y) {
        if (!this.pointerIsDown) return;
        const nodeRect = this.timelineContainer.getBoundingClientRect();
        x = x - nodeRect.left;
        y = y - nodeRect.top;
        if (this.audio.duration) {
            this.audio.currentTime = Math.max(0, Math.min(1, x / nodeRect.width)) * this.audio.duration;
        }
        this.update();
    }
    pointerUp() {
        this.pointerIsDown = false;
        this.timelineContainer.classList.remove('is-dragging');
    }

    expand() {
        this.player.classList.remove('is-collapsed');
        this.player.querySelector('.audio-back-button').disabled = false;
        this.player.querySelector('.audio-fwd-button').disabled = false;
        if (this.node.dataset.sticky) {
            this.node.classList.add('is-sticky');
        }
    }

    jumpRelative(t) {
        this.expand();
        const maxTime = Number.isFinite(this.audio.duration) ? this.audio.duration : 0;
        this.audio.currentTime = Math.min(Math.max(this.audio.currentTime + t, 0), maxTime);
        this.update();
    }

    togglePlaying() {
        this.expand();
        if (this.audio.paused) {
            for (const player of this.audioPlayers) {
                player.stopPlaying();
            }
            this.audio.play();
        } else {
            this.stopPlaying();
        }
    }

    stopPlaying() {
        this.audio.pause();
    }

    update() {
        const error = this.audio.error;

        const playing = !error && !this.audio.paused;
        this.playPauseButton.dataset.state = playing ? 'playing' : 'paused';
        this.playPauseButton.setAttribute('aria-label', playing
            ? locale.magazines.entry_recitation_btn_pause
            : locale.magazines.entry_recitation_btn_play);
        this.playPauseButton.dataset.waiting = !this.audio.duration || !!this.waiting;
        this.timeline.dataset.waiting = playing && (!this.audio.duration || !!this.waiting);

        const bufferedTimeRanges = [];
        for (let i = 0; i < this.audio.buffered.length; i++) {
            bufferedTimeRanges.push({ start: this.audio.buffered.start(i), end: this.audio.buffered.end(i) });
        }
        while (this.timelineBufferElements.length > bufferedTimeRanges.length) {
            this.timelineBuffers.removeChild(this.timelineBufferElements.pop());
        }
        for (let i = 0; i < bufferedTimeRanges.length; i++) {
            if (!this.timelineBufferElements[i]) {
                const el = document.createElement('div');
                el.className = 'player-buffer';
                this.timelineBuffers.appendChild(el);
                this.timelineBufferElements.push(el);
            }
            const range = bufferedTimeRanges[i];
            const el = this.timelineBufferElements[i];
            el.style.left = (range.start / this.audio.duration * 100) + '%';
            el.style.width = ((range.end -  range.start) / this.audio.duration * 100) + '%';
        }
        if (this.audio.duration) {
            this.timelinePlayhead.style.left =
            this.timelineProgress.style.width = (this.audio.currentTime / this.audio.duration * 100) + '%';
        } else {
            this.timelinePlayhead.style.left = this.timelineProgress.style.width = 0;
        }

        this.timeCurrent.textContent = formatAudioDuration(this.audio.currentTime, this.audio.duration);

        this.timeDuration.classList.remove('is-error');
        if (Number.isFinite(this.audio.duration)) {
            this.timeDuration.textContent = formatAudioDuration(this.audio.duration);
        } else if (error) {
            this.timeDuration.textContent = locale.magazines.entry_recitation_error;
            this.timeDuration.classList.add('is-error');
        } else {
            this.timeDuration.textContent = locale.magazines.entry_recitation_loading;
        }
    }
}

