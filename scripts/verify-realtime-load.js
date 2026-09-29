#!/usr/bin/env node
/**
 * EVT-021 — realtime delivery verification under load.
 *
 * Logs in as a real user (session cookie + CSRF, the same flow the SPA
 * uses — see apps/frontend/src/services/echo.js), subscribes to a site's
 * private Reverb channel over a genuine WebSocket through the nginx
 * gateway, and counts `event.decided` pushes received during a window.
 * Intended to run alongside `php artisan events:load-test` (fired from
 * the backend container) so the two together prove ingestion AND
 * realtime delivery survive the same load, not just one in isolation.
 *
 * Usage:
 *   node scripts/verify-realtime-load.js <baseUrl> <reverbAppKey> <username> <password> <siteUuid> [durationSeconds]
 *
 * Example (run from a container with network access to the gateway, e.g.
 * the frontend container, which already has Node 22 + a networked path
 * to `nginx`):
 *   node scripts/verify-realtime-load.js http://nginx 0xbyeamqa5fyrjwgzvsb monitor.demo 'DemoPass123!' <site-uuid> 30
 */

const [, , baseUrl, reverbAppKey, username, password, siteUuid, durationArg] = process.argv;

if (!baseUrl || !reverbAppKey || !username || !password || !siteUuid) {
  console.error('Usage: node verify-realtime-load.js <baseUrl> <reverbAppKey> <username> <password> <siteUuid> [durationSeconds]')
  process.exit(1)
}

const durationSeconds = Number(durationArg || 30)

function parseSetCookie(headers) {
  const raw = typeof headers.getSetCookie === 'function' ? headers.getSetCookie() : []
  const jar = {}
  for (const line of raw) {
    const pair = line.split(';')[0]
    const idx = pair.indexOf('=')
    jar[pair.slice(0, idx)] = pair.slice(idx + 1)
  }
  return jar
}

function cookieHeader(jar) {
  return Object.entries(jar)
    .map(([k, v]) => `${k}=${v}`)
    .join('; ')
}

async function main() {
  let jar = {}

  const csrfRes = await fetch(`${baseUrl}/api/v1/auth/csrf-cookie`, {
    headers: { Accept: 'application/json' },
  })
  jar = { ...jar, ...parseSetCookie(csrfRes.headers) }
  const xsrfToken = decodeURIComponent(jar['XSRF-TOKEN'])

  const loginRes = await fetch(`${baseUrl}/api/v1/auth/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': xsrfToken,
      Cookie: cookieHeader(jar),
    },
    body: JSON.stringify({ username, password }),
  })
  if (!loginRes.ok) {
    console.error('Login failed:', loginRes.status, await loginRes.text())
    process.exit(1)
  }
  jar = { ...jar, ...parseSetCookie(loginRes.headers) }

  const wsUrl = `${baseUrl.replace(/^http/, 'ws')}/reverb/app/${reverbAppKey}?protocol=7&client=js&version=8.6.0`
  const ws = new WebSocket(wsUrl)

  let received = 0
  let subscribed = false

  ws.addEventListener('message', async (ev) => {
    const msg = JSON.parse(ev.data)

    if (msg.event === 'pusher:connection_established') {
      const socketId = JSON.parse(msg.data).socket_id
      const channelName = `private-site.${siteUuid}`

      const authRes = await fetch(`${baseUrl}/api/v1/broadcasting/auth`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrfToken,
          Cookie: cookieHeader(jar),
        },
        body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
      })
      if (!authRes.ok) {
        console.error('Channel auth failed:', authRes.status, await authRes.text())
        process.exit(1)
      }
      const authData = await authRes.json()

      ws.send(JSON.stringify({
        event: 'pusher:subscribe',
        data: { auth: authData.auth, channel: channelName },
      }))
    } else if (msg.event === 'pusher_internal:subscription_succeeded') {
      subscribed = true
      console.log(`Subscribed to site.${siteUuid} — listening for ${durationSeconds}s...`)
    } else if (msg.event === 'event.decided') {
      received++
    }
  })

  ws.addEventListener('error', (e) => console.error('WebSocket error:', e.message || e))

  await new Promise((resolve) => setTimeout(resolve, durationSeconds * 1000))

  console.log(`subscribed=${subscribed}`)
  console.log(`realtime_messages_received=${received}`)
  ws.close()
  process.exit(0)
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
