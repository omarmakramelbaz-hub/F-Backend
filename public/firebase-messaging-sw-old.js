importScripts('https://www.gstatic.com/firebasejs/7.9.3/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/7.9.3/firebase-messaging.js');

const firebaseConfig = {
    apiKey: "AIzaSyB0KKM-Dm-7wOk-S3zLkBAjMn8Z4JL9snA",
    authDomain: "faskhaninja.firebaseapp.com",
    projectId: "faskhaninja",
    storageBucket: "faskhaninja.appspot.com",
    messagingSenderId: "547950781924",
    appId: "1:547950781924:web:7511c8e2cc79e2cc28d3f1",
    measurementId: "G-2BN39RQZDR"
};

// Initialize Firebase
firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();
self.addEventListener('push', function (event) {
    const payload = event.data ? event.data.json() : {};

    const options = {
        body: payload.notification.body,
        icon: payload.data.icon || '{{url("/storage/".app(App\Models\GeneralSettings::class)->logo)}}',
        vibrate: [1000, 200, 1000, 200, 1000],
        silent: false,
        data: {
            click_action: payload.data.click_action || '{{url("/")}}',
        },
    };

    event.waitUntil(
        self.registration.showNotification(payload.notification.title, options).then(() => {
            return self.clients.matchAll({ includeUncontrolled: true }).then((clients) => {
                if (clients && clients.length) {
                    clients[0].postMessage({ type: 'play-audio-loop', click_action: options.data.click_action });
                }
            });
        })
    );
});




