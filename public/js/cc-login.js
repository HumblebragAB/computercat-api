    document.addEventListener('DOMContentLoaded', function() {
        const cat = document.getElementById('the-cat');
        const track = document.getElementById('cat-track');
        const fly = document.getElementById('the-fly');
        const zzz = document.getElementById('cat-zzz');
        if (!cat || !track) return;

        const CAT_W = 80;
        const SPEED = 60; // px per second
        let trackW = track.offsetWidth;
        let x = Math.random() * (trackW - CAT_W);
        let state = 'idle';
        let facingLeft = false;
        let animFrame = null;
        let stateTimeout = null;

        window.addEventListener('resize', () => { trackW = track.offsetWidth; });

        function setX(val) {
            x = Math.max(0, Math.min(val, trackW - CAT_W));
            cat.style.left = x + 'px';
        }

        function setFacing(left) {
            facingLeft = left;
            cat.classList.toggle('facing-left', left);
        }

        function setState(s) {
            cat.classList.remove('walking', 'sitting', 'sleeping', 'falling', 'grooming', 'spinning', 'pouncing', 'peeking');
            if (s !== 'peek' && s !== 'fall') { cat.style.bottom = '0'; }
            state = s;
            if (s === 'walking') cat.classList.add('walking');
            if (s === 'sit' || s === 'chase') cat.classList.add('sitting');
            if (s === 'sleep') { cat.classList.add('sitting', 'sleeping'); }
            if (s === 'fall') cat.classList.add('falling');
            if (s === 'groom') cat.classList.add('sitting', 'grooming');
            if (s === 'spin') cat.classList.add('spinning');
            if (s === 'pounce') cat.classList.add('pouncing');
            if (s === 'peek') cat.classList.add('sitting', 'peeking');
        }

        // -- BEHAVIORS --

        function walkTo(targetX, cb) {
            setState('walking');
            setFacing(targetX < x);
            const startX = x;
            const dist = Math.abs(targetX - startX);
            const duration = (dist / SPEED) * 1000;
            const startTime = performance.now();

            function step(now) {
                const elapsed = now - startTime;
                const progress = Math.min(elapsed / duration, 1);
                setX(startX + (targetX - startX) * progress);
                if (progress < 1) {
                    animFrame = requestAnimationFrame(step);
                } else {
                    animFrame = null;
                    cb && cb();
                }
            }
            animFrame = requestAnimationFrame(step);
        }

        function doWalk() {
            const target = Math.random() * (trackW - CAT_W);
            walkTo(target, () => nextBehavior());
        }

        function doSit() {
            setState('sit');
            stateTimeout = setTimeout(() => nextBehavior(), 2000 + Math.random() * 3000);
        }

        function doSleep() {
            setState('sleep');
            zzz.textContent = 'z Z z';
            stateTimeout = setTimeout(() => {
                zzz.textContent = '';
                nextBehavior();
            }, 4000 + Math.random() * 4000);
        }

        function doChase() {
            // Fly appears and bounces around, cat follows
            fly.classList.add('active');
            setState('walking');

            let flyX = Math.random() * (trackW - 20);
            let flyY = 10 + Math.random() * 30;
            fly.style.left = flyX + 'px';
            fly.style.top = flyY + 'px';

            let bounces = 0;
            const maxBounces = 3 + Math.floor(Math.random() * 3);

            function chaseBounce() {
                flyX = Math.random() * (trackW - 20);
                flyY = 5 + Math.random() * 35;
                fly.style.transition = 'left 0.3s ease-out, top 0.3s ease-out';
                fly.style.left = flyX + 'px';
                fly.style.top = flyY + 'px';

                walkTo(Math.max(0, Math.min(flyX - 30, trackW - CAT_W)), () => {
                    bounces++;
                    if (bounces < maxBounces) {
                        stateTimeout = setTimeout(chaseBounce, 200 + Math.random() * 400);
                    } else {
                        // Cat catches fly
                        fly.style.left = (x + 40) + 'px';
                        fly.style.top = '30px';
                        setState('sit');
                        stateTimeout = setTimeout(() => {
                            fly.classList.remove('active');
                            fly.style.transition = '';
                            showSpeech('*munch*');
                            stateTimeout = setTimeout(() => nextBehavior(), 1500);
                        }, 500);
                    }
                });
            }
            chaseBounce();
        }

        function doGroom() {
            setState('groom');
            showSpeech('*lick*');
            stateTimeout = setTimeout(() => nextBehavior(), 3000 + Math.random() * 2000);
        }

        function doTailChase() {
            const currentTrack = isOnBottom ? bottomTrack : track;
            if (currentTrack) currentTrack.style.overflow = 'visible';
            setState('spin');
            showSpeech('?!');
            const spins = 3 + Math.floor(Math.random() * 4);
            stateTimeout = setTimeout(() => {
                if (currentTrack) currentTrack.style.overflow = 'hidden';
                setState('sit');
                showSpeech('*dizzy*');
                stateTimeout = setTimeout(() => nextBehavior(), 1500);
            }, spins * 600);
        }

        function doPounce() {
            // Wiggle butt, then pounce forward
            setState('sit');
            const pounceDir = Math.random() > 0.5;
            setFacing(pounceDir);

            // Butt wiggle phase
            let wiggles = 0;
            const wiggleInterval = setInterval(() => {
                cat.style.transform = (facingLeft ? 'scaleX(-1) ' : '') + 'translateX(' + (wiggles % 2 === 0 ? '2' : '-2') + 'px)';
                wiggles++;
                if (wiggles >= 6) {
                    clearInterval(wiggleInterval);
                    cat.style.transform = facingLeft ? 'scaleX(-1)' : '';
                    // Pounce!
                    setState('pounce');
                    const jumpDist = 60 + Math.random() * 80;
                    const targetX = facingLeft ? x - jumpDist : x + jumpDist;
                    setTimeout(() => {
                        setX(targetX);
                        setTimeout(() => {
                            setState('sit');
                            const caught = Math.random() > 0.5;
                            showSpeech(caught ? '*gotcha!*' : '*missed*');
                            stateTimeout = setTimeout(() => nextBehavior(), 1500);
                        }, 400);
                    }, 50);
                }
            }, 100);
        }

        function doPeek() {
            // Walk to edge and peek down
            const edge = Math.random() > 0.5 ? trackW - CAT_W : 0;
            walkTo(edge, () => {
                setState('peek');
                setFacing(edge === 0);
                // Slowly lower down to peek
                cat.style.bottom = '-16px';
                showSpeech('...');
                stateTimeout = setTimeout(() => {
                    // Look around
                    showSpeech('hmm');
                    stateTimeout = setTimeout(() => {
                        // Come back up
                        cat.style.bottom = '0';
                        stateTimeout = setTimeout(() => nextBehavior(), 800);
                    }, 1500);
                }, 1500);
            });
        }

        const bottomTrack = document.getElementById('cat-track-bottom');
        let isOnBottom = false;

        function moveToBottomTrack() {
            if (!bottomTrack) return;
            isOnBottom = true;
            bottomTrack.appendChild(cat);
            bottomTrack.appendChild(fly);
            cat.style.transition = 'none';
            cat.style.bottom = '0';
            cat.style.transform = '';
            setX(Math.random() * (trackW - CAT_W));
            setFacing(false);
        }

        function moveToTopTrack() {
            isOnBottom = false;
            track.appendChild(cat);
            track.appendChild(fly);
            cat.style.transition = 'none';
            cat.style.bottom = '0';
            cat.style.transform = '';
        }

        function doFall() {
            // Walk to edge, look down, then fall using physics
            const edge = Math.random() > 0.5 ? trackW - CAT_W : 0;
            walkTo(edge, () => {
                setState('sit');
                setFacing(edge === 0);
                showSpeech('...');
                // Pause at edge, then fall with physics
                stateTimeout = setTimeout(() => {
                    // Get cat's current screen position
                    const catRect = cat.getBoundingClientRect();

                    // Move cat to fixed position on body
                    cat.classList.remove('walking', 'sitting', 'sleeping', 'falling', 'grooming', 'spinning', 'pouncing', 'peeking');
                    cat.classList.add('free-fall');
                    cat.style.position = 'fixed';
                    cat.style.left = catRect.left + 'px';
                    cat.style.top = catRect.top + 'px';
                    cat.style.bottom = 'auto';
                    cat.style.transition = 'none';
                    document.body.appendChild(cat);

                    showSpeech('AAAH!');

                    // Use physics engine to fall
                    velX = (facingLeft ? -1 : 1) * (30 + Math.random() * 60);
                    velY = 0;
                    let px = catRect.left;
                    let py = catRect.top;
                    let lastTime = performance.now();

                    function fallStep(now) {
                        const dt = Math.min((now - lastTime) / 1000, 0.05);
                        lastTime = now;

                        velY += GRAVITY * dt;
                        velX *= (1 - (1 - FRICTION) * dt * 10);
                        px += velX * dt;
                        py += velY * dt;

                        const vw = window.innerWidth;
                        const vh = window.innerHeight;

                        // Floor
                        if (py > vh - 48) {
                            py = vh - 48;
                            velY = -velY * BOUNCE;
                            velX *= FRICTION;
                            if (Math.abs(velY) < 60) velY = 0;
                        }
                        // Walls
                        if (px < 0) { px = 0; velX = -velX * BOUNCE; }
                        if (px > vw - CAT_W) { px = vw - CAT_W; velX = -velX * BOUNCE; }

                        cat.style.left = px + 'px';
                        cat.style.top = py + 'px';

                        // Spin while falling fast
                        const speed = Math.sqrt(velX * velX + velY * velY);
                        if (speed > 200) {
                            const angle = Math.atan2(velY, velX) * (180 / Math.PI);
                            cat.style.transform = 'rotate(' + angle + 'deg)';
                        } else {
                            cat.style.transform = '';
                        }

                        // Settled on floor?
                        if (Math.abs(velX) < MIN_VEL && Math.abs(velY) < MIN_VEL && py >= vh - 50) {
                            cat.style.transform = '';
                            cat.classList.remove('free-fall');
                            cat.classList.add('sitting');
                            showSpeech('Ow!');
                            floorX = px;
                            stateTimeout = setTimeout(() => doFloorBehaviors(0), 1500);
                            return;
                        }

                        physicsFrame = requestAnimationFrame(fallStep);
                    }

                    physicsFrame = requestAnimationFrame(fallStep);
                }, 800);
            });
        }

        function doBottomBehavior(count) {
            // Do a few random things at the bottom, then climb back up
            if (count >= 2 + Math.floor(Math.random() * 3)) {
                // Time to climb back up
                doClimbBack();
                return;
            }

            const r = Math.random();
            if (r < 0.3) {
                // Walk around at bottom
                const target = Math.random() * (trackW - CAT_W);
                walkTo(target, () => {
                    stateTimeout = setTimeout(() => doBottomBehavior(count + 1), 500);
                });
            } else if (r < 0.5) {
                // Sit and look up
                setState('sit');
                showSpeech('how do I get up?');
                stateTimeout = setTimeout(() => doBottomBehavior(count + 1), 2000);
            } else if (r < 0.7) {
                // Groom
                setState('groom');
                showSpeech('*lick*');
                stateTimeout = setTimeout(() => doBottomBehavior(count + 1), 2500);
            } else if (r < 0.85) {
                // Tail chase at bottom
                setState('spin');
                showSpeech('?!');
                stateTimeout = setTimeout(() => {
                    setState('sit');
                    showSpeech('*dizzy*');
                    stateTimeout = setTimeout(() => doBottomBehavior(count + 1), 1000);
                }, 2400);
            } else {
                // Sleep briefly
                setState('sleep');
                zzz.textContent = 'z Z z';
                stateTimeout = setTimeout(() => {
                    zzz.textContent = '';
                    doBottomBehavior(count + 1);
                }, 3000);
            }
        }

        function doClimbBack() {
            // Walk to edge, "jump" back to top
            const edge = Math.random() > 0.5 ? 0 : trackW - CAT_W;
            walkTo(edge, () => {
                setState('sit');
                showSpeech('*jump!*');
                stateTimeout = setTimeout(() => {
                    if (bottomTrack) bottomTrack.style.overflow = 'visible';
                    cat.style.transition = 'bottom 0.6s ease-out';
                    cat.style.bottom = '400px';

                    stateTimeout = setTimeout(() => {
                        if (bottomTrack) bottomTrack.style.overflow = 'hidden';
                        moveToTopTrack();
                        setX(edge);
                        setState('sit');
                        showSpeech('Made it!');
                        stateTimeout = setTimeout(() => nextBehavior(), 1500);
                    }, 700);
                }, 500);
            });
        }

        function nextBehavior() {
            if (animFrame) { cancelAnimationFrame(animFrame); animFrame = null; }
            if (stateTimeout) { clearTimeout(stateTimeout); stateTimeout = null; }

            const r = Math.random();
            if (r < 0.22)      doWalk();
            else if (r < 0.35) doSit();
            else if (r < 0.45) doSleep();
            else if (r < 0.58) doChase();
            else if (r < 0.68) doGroom();
            else if (r < 0.78) doPounce();
            else if (r < 0.86) doTailChase();
            else if (r < 0.94) doPeek();
            else               doFall();
        }

        // -- SPEECH --
        const meows = ['Meow!', '*purr*', 'Login pls', 'Feed me.', 'Ship it!', '> sudo...', ':3', 'Nya~'];
        let speechEl = null;

        function showSpeech(text) {
            if (speechEl) speechEl.remove();
            speechEl = document.createElement('div');
            speechEl.className = 'cc-speech show';
            speechEl.textContent = text;
            cat.appendChild(speechEl);
            setTimeout(() => { if (speechEl) { speechEl.remove(); speechEl = null; } }, 2000);
        }

        // -- DRAG & PHYSICS --
        let isDragging = false;
        let dragStartX = 0, dragStartY = 0;
        let dragOffsetX = 0, dragOffsetY = 0;
        let dragMoved = false;
        let velX = 0, velY = 0;
        let lastDragX = 0, lastDragY = 0;
        let lastDragTime = 0;
        let physicsFrame = null;

        const GRAVITY = 1200; // px/s^2
        const BOUNCE = 0.5;
        const FRICTION = 0.85;
        const MIN_VEL = 20;

        function startDrag(clientX, clientY) {
            // Stop all current behavior
            if (animFrame) { cancelAnimationFrame(animFrame); animFrame = null; }
            if (stateTimeout) { clearTimeout(stateTimeout); stateTimeout = null; }
            if (physicsFrame) { cancelAnimationFrame(physicsFrame); physicsFrame = null; }

            isDragging = true;
            dragMoved = false;

            const catRect = cat.getBoundingClientRect();
            dragOffsetX = clientX - catRect.left;
            dragOffsetY = clientY - catRect.top;
            dragStartX = clientX;
            dragStartY = clientY;

            // Move cat to fixed position on the page
            cat.classList.remove('walking', 'sitting', 'sleeping', 'falling', 'grooming', 'spinning', 'pouncing', 'peeking', 'facing-left');
            cat.classList.add('dragging');
            cat.style.position = 'fixed';
            cat.style.left = (clientX - dragOffsetX) + 'px';
            cat.style.top = (clientY - dragOffsetY) + 'px';
            cat.style.bottom = 'auto';
            cat.style.transition = 'none';
            cat.style.transform = '';
            document.body.appendChild(cat);

            velX = 0; velY = 0;
            lastDragX = clientX;
            lastDragY = clientY;
            lastDragTime = performance.now();

            showSpeech('Mrrp!');
        }

        function moveDrag(clientX, clientY) {
            if (!isDragging) return;
            const dx = clientX - dragStartX;
            const dy = clientY - dragStartY;
            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) dragMoved = true;

            cat.style.left = (clientX - dragOffsetX) + 'px';
            cat.style.top = (clientY - dragOffsetY) + 'px';

            // Track velocity
            const now = performance.now();
            const dt = (now - lastDragTime) / 1000;
            if (dt > 0.005) {
                velX = (clientX - lastDragX) / dt;
                velY = (clientY - lastDragY) / dt;
                lastDragX = clientX;
                lastDragY = clientY;
                lastDragTime = now;
            }

            // Flip cat based on drag direction
            if (Math.abs(velX) > 50) {
                cat.style.transform = velX < 0 ? 'scaleX(-1)' : '';
            }
        }

        function endDrag() {
            if (!isDragging) return;
            isDragging = false;
            cat.classList.remove('dragging');
            cat.classList.add('free-fall');

            // Cap velocity
            const maxVel = 2000;
            velX = Math.max(-maxVel, Math.min(maxVel, velX));
            velY = Math.max(-maxVel, Math.min(maxVel, velY));

            showSpeech(Math.abs(velY) > 400 ? 'AAAH!' : 'Whoa!');

            // Start physics
            let px = parseFloat(cat.style.left);
            let py = parseFloat(cat.style.top);
            let lastTime = performance.now();
            let bounceCount = 0;
            let settled = false;

            function physicsStep(now) {
                const dt = Math.min((now - lastTime) / 1000, 0.05);
                lastTime = now;

                // Apply gravity
                velY += GRAVITY * dt;

                // Apply air friction to horizontal
                velX *= (1 - (1 - FRICTION) * dt * 10);

                px += velX * dt;
                py += velY * dt;

                const vw = window.innerWidth;
                const vh = window.innerHeight;

                // Floor collision
                if (py > vh - 48) {
                    py = vh - 48;
                    velY = -velY * BOUNCE;
                    velX *= FRICTION;
                    bounceCount++;
                    if (Math.abs(velY) < 60) velY = 0;
                }

                // Wall collisions
                if (px < 0) { px = 0; velX = -velX * BOUNCE; }
                if (px > vw - CAT_W) { px = vw - CAT_W; velX = -velX * BOUNCE; }

                // Ceiling
                if (py < 0) { py = 0; velY = -velY * BOUNCE; }

                cat.style.left = px + 'px';
                cat.style.top = py + 'px';

                // Spin cat based on velocity
                const speed = Math.sqrt(velX * velX + velY * velY);
                if (speed > 200) {
                    const angle = Math.atan2(velY, velX) * (180 / Math.PI);
                    cat.style.transform = 'rotate(' + angle + 'deg)';
                } else {
                    cat.style.transform = '';
                }

                // Check if settled
                if (Math.abs(velX) < MIN_VEL && Math.abs(velY) < MIN_VEL && py >= vh - 50) {
                    settled = true;
                }

                // Check if cat lands on the card top edge
                const card = document.getElementById('login-card');
                if (card) {
                    const cardRect = card.getBoundingClientRect();
                    const catCenterX = px + CAT_W / 2;
                    const catBottom = py + 48;

                    // Landing on top of card
                    if (catBottom >= cardRect.top - 5 && catBottom <= cardRect.top + 15 &&
                        catCenterX > cardRect.left && catCenterX < cardRect.right &&
                        velY > 0) {
                        // Snap back to top track!
                        returnToTrack();
                        return;
                    }
                }

                if (settled) {
                    cat.style.transform = '';
                    cat.classList.remove('free-fall');
                    cat.classList.add('sitting');
                    showSpeech('Ow!');
                    floorX = px;
                    stateTimeout = setTimeout(() => doFloorBehaviors(0), 1500);
                    return;
                }

                physicsFrame = requestAnimationFrame(physicsStep);
            }

            physicsFrame = requestAnimationFrame(physicsStep);
        }

        // -- FLOOR BEHAVIORS (cat is position:fixed at bottom of viewport) --
        let floorX = 0;

        function floorSetX(val) {
            floorX = Math.max(0, Math.min(val, window.innerWidth - CAT_W));
            cat.style.left = floorX + 'px';
        }

        function doFloorBehaviors(count) {
            if (count >= 3 + Math.floor(Math.random() * 3)) {
                // Jump back to card
                doFloorJumpBack();
                return;
            }

            const r = Math.random();
            if (r < 0.3) {
                // Walk around on floor
                cat.classList.remove('sitting', 'sleeping', 'grooming', 'spinning');
                cat.classList.add('walking');
                const target = Math.random() * (window.innerWidth - CAT_W);
                const facingL = target < floorX;
                cat.style.transform = facingL ? 'scaleX(-1)' : '';
                const dist = Math.abs(target - floorX);
                const duration = (dist / SPEED) * 1000;
                const startX = floorX;
                const startTime = performance.now();

                function floorWalk(now) {
                    const progress = Math.min((now - startTime) / duration, 1);
                    floorSetX(startX + (target - startX) * progress);
                    if (progress < 1) {
                        animFrame = requestAnimationFrame(floorWalk);
                    } else {
                        animFrame = null;
                        stateTimeout = setTimeout(() => doFloorBehaviors(count + 1), 500);
                    }
                }
                animFrame = requestAnimationFrame(floorWalk);
            } else if (r < 0.45) {
                // Sit and look up at card
                cat.classList.remove('walking', 'grooming', 'spinning');
                cat.classList.add('sitting');
                cat.style.transform = '';
                showSpeech('how do I get up?');
                stateTimeout = setTimeout(() => doFloorBehaviors(count + 1), 2500);
            } else if (r < 0.6) {
                // Groom
                cat.classList.remove('walking', 'sitting', 'spinning');
                cat.classList.add('sitting', 'grooming');
                cat.style.transform = '';
                showSpeech('*lick*');
                stateTimeout = setTimeout(() => doFloorBehaviors(count + 1), 3000);
            } else if (r < 0.75) {
                // Tail chase on floor
                cat.classList.remove('walking', 'sitting', 'grooming');
                cat.classList.add('spinning');
                cat.style.transform = '';
                showSpeech('?!');
                stateTimeout = setTimeout(() => {
                    cat.classList.remove('spinning');
                    cat.classList.add('sitting');
                    showSpeech('*dizzy*');
                    stateTimeout = setTimeout(() => doFloorBehaviors(count + 1), 1500);
                }, 2400);
            } else if (r < 0.85) {
                // Sleep
                cat.classList.remove('walking', 'grooming', 'spinning');
                cat.classList.add('sitting', 'sleeping');
                cat.style.transform = '';
                zzz.textContent = 'z Z z';
                stateTimeout = setTimeout(() => {
                    zzz.textContent = '';
                    cat.classList.remove('sleeping');
                    doFloorBehaviors(count + 1);
                }, 3000 + Math.random() * 2000);
            } else {
                // Pounce on floor
                cat.classList.remove('walking', 'sitting', 'grooming', 'spinning');
                cat.classList.add('sitting');
                const pounceLeft = Math.random() > 0.5;
                cat.style.transform = pounceLeft ? 'scaleX(-1)' : '';
                // Butt wiggle
                let w = 0;
                const wi = setInterval(() => {
                    const base = pounceLeft ? 'scaleX(-1) ' : '';
                    cat.style.transform = base + 'translateX(' + (w % 2 === 0 ? '2' : '-2') + 'px)';
                    w++;
                    if (w >= 6) {
                        clearInterval(wi);
                        cat.style.transform = pounceLeft ? 'scaleX(-1)' : '';
                        cat.classList.add('pouncing');
                        const jumpDist = 60 + Math.random() * 100;
                        floorSetX(pounceLeft ? floorX - jumpDist : floorX + jumpDist);
                        setTimeout(() => {
                            cat.classList.remove('pouncing');
                            cat.classList.add('sitting');
                            showSpeech(Math.random() > 0.5 ? '*gotcha!*' : '*missed*');
                            stateTimeout = setTimeout(() => doFloorBehaviors(count + 1), 1500);
                        }, 400);
                    }
                }, 100);
            }
        }

        function doFloorJumpBack() {
            cat.classList.remove('walking', 'sleeping', 'grooming', 'spinning', 'pouncing');
            cat.classList.add('sitting');
            cat.style.transform = '';
            showSpeech('*jump!*');

            stateTimeout = setTimeout(() => {
                // Animate cat jumping up toward card
                const card = document.getElementById('login-card');
                const cardRect = card ? card.getBoundingClientRect() : { top: window.innerHeight / 2, left: window.innerWidth / 3, right: window.innerWidth * 2 / 3 };
                const targetX = cardRect.left + Math.random() * (cardRect.right - cardRect.left - CAT_W);
                const targetY = cardRect.top - 48;

                // Parabolic jump
                const startX = floorX;
                const startY = window.innerHeight - 48;
                const duration = 600;
                const startTime = performance.now();

                function jumpStep(now) {
                    const progress = Math.min((now - startTime) / duration, 1);
                    const easedP = progress; // linear for position
                    const px = startX + (targetX - startX) * easedP;
                    // Parabolic arc: goes up then down
                    const arc = -4 * (easedP - 0.5) * (easedP - 0.5) + 1; // peaks at 0.5
                    const midY = Math.min(startY, targetY) - 150; // apex 150px above highest point
                    const py = startY + (targetY - startY) * easedP - arc * (startY - midY);

                    cat.style.left = px + 'px';
                    cat.style.top = py + 'px';

                    // Spin during jump
                    cat.style.transform = 'rotate(' + (progress * 360) + 'deg)';

                    if (progress < 1) {
                        physicsFrame = requestAnimationFrame(jumpStep);
                    } else {
                        returnToTrack();
                    }
                }

                physicsFrame = requestAnimationFrame(jumpStep);
            }, 500);
        }

        function returnToTrack() {
            if (physicsFrame) { cancelAnimationFrame(physicsFrame); physicsFrame = null; }

            cat.classList.remove('free-fall', 'dragging');
            cat.style.position = 'absolute';
            cat.style.top = '';
            cat.style.bottom = '0';
            cat.style.transition = 'none';
            cat.style.transform = '';

            // Return to whichever track cat was on
            const parentTrack = isOnBottom ? bottomTrack : track;
            parentTrack.appendChild(cat);
            parentTrack.appendChild(fly);

            trackW = track.offsetWidth;
            setX(Math.random() * (trackW - CAT_W));
            setFacing(false);
            showSpeech('Made it!');
            stateTimeout = setTimeout(() => nextBehavior(), 1500);
        }

        // Mouse events
        cat.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            startDrag(e.clientX, e.clientY);
        });
        document.addEventListener('mousemove', function(e) {
            moveDrag(e.clientX, e.clientY);
        });
        document.addEventListener('mouseup', function(e) {
            if (!isDragging) return;
            if (!dragMoved) {
                // It was a click, not a drag — show speech
                isDragging = false;
                cat.classList.remove('dragging');
                // Return cat to track position
                cat.style.position = 'absolute';
                cat.style.top = '';
                cat.style.bottom = '0';
                const parentTrack = isOnBottom ? bottomTrack : track;
                parentTrack.appendChild(cat);
                parentTrack.appendChild(fly);
                showSpeech(meows[Math.floor(Math.random() * meows.length)]);
                return;
            }
            endDrag();
        });

        // Touch events
        cat.addEventListener('touchstart', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const t = e.touches[0];
            startDrag(t.clientX, t.clientY);
        }, { passive: false });
        document.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            e.preventDefault();
            const t = e.touches[0];
            moveDrag(t.clientX, t.clientY);
        }, { passive: false });
        document.addEventListener('touchend', function(e) {
            if (!isDragging) return;
            if (!dragMoved) {
                isDragging = false;
                cat.classList.remove('dragging');
                cat.style.position = 'absolute';
                cat.style.top = '';
                cat.style.bottom = '0';
                const parentTrack = isOnBottom ? bottomTrack : track;
                parentTrack.appendChild(cat);
                parentTrack.appendChild(fly);
                showSpeech(meows[Math.floor(Math.random() * meows.length)]);
                return;
            }
            endDrag();
        });

        // Start
        setX(x);
        nextBehavior();
    });
