<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import eventsService from '@/services/events'
import camerasService from '@/services/cameras'
import { getEcho, disconnectEcho } from '@/services/echo'
import { apiErrorText, PLATE_EVENT_LABELS, VEHICLE_STATUS_LABELS } from '@/constants/labels'
import CameraLiveView from '@/components/cameras/CameraLiveView.vue'
import GatePassInfo from '@/components/common/GatePassInfo.vue'
import CameraZoneWall from '@/components/cameras/CameraZoneWall.vue'

const router = useRouter()
const authStore = useAuthStore()

const canViewMonitor = computed(() => authStore.can('monitor.view'))
const canViewCameras = computed(() => authStore.can('camera.view'))
const canViewLive = computed(() => authStore.can('camera.live'))
const canViewHealth = computed(() => authStore.can('camera.view') || authStore.can('ai_node.view'))

// --- Sites/cameras: no dedicated /sites listing endpoint exists yet, so the
// site picker is derived from whatever cameras the current user can already
// see (GET /cameras, gated by camera.view). If the user lacks camera.view,
// no site list can be built client-side and the picker stays empty.
const cameras = ref([])
const sites = ref([])
const selectedSiteUuid = ref('')
const selectedCameraUuid = ref('')
const camerasLoading = ref(false)
const errorMessage = ref('')

const camerasForSelectedSite = computed(() =>
  cameras.value.filter((c) => c.site?.uuid === selectedSiteUuid.value),
)
const selectedCamera = computed(
  () => cameras.value.find((c) => c.uuid === selectedCameraUuid.value) || null,
)

async function loadCameras() {
  if (!canViewCameras.value) return

  camerasLoading.value = true
  try {
    const res = await camerasService.listCameras({ per_page: 100 })
    cameras.value = res.data || []

    const uniqueSites = new Map()
    for (const camera of cameras.value) {
      if (camera.site) uniqueSites.set(camera.site.uuid, camera.site)
    }
    sites.value = Array.from(uniqueSites.values())

    if (!selectedSiteUuid.value && sites.value.length > 0) {
      selectedSiteUuid.value = sites.value[0].uuid
    }
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดรายชื่อกล้องไม่สำเร็จ')
  } finally {
    camerasLoading.value = false
  }
}

// --- Live event feed ---
const liveEvents = ref([])
const MAX_FEED_LENGTH = 50
let currentChannel = null
// The bare channel name (no `private-` prefix) — Echo.leave() adds that
// prefix itself internally, so leaving must use the same bare name private()
// was given, not whatever the underlying channel object reports as `.name`.
let currentChannelName = ''
// Reactive mirror of the subscription state for the template (currentChannel is a
// plain variable). realtimeError: the live feed is unavailable, but the site picker,
// KPIs and backfill keep working — a realtime failure must never block the page.
const realtimeConnected = ref(false)
const realtimeError = ref('')

// GATE-007: a Smart Gate decision arrives as a second `event.decided` for an event already in
// the feed (the face event, or the plate read of a plate-only pass). It updates that row in place
// and moves it to the top, keeping fields the earlier push carried (such as the plate).
function pushLiveEvent(payload) {
  const existing = liveEvents.value.find((e) => e.uuid === payload.event.uuid)
  const row = {
    ...(existing || {}),
    ...payload.event,
    decision: payload.decision,
    gate_pass: payload.gate_pass || existing?.gate_pass || null,
    _receivedAt: Date.now(),
  }
  liveEvents.value = [row, ...liveEvents.value.filter((e) => e.uuid !== row.uuid)].slice(
    0,
    MAX_FEED_LENGTH,
  )
}

// VEH-012: a plate read is a record, never a decision (ACR-VEH-01 D2) — it carries `plate`
// instead of `decision` and is drawn with its own badge.
function pushPlateEvent(payload) {
  liveEvents.value = [
    { ...payload.event, plate: payload.plate, decision: null, _receivedAt: Date.now() },
    ...liveEvents.value,
  ].slice(0, MAX_FEED_LENGTH)
}

function subscribeToSite(siteUuid) {
  unsubscribeFromSite()
  if (!siteUuid) return

  try {
    const channel = getEcho().private(`site.${siteUuid}`)
    channel.listen('.event.decided', pushLiveEvent)
    channel.listen('.plate.read', pushPlateEvent)
    currentChannel = channel
    currentChannelName = `site.${siteUuid}`
    realtimeConnected.value = true
    realtimeError.value = ''
  } catch (err) {
    console.error('Live event feed unavailable:', err)
    realtimeError.value =
      'การแจ้งเหตุการณ์แบบสดไม่พร้อมใช้งาน (ตรวจสอบการตั้งค่า Reverb) — กดรีเฟรชเพื่อดูเหตุการณ์ล่าสุด'
  }
}

function unsubscribeFromSite() {
  if (currentChannel) {
    currentChannel.stopListening('.event.decided')
    currentChannel.stopListening('.plate.read')
    getEcho().leave(currentChannelName)
    currentChannel = null
    currentChannelName = ''
  }
  realtimeConnected.value = false
}

// --- Historical backfill (also doubles as EVT-015 recovery on (re)connect) ---
async function loadRecentEvents(siteUuid) {
  if (!siteUuid) {
    liveEvents.value = []
    return
  }

  try {
    const res = await eventsService.listEvents({
      site_uuid: siteUuid,
      per_page: MAX_FEED_LENGTH,
    })
    liveEvents.value = (res.data || []).map((event) => ({
      ...event,
      decision: event.current_decision,
      gate_pass: event.current_decision?.gate_pass || null,
      _receivedAt: Date.now(),
    }))
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดเหตุการณ์ล่าสุดไม่สำเร็จ')
  }
}

// --- Daily KPIs: one lightweight count query per decision outcome ---
const kpi = reactive({ ALLOW: 0, REVIEW: 0, DENY: 0, UNKNOWN: 0 })
const kpiLoading = ref(false)

async function loadKpis(siteUuid) {
  if (!siteUuid) {
    kpi.ALLOW = kpi.REVIEW = kpi.DENY = kpi.UNKNOWN = 0
    return
  }

  kpiLoading.value = true
  const startOfDay = new Date()
  startOfDay.setHours(0, 0, 0, 0)

  try {
    const results = await Promise.all(
      ['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN'].map((decision) =>
        eventsService.listEvents({
          site_uuid: siteUuid,
          decision,
          from: startOfDay.toISOString(),
          per_page: 1,
        }),
      ),
    )
    ;['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN'].forEach((decision, i) => {
      kpi[decision] = results[i]?.meta?.pagination?.total ?? 0
    })
  } catch {
    // KPI counts are supplementary — a failure here shouldn't block the feed.
  } finally {
    kpiLoading.value = false
  }
}

const unknownPersonEvents = computed(() =>
  liveEvents.value.filter((e) => e.event_type === 'UNKNOWN_PERSON').slice(0, 10),
)

watch(selectedSiteUuid, (siteUuid) => {
  subscribeToSite(siteUuid)
  loadRecentEvents(siteUuid)
  loadKpis(siteUuid)
})

onMounted(async () => {
  await loadCameras()
})

onBeforeUnmount(() => {
  unsubscribeFromSite()
  disconnectEcho()
})

function decisionBadgeClass(decision) {
  return (
    {
      ALLOW: 'bg-green-100 text-green-800',
      REVIEW: 'bg-amber-100 text-amber-800',
      DENY: 'bg-red-100 text-red-800',
      UNKNOWN: 'bg-gray-100 text-gray-800',
    }[decision] || 'bg-gray-100 text-gray-600'
  )
}

// display_name is often empty; the backend also sends a name with a first/last fallback.
function plateLabel(event) {
  const plate = event.plate || {}
  const text = plate.plate_display || plate.raw_text || 'อ่านทะเบียนไม่ได้'
  const province = plate.plate_province_name || ''
  return province ? `${text} ${province}` : text
}

function plateBadgeClass(event) {
  if (event.plate?.vehicle_status === 'BLOCKED') return 'bg-red-100 text-red-800'
  if (event.event_type === 'PLATE_RECOGNIZED') return 'bg-blue-100 text-blue-800'
  return 'bg-gray-100 text-gray-700'
}

function plateBadgeText(event) {
  if (event.plate?.vehicle_status === 'BLOCKED') return `รถ${VEHICLE_STATUS_LABELS.BLOCKED}`
  return PLATE_EVENT_LABELS[event.event_type] || 'ป้ายทะเบียน'
}

function personLabel(event) {
  if (event.plate) return plateLabel(event)
  return (
    event.person?.name ||
    event.person_name ||
    event.person?.display_name ||
    event.person_display_name ||
    'ไม่ทราบตัวตน'
  )
}

const PERSON_TYPE_LABELS = {
  STUDENT: 'ผู้เรียน',
  TEACHER: 'ครู',
  STAFF: 'บุคลากร',
  PARENT: 'ผู้ปกครอง',
  VISITOR: 'ผู้มาติดต่อ',
  CONTRACTOR: 'ผู้รับเหมา',
}

function personMeta(event) {
  const type = event.person?.person_type || event.person_type
  const parts = []
  if (type) parts.push(PERSON_TYPE_LABELS[type] || type)
  if (event.person_code) parts.push(event.person_code)
  if (typeof event.face_score === 'number') parts.push(`ความเหมือน ${event.face_score.toFixed(2)}`)
  return parts.join(' · ')
}
</script>

<template>
  <div class="live-monitor-view max-w-7xl mx-auto p-6 space-y-6">
    <div
      class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-4"
    >
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ศูนย์เฝ้าระวังแบบเรียลไทม์</h1>
        <p class="text-sm text-gray-500">
          Live Monitor — เหตุการณ์รับรู้ใบหน้าและการตัดสินใจแบบเรียลไทม์ (EVT-016)
        </p>
      </div>
      <div v-if="canViewHealth">
        <button
          @click="router.push({ name: 'health.overview' })"
          class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium"
        >
          สถานะกล้อง / AI Node
        </button>
      </div>
    </div>

    <div
      v-if="!canViewMonitor"
      class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm"
    >
      คุณไม่มีสิทธิ์เข้าถึงศูนย์เฝ้าระวังนี้ (ต้องมีสิทธิ์ monitor.view)
    </div>

    <template v-else>
      <div
        v-if="errorMessage"
        class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
      >
        {{ errorMessage }}
      </div>

      <div
        class="bg-surface p-4 rounded-xl border border-gray-200 flex flex-wrap gap-4 items-center"
      >
        <label class="text-sm text-gray-500">สถานที่:</label>
        <select
          v-model="selectedSiteUuid"
          class="px-3 py-2 border rounded-lg text-sm min-w-[200px]"
        >
          <option value="" disabled>เลือกสถานที่</option>
          <option v-for="site in sites" :key="site.uuid" :value="site.uuid">{{ site.name }}</option>
        </select>
        <span v-if="camerasLoading" class="text-xs text-gray-400">กำลังโหลด...</span>
        <span v-else-if="!canViewCameras" class="text-xs text-gray-400">
          (ไม่มีสิทธิ์ camera.view — ไม่สามารถเลือกสถานที่ได้)
        </span>
        <span v-else-if="sites.length === 0" class="text-xs text-gray-400">ไม่พบสถานที่/กล้อง</span>

        <div v-if="realtimeError" data-test="realtime-error" class="ml-auto text-xs text-amber-700">
          {{ realtimeError }}
        </div>
        <div
          v-else-if="realtimeConnected"
          class="ml-auto flex items-center gap-1.5 text-xs text-green-600"
        >
          <span class="h-2 w-2 rounded-full bg-green-500 animate-pulse"></span>
          เชื่อมต่อสดแล้ว
        </div>
      </div>

      <!-- Daily KPIs -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div
          v-for="decision in ['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN']"
          :key="decision"
          class="bg-surface p-4 rounded-xl border border-gray-200"
        >
          <div
            class="text-xs font-medium uppercase"
            :class="decisionBadgeClass(decision).split(' ')[1]"
          >
            {{ decision }}
          </div>
          <div class="text-2xl font-bold text-gray-900 mt-1">
            {{ kpiLoading ? '…' : kpi[decision] }}
          </div>
          <div class="text-xs text-gray-400">วันนี้</div>
        </div>
      </div>

      <!-- EVT-027: every camera of the site, grouped by zone -->
      <CameraZoneWall
        v-if="canViewCameras && selectedSiteUuid"
        :cameras="camerasForSelectedSite"
        :can-view-live="canViewLive"
      />

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Selected camera preview -->
        <div class="bg-surface rounded-xl border border-gray-200 p-4 space-y-3">
          <h2 class="font-semibold text-gray-900">กล้องที่เลือก</h2>
          <select v-model="selectedCameraUuid" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="">-- เลือกกล้อง --</option>
            <option
              v-for="camera in camerasForSelectedSite"
              :key="camera.uuid"
              :value="camera.uuid"
            >
              {{ camera.name }}
            </option>
          </select>
          <CameraLiveView
            v-if="selectedCamera && canViewLive"
            :camera-uuid="selectedCamera.uuid"
            :camera-name="selectedCamera.name"
          />
          <div
            v-else
            class="aspect-video bg-zinc-950 ring-1 ring-black/5 rounded-lg flex items-center justify-center text-center p-4"
          >
            <p v-if="selectedCamera" class="text-zinc-400 text-xs">
              {{ selectedCamera.name }}<br />
              ไม่มีสิทธิ์ดูภาพสดจากกล้อง (camera.live)
            </p>
            <p v-else class="text-zinc-500 text-xs">เลือกกล้องเพื่อดูภาพสด</p>
          </div>

          <!-- Unknown-person events -->
          <div>
            <h3 class="text-sm font-semibold text-gray-700 mb-2">บุคคลไม่ทราบตัวตนล่าสุด</h3>
            <ul v-if="unknownPersonEvents.length > 0" class="space-y-1 text-xs">
              <li v-for="event in unknownPersonEvents" :key="event.uuid" class="text-gray-600">
                {{ new Date(event.occurred_at).toLocaleTimeString() }} —
                {{ event.camera_name || event.camera?.name || '-' }}
              </li>
            </ul>
            <p v-else class="text-xs text-gray-400">ไม่มีเหตุการณ์บุคคลไม่ทราบตัวตน</p>
          </div>
        </div>

        <!-- Realtime event feed -->
        <div class="lg:col-span-2 bg-surface rounded-xl border border-gray-200 p-4">
          <h2 class="font-semibold text-gray-900 mb-3">เหตุการณ์แบบเรียลไทม์</h2>
          <div v-if="!selectedSiteUuid" class="text-center py-12 text-gray-400 text-sm">
            กรุณาเลือกสถานที่เพื่อดูเหตุการณ์
          </div>
          <div v-else-if="liveEvents.length === 0" class="text-center py-12 text-gray-400 text-sm">
            ยังไม่มีเหตุการณ์
          </div>
          <ul v-else class="divide-y divide-gray-200 max-h-[600px] overflow-y-auto">
            <li
              v-for="event in liveEvents"
              :key="event.uuid"
              class="py-3 flex items-start justify-between gap-3 cursor-pointer hover:bg-gray-50 -mx-4 px-4"
              @click="router.push({ name: 'events.show', params: { uuid: event.uuid } })"
            >
              <div>
                <div class="font-medium text-gray-900 text-sm">
                  {{ personLabel(event) }}
                </div>
                <div v-if="personMeta(event)" data-test="person-meta" class="text-xs text-blue-700">
                  {{ personMeta(event) }}
                </div>
                <div class="text-xs text-gray-500">
                  {{ event.event_type }} · {{ event.camera_name || event.camera?.name || '-' }} ·
                  {{ event.zone_name || event.zone?.name || '-' }}
                </div>
                <GatePassInfo
                  v-if="event.gate_pass"
                  :gate-pass="event.gate_pass"
                  compact
                  class="mt-0.5"
                />
                <div v-if="event.decision?.reason_code" class="text-xs text-gray-400">
                  เหตุผล: {{ event.decision.reason_code }}
                </div>
              </div>
              <div class="text-right shrink-0">
                <span
                  v-if="event.plate && !event.decision"
                  data-test="plate-badge"
                  class="px-2.5 py-1 rounded-full text-xs font-semibold"
                  :class="plateBadgeClass(event)"
                >
                  {{ plateBadgeText(event) }}
                </span>
                <span
                  v-else
                  class="px-2.5 py-1 rounded-full text-xs font-semibold"
                  :class="decisionBadgeClass(event.decision?.decision)"
                >
                  {{ event.decision?.decision || '-' }}
                </span>
                <div class="text-xs text-gray-400 mt-1">
                  {{ new Date(event.occurred_at).toLocaleTimeString() }}
                </div>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </div>
</template>
