import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import DashboardView from '@/views/DashboardView.vue'
import HistorySearchView from '@/views/history/HistorySearchView.vue'
import ReportsView from '@/views/reports/ReportsView.vue'

describe('Module 06 Frontend Views (RPT-019, RPT-020, RPT-021)', () => {
  let router

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/dashboard', name: 'dashboard', component: DashboardView },
        { path: '/history', name: 'history.search', component: HistorySearchView },
        { path: '/reports', name: 'reports.index', component: ReportsView },
      ],
    })
    await router.push('/dashboard')
    await router.isReady()
  })

  it('RPT-019: DashboardView renders metrics from summary and charts API', async () => {
    vi.spyOn(api, 'get').mockImplementation((url) => {
      if (url === '/dashboard/summary') {
        return Promise.resolve({
          data: {
            // Shape returned by DashboardController::summary (RPT-005).
            data: {
              date: '2026-09-29',
              events: {
                detected: 150,
                recognized: 120,
                review: 10,
                unknown: 20,
                allow: 110,
                deny: 20,
              },
              unique_persons_recognized: 95,
              cameras: { online: 8, total: 10 },
              ai_nodes: { online: 2, total: 2 },
            },
          },
        })
      }
      if (url === '/dashboard/events-by-hour') {
        return Promise.resolve({
          data: {
            data: {
              date: '2026-09-29',
              series: [
                { hour: '08:00', count: 35 },
                { hour: '09:00', count: 50 },
              ],
            },
          },
        })
      }
      if (url === '/dashboard/events-by-decision') {
        return Promise.resolve({
          data: {
            data: {
              series: [
                { decision: 'ALLOW', count: 110 },
                { decision: 'DENY', count: 20 },
              ],
            },
          },
        })
      }
      if (url === '/dashboard/events-by-camera') {
        return Promise.resolve({
          data: {
            data: { series: [{ camera_uuid: 'cam-1', camera_name: 'Main Gate Cam', count: 90 }] },
          },
        })
      }
      if (url === '/dashboard/events-by-department') {
        return Promise.resolve({
          data: {
            data: {
              series: [
                { department_uuid: 'dept-1', department_name: 'Computer Science', count: 70 },
              ],
            },
          },
        })
      }
      return Promise.reject(new Error(`Unhandled URL: ${url}`))
    })

    const wrapper = mount(DashboardView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('แดชบอร์ดภาพรวมระบบ')
    expect(wrapper.text()).toContain('150')
    expect(wrapper.text()).toContain('8 / 10')
    expect(wrapper.text()).toContain('95 คนไม่ซ้ำ')
    expect(wrapper.text()).toContain('Main Gate Cam')
    expect(wrapper.text()).toContain('Computer Science')
  })

  it('RPT-020: HistorySearchView performs queries and renders events', async () => {
    const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        data: [
          {
            uuid: 'evt-hist-1',
            event_type: 'FACE_RECOGNIZED',
            decision: 'ALLOW',
            event_time: '2026-09-29T10:00:00Z',
            person: { uuid: 'p-1', name: 'Somchai Prasert', person_code: 'STD-1001' },
            camera: { uuid: 'c-1', name: 'Turnstile A' },
            site: { uuid: 's-1', name: 'Central Campus' },
          },
        ],
        meta: { current_page: 1, last_page: 1, total: 1 },
      },
    })

    const wrapper = mount(HistorySearchView, { global: { plugins: [router] } })
    await flushPromises()

    expect(getSpy).toHaveBeenCalledWith('/history/search', expect.any(Object))
    expect(wrapper.text()).toContain('Somchai Prasert')
    expect(wrapper.text()).toContain('Turnstile A')
    expect(wrapper.text()).toContain('ALLOW')
  })

  it('RPT-021: ReportsView previews report and triggers export requests', async () => {
    const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        data: {
          from: '2026-09-29',
          to: '2026-09-29',
          totals: {
            detected: 80,
            recognized: 60,
            review: 5,
            unknown: 15,
            allow: 55,
            deny: 10,
            unique_persons_recognized: 45,
          },
          by_hour: [{ hour: '08:00', count: 20 }],
        },
      },
    })

    const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
      data: {
        data: {
          uuid: 'exp-1',
          report_type: 'daily',
          format: 'CSV',
          status: 'PENDING',
        },
      },
    })

    const wrapper = mount(ReportsView, { global: { plugins: [router] } })
    await flushPromises()

    expect(getSpy).toHaveBeenCalledWith('/reports/daily', expect.any(Object))
    expect(wrapper.text()).toContain('ศูนย์รายงานและการส่งออกข้อมูล')
    expect(wrapper.text()).toContain('80')

    // Click CSV Export button
    const csvButton = wrapper.findAll('button').find((b) => b.text() === 'CSV')
    expect(csvButton).toBeDefined()
    await csvButton.trigger('click')
    await flushPromises()

    expect(postSpy).toHaveBeenCalledWith(
      '/reports/exports',
      expect.objectContaining({
        report_type: 'daily',
        format: 'CSV',
      }),
    )
  })
})
