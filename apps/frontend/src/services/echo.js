import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import api from '@/services/api'

window.Pusher = Pusher

let echoInstance = null

/**
 * Lazily-created singleton Laravel Echo client (EVT-016), talking to Reverb
 * over the Pusher-compatible protocol.
 *
 * Connects to the SAME origin the page was served from, at /reverb — not a
 * separate host:port. `websocket:8080` (the Reverb container) is an
 * internal Docker hostname, unreachable from a real browser; the nginx
 * gateway (infrastructure/nginx/default.conf) proxies /reverb/ to it,
 * stripping the prefix, and this wsPath matches that. VITE_REVERB_HOST/PORT
 * are only used to pick TLS vs. plain (they're the values the backend's own
 * Reverb server binds to, not a browser-reachable address).
 *
 * Uses a custom authorizer built on the shared `api` axios instance rather
 * than pusher-js's own XHR auth (which wouldn't carry the session cookie /
 * CSRF header the rest of this app relies on) — private-channel auth goes
 * through the same cookie-session request pipeline as every other API call.
 */
export function getEcho() {
  if (echoInstance) return echoInstance

  const useTLS = window.location.protocol === 'https:'

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: window.location.port || (useTLS ? 443 : 80),
    wssPort: window.location.port || 443,
    wsPath: '/reverb',
    forceTLS: useTLS,
    enabledTransports: useTLS ? ['wss'] : ['ws'],
    authorizer: (channel) => ({
      authorize(socketId, callback) {
        api
          .post('/broadcasting/auth', {
            socket_id: socketId,
            channel_name: channel.name,
          })
          .then((response) => callback(false, response.data))
          .catch((error) => callback(true, error))
      },
    }),
  })

  return echoInstance
}

/** Tear down the singleton (route leave / logout) so no stale socket lingers. */
export function disconnectEcho() {
  if (echoInstance) {
    echoInstance.disconnect()
    echoInstance = null
  }
}
