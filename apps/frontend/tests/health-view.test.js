import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import SystemHealthView from '@/views/system/SystemHealthView.vue'
import { useAuthStore } from '@/stores/auth'

describe('SEC-011 / SEC-012: System Health and Alert Triage UI', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
  })

  it('renders system health overview cards and active alerts', async () => {
    const authStore = useAuthStore()
    authStore.user = { id: 1, name: 'Admin', permissions: ['system.view', 'system.manage'] }

    vi.spyOn(api, 'get').mockImplementation((url) => {
      if (url === '/system/health') {
        return Promise.resolve({
          data: {
            success: true,
            data: {
              status: 'HEALTHY',
              timestamp: '2026-09-30T00:00:00Z',
              core_services: {
                backend: { status: 'UP' },
                database: { status: 'UP' },
                redis: { status: 'UP' },
              },
              edge_devices: {
                cameras: { total: 4, active: 4, offline: 0 },
                ai_nodes: { total: 2, online: 2, offline: 0 },
              },
              alerts: { open_total: 1, open_critical: 0 },
            },
          },
        })
      }
      if (url === '/system/alerts') {
        return Promise.resolve({
          data: {
            success: true,
            data: [
              {
                id: 'alert-123',
                type: 'CAMERA_DOWN',
                severity: 'WARNING',
                title: 'Camera 1 FPS drop',
                message: 'Frame rate degraded below 10 FPS',
                status: 'OPEN',
              },
            ],
          },
        })
      }
      return Promise.reject(new Error(`Unhandled URL: ${url}`))
    })

    const wrapper = mount(SystemHealthView, {
      global: {
        plugins: [pinia],
      },
    })

    await wrapper.vm.$nextTick()
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.text()).toContain('System Health & Operations')
    expect(wrapper.text()).toContain('HEALTHY')
    expect(wrapper.text()).toContain('Camera 1 FPS drop')
    expect(wrapper.find('button').text()).toContain('Refresh')
  })
})
