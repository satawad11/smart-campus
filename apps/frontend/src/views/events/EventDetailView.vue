<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import eventsService from '@/services/events'
import { apiErrorText } from '@/constants/labels'
import EventSnapshotPanel from '@/components/events/EventSnapshotPanel.vue'
import { personTypeLabel, statusLabel, recognitionReasonLabel } from '@/constants/labels'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const canView = computed(() => authStore.can('event.view'))
const canReview = computed(() => authStore.can('event.review'))
const canEnroll = computed(() => authStore.can('face.enroll'))

// EVT-025: an alert's "ดูภาพ" opens this page with ?image=1.
const autoLoadImage = route.query?.image === '1'

// Enrollment from an event only opens the normal enrollment page (person
// picker, consent, quality and audit). It never creates a person or profile.
const enrollable = computed(
  () =>
    !!event.value &&
    !event.value.person &&
    ['UNKNOWN', 'REVIEW', 'DENY'].includes(event.value.current_decision?.decision),
)

function startEnrollment() {
  router.push({ name: 'faces.enroll', query: { event: event.value.uuid } })
}

const event = ref(null)
const loading = ref(false)
const errorMessage = ref('')

const reviewForm = reactive({
  decision: 'ALLOW',
  reason_code: '',
  reason_text: '',
})
const reviewSubmitting = ref(false)
const reviewError = ref('')
const reviewSuccess = ref('')

async function fetchEvent() {
  if (!canView.value) return

  loading.value = true
  errorMessage.value = ''
  try {
    const res = await eventsService.getEvent(route.params.uuid)
    event.value = res.data
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดรายละเอียดเหตุการณ์ไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function submitReview() {
  reviewError.value = ''
  reviewSuccess.value = ''

  if (!reviewForm.reason_code.trim()) {
    reviewError.value = 'กรุณาระบุรหัสเหตุผล (reason_code)'
    return
  }

  reviewSubmitting.value = true
  try {
    await eventsService.reviewEvent(event.value.uuid, {
      decision: reviewForm.decision,
      reason_code: reviewForm.reason_code.trim(),
      reason_text: reviewForm.reason_text.trim() || undefined,
      // Optimistic concurrency (EVT-011): the reviewer's client sends back
      // the decision uuid it last saw. A stale value (someone else reviewed
      // first) is rejected with 409 instead of silently appending on top of
      // a decision this client never saw.
      expected_decision_uuid: event.value.current_decision?.uuid ?? null,
    })
    reviewSuccess.value = 'บันทึกผลการตรวจสอบเรียบร้อยแล้ว'
    reviewForm.reason_code = ''
    reviewForm.reason_text = ''
    await fetchEvent()
  } catch (err) {
    if (err.code === 'EVENT_REVIEW_CONFLICT') {
      reviewError.value =
        'เหตุการณ์นี้ถูกตรวจสอบโดยผู้อื่นไปแล้วนับตั้งแต่คุณเปิดหน้านี้ กำลังโหลดข้อมูลล่าสุด...'
      await fetchEvent()
    } else {
      reviewError.value = apiErrorText(err, 'บันทึกผลการตรวจสอบไม่สำเร็จ')
    }
  } finally {
    reviewSubmitting.value = false
  }
}

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

function sourceLabel(source) {
  return { SYSTEM: 'ระบบอัตโนมัติ', POLICY: 'นโยบาย', OFFICER: 'เจ้าหน้าที่' }[source] || source
}

onMounted(() => {
  fetchEvent()
})
</script>

<template>
  <div class="event-detail-view max-w-5xl mx-auto p-6 space-y-6">
    <button
      @click="router.push({ name: 'events.index' })"
      class="text-sm text-blue-600 hover:underline"
    >
      &larr; กลับไปยังประวัติเหตุการณ์
    </button>

    <div
      v-if="!canView"
      class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm"
    >
      คุณไม่มีสิทธิ์เข้าถึงรายละเอียดเหตุการณ์นี้ (ต้องมีสิทธิ์ event.view)
    </div>

    <template v-else>
      <div
        v-if="errorMessage"
        class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
      >
        {{ errorMessage }}
      </div>

      <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลด...</div>

      <template v-else-if="event">
        <div class="border-b pb-4 flex items-start justify-between">
          <div>
            <h1 class="text-2xl font-bold text-gray-900">
              {{ event.person?.name || event.person?.display_name || 'ไม่ทราบตัวตน' }}
            </h1>
            <p class="text-sm text-gray-500">
              {{ event.event_type }} · {{ new Date(event.occurred_at).toLocaleString() }}
            </p>
            <p v-if="event.person" class="mt-1 text-sm text-gray-600">
              ประเภทบุคคล: {{ personTypeLabel(event.person.person_type) }} · สถานะ: {{ statusLabel(event.person.status || event.person.person_status) }}
            </p>
            <p v-else-if="event.review_candidate" class="mt-1 text-sm text-amber-700">
              ผู้สมัครที่ระบบเสนอให้ตรวจสอบ: {{ event.review_candidate.name }}
              <span v-if="event.review_candidate.student_code">
                · รหัสนักเรียน {{ event.review_candidate.student_code }}
              </span>
            </p>
          </div>
          <span
            class="px-3 py-1.5 rounded-full text-sm font-semibold"
            :class="decisionBadgeClass(event.current_decision?.decision)"
          >
            {{ event.current_decision?.decision || '-' }}
          </span>
        </div>

        <!-- Event fields -->
        <div class="bg-surface rounded-xl border border-gray-200 p-6">
          <h2 class="font-semibold text-gray-900 mb-3">ข้อมูลเหตุการณ์</h2>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">สถานที่:</span> {{ event.site?.name || '-' }}</div>
            <div><span class="text-gray-500">โซน:</span> {{ event.zone?.name || '-' }}</div>
            <div><span class="text-gray-500">กล้อง:</span> {{ event.camera?.name || '-' }}</div>
            <div><span class="text-gray-500">AI Node:</span> {{ event.ai_node_uuid || '-' }}</div>
            <div><span class="text-gray-500">คะแนนใบหน้า:</span> {{ event.face_score ?? '-' }}</div>
            <div>
              <span class="text-gray-500">คะแนน Liveness:</span> {{ event.liveness_score ?? '-' }}
            </div>
          </div>
        </div>

        <!-- Face image (EVT-025) -->
        <div class="bg-surface rounded-xl border border-gray-200 p-6 space-y-3">
          <h2 class="font-semibold text-gray-900">ภาพใบหน้าจากเหตุการณ์</h2>
          <EventSnapshotPanel
            :event-uuid="event.uuid"
            :available="!!event.snapshot_available"
            :auto-load="autoLoadImage"
          />
          <div v-if="enrollable && canEnroll" class="border-t pt-3">
            <button
              type="button"
              data-test="event-start-enrollment"
              class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700"
              @click="startEnrollment"
            >
              ลงทะเบียนใบหน้า
            </button>
            <p class="mt-1 text-xs text-gray-500">
              เลือกบุคคลที่มีอยู่ในระบบ ขอความยินยอม แล้วถ่ายภาพตามขั้นตอนลงทะเบียนปกติ —
              ระบบจะไม่สร้างบุคคลหรือข้อมูลใบหน้าจากภาพนี้โดยอัตโนมัติ
            </p>
          </div>
        </div>

        <!-- EVT-026: why recognition produced this result -->
        <div
          v-if="event.recognition && (event.recognition.reasons?.length || event.recognition.rejected_locally !== null)"
          class="bg-surface rounded-xl border border-gray-200 p-6"
          data-testid="recognition-reasons"
        >
          <h2 class="font-semibold text-gray-900 mb-3">ผลการรู้จำใบหน้า</h2>
          <p
            v-if="event.recognition.rejected_locally"
            class="mb-3 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800"
            data-testid="rejected-locally-note"
          >
            ภาพไม่ผ่านเกณฑ์คุณภาพที่หน่วยประมวลผล จึง<strong>ไม่ได้นำไปเปรียบเทียบ</strong>กับใบหน้าที่ลงทะเบียนไว้
            (ไม่ใช่การยืนยันว่าเป็นบุคคลภายนอก) — ตรวจมุมกล้อง แสง และความคมชัด
          </p>
          <ul v-if="event.recognition.reasons?.length" class="space-y-1 text-sm">
            <li v-for="(reason, i) in event.recognition.reasons" :key="i" class="flex items-baseline gap-2">
              <span class="text-gray-900">{{ recognitionReasonLabel(reason) }}</span>
              <code class="text-xs text-gray-500">{{ reason }}</code>
            </li>
          </ul>
        </div>

        <!-- Decision timeline (Event Drawer) -->
        <div class="bg-surface rounded-xl border border-gray-200 p-6">
          <h2 class="font-semibold text-gray-900 mb-3">
            ประวัติการตัดสินใจ ({{ event.decisions?.length || 0 }})
          </h2>
          <p class="text-xs text-gray-400 mb-3">
            การตรวจสอบของเจ้าหน้าที่จะเพิ่มรายการใหม่เสมอ — ไม่มีการแก้ไขรายการเดิม
          </p>
          <ul class="space-y-3">
            <li
              v-for="decision in event.decisions"
              :key="decision.uuid"
              class="border border-gray-200 rounded-lg p-3 text-sm"
            >
              <div class="flex items-center justify-between">
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-semibold"
                  :class="decisionBadgeClass(decision.decision)"
                >
                  {{ decision.decision }}
                </span>
                <span class="text-xs text-gray-400">{{
                  new Date(decision.created_at).toLocaleString()
                }}</span>
              </div>
              <div class="mt-1 text-gray-600">
                เหตุผล: {{ decision.reason_code || '-' }}
                <span v-if="decision.reason_text"> — {{ decision.reason_text }}</span>
              </div>
              <div class="text-xs text-gray-400">
                แหล่งที่มา: {{ sourceLabel(decision.decision_source) }}
                <span v-if="decision.decided_by"> · โดย {{ decision.decided_by.username }}</span>
              </div>
            </li>
          </ul>
        </div>

        <!-- Manual review form -->
        <div v-if="canReview" class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="font-semibold text-gray-900">ตรวจสอบโดยเจ้าหน้าที่</h2>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs text-gray-500 mb-1">ผลการตัดสินใจ</label>
              <select
                v-model="reviewForm.decision"
                class="w-full px-3 py-2 border rounded-lg text-sm"
              >
                <option value="ALLOW">ALLOW</option>
                <option value="REVIEW">REVIEW</option>
                <option value="DENY">DENY</option>
              </select>
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs text-gray-500 mb-1">รหัสเหตุผล (จำเป็น)</label>
              <input
                v-model="reviewForm.reason_code"
                type="text"
                placeholder="เช่น OFFICER_OVERRIDE, IDENTITY_MISMATCH"
                class="w-full px-3 py-2 border rounded-lg text-sm"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs text-gray-500 mb-1">หมายเหตุ (ไม่บังคับ)</label>
            <textarea
              v-model="reviewForm.reason_text"
              rows="2"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            ></textarea>
          </div>

          <p v-if="reviewError" class="text-sm text-red-600">{{ reviewError }}</p>
          <p v-if="reviewSuccess" class="text-sm text-green-600">{{ reviewSuccess }}</p>

          <button
            @click="submitReview"
            :disabled="reviewSubmitting"
            class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ reviewSubmitting ? 'กำลังบันทึก...' : 'ยืนยันผลการตรวจสอบ' }}
          </button>
        </div>
      </template>
    </template>
  </div>
</template>
