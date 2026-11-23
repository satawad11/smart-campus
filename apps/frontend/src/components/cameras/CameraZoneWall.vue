<script setup>
/**
 * EVT-027: camera wall on the live monitor — every camera of the selected
 * site, grouped by zone, each tile a CameraLiveView (snapshot refresh through
 * POST /cameras/{uuid}/snapshot, camera.live, audited per frame).
 *
 * There is no video-streaming server in the locked stack, so a wall tile
 * refreshes more slowly than the single-camera view and inherits its
 * safeguards (no overlapping requests, paused while the tab is hidden,
 * auto-stop after a while; "ดูภาพสดทั้งหมด" restarts every tile).
 */
import { computed, ref, watch, nextTick } from 'vue'
import CameraLiveView from '@/components/cameras/CameraLiveView.vue'
import { statusLabel } from '@/constants/labels'

const props = defineProps({
  cameras: { type: Array, default: () => [] },
  canViewLive: { type: Boolean, default: false },
  intervalMs: { type: Number, default: 5000 },
})

const ALL = '__all__'
const NO_ZONE = '__none__'
const selectedZone = ref(ALL)
const tiles = ref([])

const zones = computed(() => {
  const groups = new Map()
  for (const camera of props.cameras) {
    const key = camera.zone?.uuid || NO_ZONE
    if (!groups.has(key)) {
      groups.set(key, { key, name: camera.zone?.name || 'ไม่ระบุโซน', cameras: [] })
    }
    groups.get(key).cameras.push(camera)
  }
  return [...groups.values()]
    .map((g) => ({ ...g, cameras: [...g.cameras].sort((a, b) => a.name.localeCompare(b.name)) }))
    .sort((a, b) => a.name.localeCompare(b.name))
})

const visibleZones = computed(() =>
  selectedZone.value === ALL
    ? zones.value
    : zones.value.filter((z) => z.key === selectedZone.value),
)

watch(zones, (list) => {
  if (selectedZone.value !== ALL && !list.some((z) => z.key === selectedZone.value)) {
    selectedZone.value = ALL
  }
})

async function startAll() {
  await nextTick()
  for (const tile of tiles.value) tile?.start()
}

function stopAll() {
  for (const tile of tiles.value) tile?.stop()
}

function statusClass(status) {
  return status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
}
</script>

<template>
  <div class="bg-surface rounded-xl border border-gray-200 p-4 space-y-4" data-test="zone-wall">
    <div class="flex flex-wrap items-center gap-2">
      <h2 class="font-semibold text-gray-900 mr-2">กล้องทั้งหมดแยกตามโซน</h2>
      <button
        type="button"
        data-test="zone-tab-all"
        class="px-3 py-1 rounded-full text-xs"
        :class="selectedZone === ALL ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700'"
        @click="selectedZone = ALL"
      >
        ทุกโซน ({{ cameras.length }})
      </button>
      <button
        v-for="zone in zones"
        :key="zone.key"
        type="button"
        :data-test="`zone-tab-${zone.key}`"
        class="px-3 py-1 rounded-full text-xs"
        :class="selectedZone === zone.key ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700'"
        @click="selectedZone = zone.key"
      >
        {{ zone.name }} ({{ zone.cameras.length }})
      </button>
      <div v-if="canViewLive && cameras.length" class="ml-auto flex gap-2">
        <button
          type="button"
          data-test="wall-start-all"
          class="px-3 py-1 rounded-lg bg-blue-600 text-white text-xs hover:bg-blue-700"
          @click="startAll"
        >
          ดูภาพสดทั้งหมด
        </button>
        <button
          type="button"
          data-test="wall-stop-all"
          class="px-3 py-1 rounded-lg bg-gray-100 text-gray-700 text-xs hover:bg-gray-200"
          @click="stopAll"
        >
          หยุดทั้งหมด
        </button>
      </div>
    </div>

    <p v-if="!cameras.length" class="text-sm text-gray-400">ไม่มีกล้องในสถานที่นี้</p>
    <p v-else-if="!canViewLive" class="text-xs text-amber-700" data-test="wall-no-live">
      ไม่มีสิทธิ์ดูภาพสดจากกล้อง (camera.live) — แสดงเฉพาะรายชื่อกล้อง
    </p>

    <section v-for="zone in visibleZones" :key="zone.key" :data-test="`zone-section-${zone.key}`">
      <h3 class="text-sm font-semibold text-gray-700 mb-2">
        {{ zone.name }}
        <span class="font-normal text-gray-400">· {{ zone.cameras.length }} กล้อง</span>
      </h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
        <div
          v-for="camera in zone.cameras"
          :key="camera.uuid"
          class="rounded-lg border border-gray-200 p-2 space-y-1"
          data-test="wall-tile"
        >
          <div class="flex items-center justify-between gap-2 text-xs">
            <span class="font-medium text-gray-900 truncate">{{ camera.name }}</span>
            <span class="px-2 py-0.5 rounded-full" :class="statusClass(camera.status)">
              {{ statusLabel(camera.status) }}
            </span>
          </div>
          <CameraLiveView
            v-if="canViewLive"
            ref="tiles"
            :camera-uuid="camera.uuid"
            :camera-name="camera.name"
            :interval-ms="intervalMs"
            :auto-start="camera.status === 'ACTIVE'"
          />
        </div>
      </div>
    </section>
  </div>
</template>
