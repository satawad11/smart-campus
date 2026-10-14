import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import DashboardView from '@/views/DashboardView.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { decisionLabel, roleLabel, errorMessage } from '@/constants/labels'

const LATIN = /[A-Za-z]/

function mockDashboard() {
  vi.spyOn(api, 'get').mockImplementation((url) => {
    const data = {
      '/dashboard/summary': {
        detected: 120,
        recognized: 100,
        review: 8,
        unknown: 12,
        allow: 95,
        deny: 5,
        cameras_online: 2,
        cameras_total: 2,
        ai_nodes_online: 1,
        ai_nodes_total: 1,
      },
      '/dashboard/events-by-hour': [
        { hour: '08:00', count: 40 },
        { hour: '09:00', count: 12 },
      ],
      '/dashboard/events-by-decision': [
        { decision: 'ALLOW', count: 95 },
        { decision: 'DENY', count: 5 },
        { decision: 'REVIEW', count: 8 },
        { decision: 'UNKNOWN', count: 12 },
      ],
      '/dashboard/events-by-camera': [{ camera_uuid: 'c1', camera_name: 'ประตูหน้า', count: 80 }],
      '/dashboard/events-by-department': [
        { department_uuid: 'd1', department_name: 'ช่างยนต์', count: 60 },
      ],
    }[url]
    return Promise.resolve({ data: { data } })
  })
}

describe('Dashboard shown entirely in Thai', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
  })

  it('renders no Latin text on the dashboard (Thai data)', async () => {
    mockDashboard()

    const wrapper = mount(DashboardView)
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('อนุญาต')
    expect(text).toContain('ปฏิเสธ')
    expect(text).toContain('รอตรวจสอบ')
    expect(text).toContain('ไม่ทราบตัวตน')
    expect(text).toContain('หน่วยประมวลผลปัญญาประดิษฐ์ออนไลน์')
    expect(text.match(LATIN)).toBeNull()
  })

  it('shows Thai error messages, never the raw English API message', async () => {
    vi.spyOn(api, 'get').mockRejectedValue({
      code: 'PERMISSION_DENIED',
      message: 'Permission denied',
    })

    const wrapper = mount(DashboardView)
    await flushPromises()

    expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์ดูข้อมูลส่วนนี้')
    expect(wrapper.text()).not.toContain('Permission denied')

    vi.spyOn(api, 'get').mockRejectedValue({ code: 'UNKNOWN_ERROR', message: 'Network Error' })
    const second = mount(DashboardView)
    await flushPromises()
    expect(second.text()).toContain('ไม่สามารถโหลดข้อมูลแดชบอร์ดได้')
    expect(second.text().match(LATIN)).toBeNull()
  })

  it('renders the menu and header without Latin text for a Thai-named user', () => {
    const store = useAuthStore()
    store.user = {
      username: 'ผู้ดูแล',
      mfa_enabled: false,
      permissions: ['*'],
      roles: [{ code: 'SUPER_ADMIN', name: 'Super Administrator' }],
    }
    store.can = () => true

    const wrapper = mount(AdminLayout, {
      global: {
        plugins: [pinia],
        stubs: { 'router-link': { template: '<a><slot /></a>' }, RouterView: true },
      },
    })

    const text = wrapper.text()
    expect(text).toContain('ผู้ดูแลระบบสูงสุด')
    expect(text).toContain('สิทธิ์การใช้งานระบบ')
    expect(text).toContain('ตั้งค่ายืนยันตัวตนสองชั้น')
    expect(text).toContain('จัดการหน่วยประมวลผลปัญญาประดิษฐ์')
    expect(text.match(LATIN)).toBeNull()
  })

  it('label helpers translate codes and fall back safely', () => {
    expect(decisionLabel('ALLOW')).toBe('อนุญาต')
    expect(decisionLabel('NEW_CODE')).toBe('NEW_CODE')
    expect(roleLabel({ code: 'SYSTEM_ADMIN', name: 'System Administrator' })).toBe('ผู้ดูแลระบบ')
    expect(roleLabel({ code: 'CUSTOM', name: 'ผู้ตรวจ' })).toBe('ผู้ตรวจ')
    expect(errorMessage({ code: 'X' }, 'ข้อผิดพลาด')).toBe('ข้อผิดพลาด')
  })
})
