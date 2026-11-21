import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import GatePassHistoryView from '@/views/history/GatePassHistoryView.vue'
import ReportsView from '@/views/reports/ReportsView.vue'

/** GATE-008 (ACR-VEH-01): gate pass history search and the per-gate report section. */

const PASSES = [
  {
    uuid: 'pass-2',
    match_result: 'PLATE_ONLY',
    occurred_at: '2026-11-20T08:00:00Z',
    gate: { uuid: 'gate-1', code: 'G1', name: 'ประตูหน้า' },
    face_event_uuid: null,
    plate_event_uuid: 'evt-plate',
    plate: { plate_display: '2คง 5678', plate_province_name: 'กรุงเทพมหานคร' },
    person: null,
    vehicle: null,
    decision: {
      uuid: 'd2',
      decision: 'DENY',
      reason_code: 'OFFICER_OVERRIDE',
      decision_source: 'OFFICER',
    },
  },
  {
    uuid: 'pass-1',
    match_result: 'MATCHED',
    occurred_at: '2026-11-20T07:45:00Z',
    gate: { uuid: 'gate-1', code: 'G1', name: 'ประตูหน้า' },
    face_event_uuid: 'evt-face',
    plate_event_uuid: 'evt-plate-1',
    plate: { plate_display: '1กข 1234', plate_province_name: 'กรุงเทพมหานคร' },
    person: { uuid: 'p1', name: 'สมชาย ใจดี', person_code: 'S001' },
    vehicle: { uuid: 'v1', plate_number: '1กข1234' },
    decision: {
      uuid: 'd1',
      decision: 'ALLOW',
      reason_code: 'STUDENT_ACTIVE',
      decision_source: 'POLICY',
    },
  },
]

let router

beforeEach(async () => {
  setActivePinia(createPinia())
  vi.restoreAllMocks()
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/events/:uuid', name: 'events.show', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
})

function apiMock(reportData = null) {
  return vi.spyOn(api, 'get').mockImplementation((url) => {
    if (url === '/features')
      return Promise.resolve({ data: { data: { vehicle: true, alpr: true } } })
    if (url === '/gates') {
      return Promise.resolve({ data: { data: [{ uuid: 'gate-1', name: 'ประตูหน้า' }] } })
    }
    if (url === '/history/gate-passes') {
      return Promise.resolve({
        data: { data: PASSES, meta: { pagination: { current_page: 1, last_page: 1, total: 2 } } },
      })
    }
    if (url.startsWith('/reports/') && url !== '/reports/exports') {
      return Promise.resolve({ data: { data: reportData } })
    }
    return Promise.resolve({ data: { data: [], meta: null } })
  })
}

describe('Gate pass history', () => {
  it('lists passes with gate, match, person and current decision', async () => {
    useAuthStore().user = { uuid: 'u', username: 'o', permissions: ['report.view', 'event.view'] }
    apiMock()
    const wrapper = mount(GatePassHistoryView, { global: { plugins: [router] } })
    await flushPromises()

    const rows = wrapper.findAll('[data-test="gate-pass-row"]')
    expect(rows.length).toBe(2)
    expect(rows[0].find('[data-test="gate-match"]').text()).toBe('เห็นเฉพาะป้ายทะเบียน')
    expect(rows[0].text()).toContain('ไม่ทราบตัวตน')
    expect(rows[0].find('[data-test="gate-pass-decision"]').text()).toBe('ปฏิเสธ')
    expect(rows[0].text()).toContain('ตรวจสอบโดยเจ้าหน้าที่')
    expect(rows[0].find('a').attributes('href')).toBe('/events/evt-plate')
    expect(rows[1].text()).toContain('สมชาย ใจดี')
    expect(rows[1].find('a').attributes('href')).toBe('/events/evt-face')
    expect(wrapper.text()).toContain('ไม่ได้สั่งเปิดไม้กั้น')
    // No gate.view: no gate filter, and the gate list is never requested.
    expect(wrapper.find('#gp-gate').exists()).toBe(false)
    expect(api.get).not.toHaveBeenCalledWith('/gates', expect.anything())
  })

  it('sends the filters, with whole days for the dates', async () => {
    useAuthStore().user = { uuid: 'u', username: 'o', permissions: ['report.view', 'gate.view'] }
    const spy = apiMock()
    const wrapper = mount(GatePassHistoryView, { global: { plugins: [router] } })
    await flushPromises()

    await wrapper.find('#gp-gate').setValue('gate-1')
    await wrapper.find('#gp-match').setValue('PLATE_ONLY')
    await wrapper.find('#gp-decision').setValue('REVIEW')
    await wrapper.find('#gp-plate').setValue('2คง 5678')
    await wrapper.find('#gp-from').setValue('2026-11-01')
    await wrapper.find('#gp-to').setValue('2026-11-20')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const call = spy.mock.calls.filter(([url]) => url === '/history/gate-passes').at(-1)
    expect(call[1].params).toEqual({
      page: 1,
      gate_uuid: 'gate-1',
      match_result: 'PLATE_ONLY',
      decision: 'REVIEW',
      plate: '2คง 5678',
      from: '2026-11-01 00:00:00',
      to: '2026-11-20 23:59:59',
    })
    // Without event.view there is no link to the event.
    expect(wrapper.find('[data-test="gate-pass-row"] a').exists()).toBe(false)
  })
})

describe('Reports', () => {
  it('shows passes per gate by match result and decision', async () => {
    useAuthStore().user = { uuid: 'u', username: 'o', permissions: ['report.view'] }
    apiMock({
      from: '2026-11-20',
      to: '2026-11-20',
      totals: {
        detected: 3,
        recognized: 1,
        review: 1,
        unknown: 0,
        allow: 1,
        deny: 1,
        unique_persons_recognized: 1,
      },
      by_decision: [],
      by_camera: [],
      by_department: [],
      gates: [
        {
          gate_uuid: 'gate-1',
          gate_code: 'G1',
          gate_name: 'ประตูหน้า',
          total: 3,
          by_match_result: { MATCHED: 1, MISMATCH: 0, FACE_ONLY: 1, PLATE_ONLY: 1, UNKNOWN: 0 },
          by_decision: { ALLOW: 1, REVIEW: 1, DENY: 0, UNKNOWN: 0, UNDECIDED: 1 },
        },
      ],
    })
    const wrapper = mount(ReportsView, { global: { plugins: [router] } })
    await flushPromises()

    const section = wrapper.find('[data-test="gate-report"]')
    expect(section.exists()).toBe(true)
    const cells = section.findAll('[data-test="gate-report-row"] td').map((td) => td.text())
    expect(cells).toEqual(['ประตูหน้า', '3', '1', '0', '0', '1', '1', '1', '1', '0'])
  })
})
