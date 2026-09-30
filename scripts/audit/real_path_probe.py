"""V1 audit real-path probe: browser-style auth, RBAC/scope, AI-node path,
ingestion idempotency (incl. 12 concurrent deliveries), Reverb private-channel
delivery and cross-site isolation, logout. Runs inside the compose network
against the TLS gateway. TEST DATA only; never prints passwords or tokens.

Usage (from the repository root, pilot overlay running):
  docker run --rm --network <project>_smartcampus -v "$PWD/scripts/audit/real_path_probe.py:/probe.py:ro"     -e REVERB_KEY=... -e PILOT_SITE=<site uuid> -e PILOT_ZONE=<zone uuid> -e OTHER_SITE=<second site uuid>     -e ADMIN_PW=... -e OFFICER_PW=... -e OTHER_PW=... smart-campus/ai-service:dev python /probe.py
Prerequisites: accounts rehearsal.admin (SUPER_ADMIN, must_change_password),
officer.pilot (SECURITY_OFFICER, SITE PILOT_SITE), officer.other
(SECURITY_OFFICER, SITE OTHER_SITE), created with access:bootstrap-admin,
access:assign-role and locations:provision. Creates test AI nodes/cameras:
never run against a pilot host holding real data.
"""

import asyncio
import json
import os
import sys
import time
import uuid
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import unquote

import httpx
import websockets

BASE = "https://nginx"
HOST = {"Host": "localhost:8443"}
KEY = os.environ["REVERB_KEY"]
PILOT_SITE, PILOT_ZONE, OTHER_SITE = os.environ["PILOT_SITE"], os.environ["PILOT_ZONE"], os.environ["OTHER_SITE"]
results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f" ({detail})" if detail else ""))


def client():
    return httpx.Client(base_url=BASE, verify=False, headers={**HOST, "Accept": "application/json"}, timeout=20)


def xsrf(c):
    return {"X-XSRF-TOKEN": unquote(c.cookies.get("XSRF-TOKEN", ""))}


def login(c, username, password):
    c.get("/api/v1/auth/csrf-cookie")
    before = c.cookies.get("laravel-session")
    r = c.post("/api/v1/auth/login", json={"username": username, "password": password}, headers=xsrf(c))
    return r, before, c.cookies.get("laravel-session")


def main():
    admin_pw = os.environ["ADMIN_PW"]
    new_admin_pw = "Rehearsal-" + uuid.uuid4().hex
    officer_pw, other_pw = os.environ["OFFICER_PW"], os.environ["OTHER_PW"]

    # --- 1. Browser-style auth: CSRF -> login -> forced password change -> session rotation
    a = client()
    r = a.post("/api/v1/auth/login", json={"username": "rehearsal.admin", "password": admin_pw})
    check("login without CSRF token rejected", r.status_code == 419, str(r.status_code))
    r, before, after = login(a, "rehearsal.admin", admin_pw)
    check("admin login via CSRF cookie", r.status_code == 200, str(r.status_code))
    check("session id rotated on login", before is not None and after is not None and before != after)
    me = a.get("/api/v1/auth/me").json().get("data", {})
    must_change = me.get("must_change_password") or me.get("user", {}).get("must_change_password")
    check("bootstrap admin must change password", bool(must_change))
    blocked = a.get("/api/v1/users")
    check("API blocked until password changed", blocked.status_code in (403, 423), str(blocked.status_code))
    r = a.post(
        "/api/v1/auth/change-password",
        headers=xsrf(a),
        json={"current_password": admin_pw, "password": new_admin_pw, "password_confirmation": new_admin_pw},
    )
    check("change password", r.status_code == 200, str(r.status_code))
    a = client()
    r, _, _ = login(a, "rehearsal.admin", new_admin_pw)
    check("login with new password", r.status_code == 200, str(r.status_code))
    check("admin can list users", a.get("/api/v1/users").status_code == 200)

    # --- 2. Register AI node + credential, camera with secrets
    code = "NODE-" + uuid.uuid4().hex[:6]
    r = a.post(
        "/api/v1/ai-nodes",
        headers=xsrf(a),
        json={"site_uuid": PILOT_SITE, "node_code": code, "name": "Rehearsal node", "max_camera": 2},
    )
    check("create AI node", r.status_code in (200, 201), str(r.status_code))
    node = r.json()["data"]
    check("AI node response has no numeric id", "id" not in node)
    r = a.post(f"/api/v1/ai-nodes/{node['uuid']}/credentials", headers=xsrf(a), json={})
    token = r.json().get("data", {}).get("token")
    check("issue node credential (shown once)", bool(token), str(r.status_code))
    creds = a.get(f"/api/v1/ai-nodes/{node['uuid']}/credentials").text
    check("credential list never shows the token", token not in creds)
    rtsp_secret = "S3cret-" + uuid.uuid4().hex[:8]
    r = a.post(
        "/api/v1/cameras",
        headers=xsrf(a),
        json={
            "site_uuid": PILOT_SITE,
            "zone_uuid": PILOT_ZONE,
            "ai_node_uuid": node["uuid"],
            "camera_code": "CAM-" + uuid.uuid4().hex[:6],
            "name": "Rehearsal cam",
            "rtsp_url": f"rtsp://admin:{rtsp_secret}@10.0.0.10/stream1",
            "username": "admin",
            "password": rtsp_secret,
            "status": "ACTIVE",
        },
    )
    check("create camera", r.status_code in (200, 201), str(r.status_code))
    cam = r.json()["data"]
    listing = a.get("/api/v1/cameras").text + a.get(f"/api/v1/cameras/{cam['uuid']}").text
    check("RTSP credentials never returned", rtsp_secret not in listing and "rtsp://" not in listing)

    # --- 3. Node path through nginx
    n = httpx.Client(base_url=BASE, verify=False, headers={**HOST, "Accept": "application/json"}, timeout=20)
    r = n.post(
        "/internal/v1/ai-nodes/heartbeat", json={"cpu_usage": 10}, headers={"Authorization": "Bearer invalid-token"}
    )
    check("internal API rejects bad node token", r.status_code == 401, str(r.status_code))
    r = n.post(
        "/internal/v1/ai-nodes/heartbeat",
        json={"cpu_usage": 12, "queue_depth": 0},
        headers={"Authorization": f"Bearer {token}"},
    )
    check("node heartbeat via gateway", r.status_code == 200, str(r.status_code))
    nh = {"Authorization": f"Bearer {token}"}

    # --- 4. RBAC / scope over HTTP with real sessions
    o = client()
    r, _, _ = login(o, "officer.pilot", officer_pw)
    check("site officer login", r.status_code == 200, str(r.status_code))
    check("site officer cannot manage users", o.get("/api/v1/users").status_code == 403)
    r = o.post("/api/v1/ai-nodes", headers=xsrf(o), json={"node_code": "X", "name": "X"})
    check("site officer cannot create AI nodes", r.status_code == 403, str(r.status_code))
    x = client()
    login(x, "officer.other", other_pw)

    # --- 5. Realtime: authorised subscriber receives; cross-site subscriber refused
    async def realtime():
        uri = f"wss://nginx/reverb/app/{KEY}?protocol=7&client=py&version=8.4.0"
        import ssl

        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        async with websockets.connect(uri, ssl=ctx) as ws:
            hello = json.loads(await ws.recv())
            socket_id = json.loads(hello["data"])["socket_id"]
            channel = f"private-site.{PILOT_SITE}"
            other = x.post(
                "/api/v1/broadcasting/auth", headers=xsrf(x), data={"socket_id": socket_id, "channel_name": channel}
            )
            check("cross-site officer denied channel auth", other.status_code == 403, str(other.status_code))
            auth = o.post(
                "/api/v1/broadcasting/auth", headers=xsrf(o), data={"socket_id": socket_id, "channel_name": channel}
            )
            check("site officer channel auth", auth.status_code == 200, str(auth.status_code))
            await ws.send(
                json.dumps({"event": "pusher:subscribe", "data": {"channel": channel, "auth": auth.json()["auth"]}})
            )
            sub = json.loads(await asyncio.wait_for(ws.recv(), 5))
            check(
                "subscribed to private site channel",
                sub.get("event") == "pusher_internal:subscription_succeeded",
                sub.get("event"),
            )

            event_uuid = str(uuid.uuid4())
            payload = {
                "event_uuid": event_uuid,
                "camera_uuid": cam["uuid"],
                "ai_node_uuid": node["uuid"],
                "track_id": "t-1",
                "occurred_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "classification": "UNKNOWN",
                "model_code": "rehearsal-model",
                "model_version": "0",
                "rejected_locally": False,
            }
            t0 = time.time()
            r = n.post("/internal/v1/events/recognition", json=payload, headers=nh)
            check("recognition event ingested", r.status_code in (200, 201), f"{r.status_code} {r.text[:120]}")
            got = None
            try:
                while got is None:
                    msg = json.loads(await asyncio.wait_for(ws.recv(), 10))
                    if msg.get("channel") == channel and not msg["event"].startswith("pusher"):
                        got = msg
            except asyncio.TimeoutError:
                pass
            check(
                "event broadcast received over WSS",
                got is not None,
                f"{(time.time() - t0) * 1000:.0f} ms" if got else "timeout",
            )
            if got:
                body = got["data"] if isinstance(got["data"], str) else json.dumps(got["data"])
                check("broadcast carries no numeric ids or embeddings", '"id":' not in body and "embedding" not in body)
                check("broadcast event uuid matches", event_uuid in body)
            return payload

    payload = asyncio.run(realtime())

    # --- 6. Duplicate delivery, sequential and concurrent
    r = n.post("/internal/v1/events/recognition", json=payload, headers=nh)
    check("identical retry is idempotent", r.status_code in (200, 201), str(r.status_code))
    tampered = {**payload, "classification": "REVIEW"}
    r = n.post("/internal/v1/events/recognition", json=tampered, headers=nh)
    check("same uuid with different content rejected", r.status_code == 409, str(r.status_code))
    conc = {**payload, "event_uuid": str(uuid.uuid4()), "track_id": "t-conc"}

    def send(_):
        with httpx.Client(base_url=BASE, verify=False, headers={**HOST, "Accept": "application/json"}, timeout=30) as c:
            return c.post("/internal/v1/events/recognition", json=conc, headers=nh).status_code

    with ThreadPoolExecutor(max_workers=12) as pool:
        codes = list(pool.map(send, range(12)))
    check("12 concurrent duplicate deliveries: no 5xx", all(c < 500 for c in codes), str(sorted(set(codes))))
    print("CONCURRENT_EVENT_UUID=" + conc["event_uuid"])

    # --- 7. Event visibility by scope
    ev = o.get("/api/v1/events").json()
    check("site officer sees events of own site", ev.get("meta", {}).get("total", len(ev.get("data", []))) >= 1)
    xe = x.get("/api/v1/events").json()
    xs = json.dumps(xe)
    check("other-site officer sees none of pilot site events", payload["event_uuid"] not in xs)
    r = x.get(f"/api/v1/events/{payload['event_uuid']}")
    check("other-site officer cannot open pilot event (BOLA)", r.status_code in (403, 404), str(r.status_code))

    # --- 8. Logout invalidates the session
    r = o.post("/api/v1/auth/logout", headers=xsrf(o))
    check("logout", r.status_code in (200, 204), str(r.status_code))
    check("session unusable after logout", o.get("/api/v1/auth/me").status_code == 401)

    print(f"TOTAL {sum(results)}/{len(results)} passed")
    sys.exit(0 if all(results) else 1)


main()
