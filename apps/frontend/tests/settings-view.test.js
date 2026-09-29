import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import SystemSettingsView from '@/views/settings/SystemSettingsView.vue'
import { useAuthStore } from '@/stores/auth'

describe('SEC-001 / SEC-002: System Settings and Feature Flags UI', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
  })

  it('renders system settings tabs and loads initial settings', async () => {
    const authStore = useAuthStore()
    authStore.user = { id: 1, name: 'Admin', permissions: ['system.view', 'system.manage'] }

    vi.spyOn(api, 'get').mockResolvedValueOnce({
      data: {
        success: true,
        data: {
          GENERAL: [
            {
              key: 'general.site_name',
              type: 'string',
              value: 'AI Smart Campus Security Platform',
              description: 'System display name',
              is_public: true,
            },
          ],
          FEATURE: [
            {
              key: 'feature.door',
              type: 'boolean',
              value: false,
              description: 'Door access control actuator',
              is_public: true,
            },
          ],
        },
      },
    })

    const wrapper = mount(SystemSettingsView, {
      global: {
        plugins: [pinia],
      },
    })

    await wrapper.vm.$nextTick()
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.text()).toContain('การตั้งค่าระบบ')
    expect(wrapper.text()).toContain('general.site_name')
  })

  it('shows locked status on Production V1 locked feature flags', async () => {
    const authStore = useAuthStore()
    authStore.user = { id: 1, name: 'Admin', permissions: ['system.view', 'system.manage'] }

    vi.spyOn(api, 'get').mockResolvedValueOnce({
      data: {
        success: true,
        data: {
          FEATURE: [
            {
              key: 'feature.door',
              type: 'boolean',
              value: false,
              description: 'Door actuator integration',
              is_public: true,
            },
          ],
        },
      },
    })

    const wrapper = mount(SystemSettingsView, {
      global: {
        plugins: [pinia],
      },
    })

    await wrapper.vm.$nextTick()
    await new Promise((resolve) => setTimeout(resolve, 50))

    // Click on FEATURE tab
    const featureTab = wrapper.findAll('button').find((b) => b.text().includes('ฟีเจอร์ระบบ'))
    if (featureTab) {
      await featureTab.trigger('click')
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Locked V1')
    }
  })
})
