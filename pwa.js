/* OpenRanch — PWA glue: service worker, install hint, push opt-in.
 * Loaded by index.php and login.php. Safe to load on a desktop browser or an
 * already-installed app; everything below is feature-detected. */
(function () {
  'use strict';

  // ---------- service worker ----------
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function (e) {
        console.warn('[ww] service worker registration failed:', e);
      });
    });
  }

  // ---------- environment ----------
  var isStandalone =
    (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
    window.navigator.standalone === true;

  var ua = navigator.userAgent || '';
  var isIOS = /iPad|iPhone|iPod/.test(ua) ||
              // iPadOS 13+ reports as Mac but has touch
              (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  // On iOS every browser is WebKit; only Safari can add to the home screen.
  var isIOSSafari = isIOS && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);

  // ---------- install hint bar ----------
  var deferredPrompt = null;
  var DISMISS_KEY = 'or_install_dismissed';

  function dismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function setDismissed() {
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
  }

  function bar() { return document.getElementById('installbar'); }

  function showBar(title, sub, actionLabel, onAction) {
    var el = bar();
    if (!el) return;
    el.innerHTML =
      '<img src="/icons/icon-192.png" alt="">' +
      '<div class="txt"><b></b><span></span></div>' +
      (actionLabel ? '<button class="go"></button>' : '') +
      '<button class="x" aria-label="Dismiss">&times;</button>';
    el.querySelector('b').textContent = title;
    el.querySelector('span').textContent = sub;

    if (actionLabel) {
      var go = el.querySelector('button.go');
      go.textContent = actionLabel;
      go.addEventListener('click', onAction);
    }
    el.querySelector('button.x').addEventListener('click', function () {
      el.classList.remove('show');
      setDismissed();
    });
    el.classList.add('show');
  }

  // Android / desktop Chromium: the browser tells us when it's installable.
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (isStandalone || dismissed()) return;
    showBar(
      'Install this app',
      'Add OpenRanch to your home screen for full-screen access.',
      'Install',
      function () {
        var el = bar();
        if (el) el.classList.remove('show');
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function () { deferredPrompt = null; });
      }
    );
  });

  window.addEventListener('appinstalled', function () {
    var el = bar();
    if (el) el.classList.remove('show');
    setDismissed();
  });

  // iOS Safari has no install event — show the Share-sheet instruction instead.
  if (isIOSSafari && !isStandalone && !dismissed()) {
    window.addEventListener('load', function () {
      setTimeout(function () {
        showBar(
          'Install this app',
          'Tap the Share button, then choose “Add to Home Screen”.',
          null, null
        );
      }, 1200);
    });
  }

  // ---------- push notifications ----------
  function b64ToUint8(base64) {
    var padded = (base64 + '='.repeat((4 - base64.length % 4) % 4))
      .replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(padded);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  var pushBtn = null;

  function setBtn(state, label, title) {
    if (!pushBtn) return;
    pushBtn.textContent = label;
    pushBtn.title = title || '';
    pushBtn.classList.toggle('on', state === 'on');
    pushBtn.disabled = (state === 'disabled');
    pushBtn.style.display = '';
  }

  async function currentSub() {
    var reg = await navigator.serviceWorker.ready;
    return { reg: reg, sub: await reg.pushManager.getSubscription() };
  }

  async function enablePush() {
    try {
      // iOS only allows push once the PWA is on the home screen.
      if (isIOS && !isStandalone) {
        alert('On iPhone and iPad, notifications only work after you install ' +
              'this app.\n\nTap the Share button in Safari, choose "Add to Home ' +
              'Screen", then open OpenRanch from your home screen and turn on ' +
              'notifications there.');
        return;
      }

      var perm = await Notification.requestPermission();
      if (perm !== 'granted') {
        setBtn('off', 'Notifications blocked',
               'Notifications are blocked for this site in your browser settings.');
        return;
      }

      var r = await fetch('/subscribe.php');
      if (!r.ok) { setBtn('off', 'Sign in for notifications'); return; }
      var info = await r.json();

      var c = await currentSub();
      var sub = c.sub;
      if (!sub) {
        sub = await c.reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: b64ToUint8(info.vapid_public),
        });
      }

      var save = await fetch('/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(sub.toJSON()),
      });
      if (!save.ok) throw new Error('could not save subscription');
      setBtn('on', 'Notifications on', 'Push notifications are on for this device.');
    } catch (e) {
      console.warn('[ww] push enable failed:', e);
      setBtn('off', 'Notifications unavailable', String(e && e.message || e));
    }
  }

  async function disablePush() {
    try {
      var c = await currentSub();
      if (c.sub) {
        await fetch('/subscribe.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ endpoint: c.sub.endpoint, unsubscribe: true }),
        });
        await c.sub.unsubscribe();
      }
      setBtn('off', 'Enable notifications');
    } catch (e) {
      console.warn('[ww] push disable failed:', e);
    }
  }

  async function initPush() {
    pushBtn = document.getElementById('pushbtn');
    if (!pushBtn) return;                       // not signed in — no button
    pushBtn.style.display = 'none';

    var supported = 'serviceWorker' in navigator &&
                    'PushManager' in window &&
                    'Notification' in window;

    // iOS < 16.4 has no web push at all; iOS 16.4+ needs the app installed.
    if (!supported) {
      if (isIOS) {
        setBtn('disabled', 'Notifications need install',
               'On iPhone, add this app to your home screen to receive notifications.');
      }
      return;                                   // stay hidden elsewhere
    }

    if (isIOS && !isStandalone) {
      setBtn('off', 'Notifications need install',
             'On iPhone, add this app to your home screen first.');
      pushBtn.addEventListener('click', enablePush);
      return;
    }

    if (Notification.permission === 'denied') {
      setBtn('off', 'Notifications blocked',
             'Notifications are blocked for this site in your browser settings.');
      return;
    }

    var c = await currentSub();
    if (c.sub) setBtn('on', 'Notifications on', 'Push notifications are on for this device.');
    else setBtn('off', 'Enable notifications');

    pushBtn.addEventListener('click', function () {
      if (pushBtn.classList.contains('on')) disablePush();
      else enablePush();
    });
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { initPush(); });
  }
})();
