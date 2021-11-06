import L from 'leaflet';
import './map-marker.less';

function pinShape (midY = 16, bottomY = 52) {
    // One half of the shape:
    //
    // midX    +circleCtrl
    //  x------o
    //    ...
    //       .. o
    //         .|
    //          |
    //          x midY
    //          |
    //         .|
    //        . o +circleCtrlDown
    //   o   .
    //   | +pinCtrlDX/pinCtrlDY
    //   |.
    //   |
    //   x bottomY +pinDX
    //   |
    //   o +pinCtrlDown
    //

    const midX = 18;
    const circleSize = 16;

    const circleCtrl = 7.84 / 16 * circleSize;
    const circleCtrlDown = 13.5 / 16 * circleSize;
    const pinDX = 1; // from center x
    const pinCtrlDX = 5; // from center x
    const pinCtrlDY = 14; // from circle center y
    const pinCtrlDown = 1.5;

    const topEdge = midY - circleSize;
    const rightEdge = midX + circleSize;
    const leftEdge = midX - circleSize;

    return [
        `M${midX},${topEdge}`,
        `C${midX + circleCtrl},${topEdge} ${rightEdge},${midY - circleCtrl} ${rightEdge},${midY}`,
        `C${rightEdge},${midY + circleCtrlDown} ${midX + pinCtrlDX},${midY + pinCtrlDY} ${midX + pinDX},${bottomY}`,
        `C${midX + pinDX},${bottomY + pinCtrlDown} ${midX - pinDX},${bottomY + pinCtrlDown} ${midX - pinDX},${bottomY}`,
        `C${midX - pinCtrlDX},${midY + pinCtrlDY} ${leftEdge},${midY + circleCtrlDown} ${leftEdge},${midY}`,
        `C${leftEdge},${midY - circleCtrl} ${midX - circleCtrl},${topEdge} ${midX},${topEdge}`,
    ].join(' ');
}

class Spring {
    constructor(f, c) {
        this.force = f;
        this.damping = c;
        this.value = 0;
        this.target = 0;
        this.velocity = 0;
    }
    update(dt) {
        const TIME_STEP = 1 / 360;
        dt = Math.min(1 / 30, dt);
        while (true) {
            const step = Math.min(TIME_STEP, dt);

            this.velocity += (this.force * (this.target - this.value) - this.damping * this.velocity) * step;
            this.value += this.velocity * step;

            dt -= step;
            if (dt <= 0) break;
        }
    }
    wantsUpdate() {
        return Math.abs(this.target - this.value) + Math.abs(this.velocity) > 1 / 1000;
    }
}

const animationList = [];
let animationLoopId = null;
function registerAnimation(node) {
    if (animationList.includes(node)) return;
    animationList.push(node);
    if (animationLoopId === null) startAnimation();
}
function unregisterAnimation(node) {
    const index = animationList.indexOf(node);
    if (index !== -1) animationList.splice(index, 1);
}
let lastTime = 0;
let syncFrame = 0;
function animationLoop(id) {
    if (id !== animationLoopId) return;
    requestAnimationFrame(() => animationLoop(id));
    const dt = (Date.now() - lastTime) / 1000;
    lastTime = Date.now();

    if (animationList.length) {
        syncFrame = 0;
        for (const item of animationList) {
            try {
                item.update(dt);
            } catch (err) {
                console.error('Error in animation: ', err);
            }
        }
    } else {
        syncFrame++;
    }

    if (syncFrame > 12) {
        animationLoopId = null;
    }
}
function startAnimation() {
    lastTime = Date.now();
    animationLoopId = Math.random().toString();
    animationLoop(animationLoopId);
}

export class Marker {
    constructor(makeIconSrc) {
        this.makeIconSrc = makeIconSrc;
        this.portalContainer = document.createElement('div');
        this.portalIcon = L.divIcon({
            className: 'a-map-pin-icon',
            html: this.portalContainer,
        });
        this.highlight = new Spring(438.65, 20.94);
        this.iconSize = new Spring(438.65, 33.51);

        this.mapPinInner = document.createElement('div');
        this.mapPinInner.className = 'map-pin-inner';
        this.mapPinInnerPinShape = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg');
        this.mapPinInnerPinShape.setAttribute('class', 'pin-shape');
        this.mapPinInnerPinShape.setAttribute('width', 36);
        this.mapPinInnerPinShape.setAttribute('height', 82);
        this.mapPinInner.appendChild(this.mapPinInnerPinShape);
        this.mapPinInnerPinShapePath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        this.mapPinInnerPinShapePath.setAttribute('class', 'pin-shape-path');
        this.mapPinInnerPinShape.appendChild(this.mapPinInnerPinShapePath);
        this.mapPinInnerPinIconContainer = document.createElement('div');
        this.mapPinInnerPinIconContainer.className = 'pin-icon-container';
        this.mapPinInner.appendChild(this.mapPinInnerPinIconContainer);
        this.mapPinInnerPinIcon = null;

        this.portalContainer.appendChild(this.mapPinInner);

        this.highlighted = false;
        this.icon = null;

        this.didMutate();
        this.render();
    }
    didMutate() {
        registerAnimation(this);
    }
    update(dt) {
        this.highlight.target = this.highlighted ? 1 : 0;
        this.iconSize.target = this.icon ? 1 : 0;
        this.highlight.update(dt);
        this.iconSize.update(dt);
        const wantsUpdate = this.highlight.wantsUpdate() || this.iconSize.wantsUpdate();
        if (!wantsUpdate) {
            unregisterAnimation(this);
        }
        this.render();
    }
    render() {
        if (this.highlighted) this.mapPinInner.classList.add('is-highlighted');
        else this.mapPinInner.classList.remove('is-highlighted');

        const shapeBottomY = 80;
        const shapeCircleY = shapeBottomY - 36 - (this.highlight.value * 12);
        const iconScale = 0.4 + this.iconSize.value * 0.6;
        this.mapPinInnerPinShapePath.setAttribute('d', pinShape(shapeCircleY, shapeBottomY));
        this.mapPinInnerPinIconContainer.style.transform = `translateY(${shapeCircleY}px) scale(${iconScale})`;

        if (this.icon) {
            this.mapPinInnerPinIconContainer.classList.remove('is-empty');
            if (!this.mapPinInnerPinIcon) {
                this.mapPinInnerPinIcon = new Image();
                this.mapPinInnerPinIcon.src = this.makeIconSrc(this.icon);
                this.mapPinInnerPinIconContainer.appendChild(this.mapPinInnerPinIcon);
            }
        } else {
            this.mapPinInnerPinIconContainer.classList.add('is-empty');
            if (this.mapPinInnerPinIcon) {
                this.mapPinInnerPinIconContainer.removeChild(this.mapPinInnerPinIcon);
                this.mapPinInnerPinIcon = null;
            }
        }
    }
}
