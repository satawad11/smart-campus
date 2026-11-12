import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import api from '@/services/api'
import { integrationsService } from '@/services/integrations'
import { useAuthStore } from '@/stores/auth'
import IntegrationsView from '@/views/system/IntegrationsView.vue'

describe('ACR-INT-02: external API connections page', () => {
  const rms = {
    code: 'RMS',
    name: 'RMS API Center',
    description: 'RMS',
    base_url: 'https://rms.example.test/api_center.php',
    base_url_source: 'env',
    api_key_set: true,
    api_key_hint: 'rmsk_9bcdb909_…',
    api_key_source: 'database',
    api_key_unreadable: false,
    timeout_seconds: 30,
    sync_enabled: false,
    sync_time: '05:30',
    sync_cron: '30 5 * * *',
    last_test: null,
    updated_by: 'admin',
    updated_at: '2026-11-12T03:00:00Z',
  }

  let auth

  function mountAs(permissions) {
    auth = useAuthStore()
    vi.spyOn(auth, 'hasPermission').mockImplementation((p) => permissions.includes(p))
    return mount(IntegrationsView)
  }

  beforeEach(() => {
    vi.restoreAllMocks()
    setActivePinia(createPinia())
    vi.spyOn(integrationsService, 'list').mockResolvedValue([rms])
  })

  it('service uses the integrations endpoints', async () => {
    vi.restoreAllMocks()
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [] } })
    const put = vi.spyOn(api, 'put').mockResolvedValue({ data: { data: rms } })
    const post = vi
      .spyOn(api, 'post')
      .mockResolvedValue({ data: { data: { ok: true }, meta: { connection: rms } } })
    await integrationsService.list()
    await integrationsService.updateRms({ base_url: 'https://x' })
    const tested = await integrationsService.testRms()
    expect(get).toHaveBeenCalledWith('/integrations')
    expect(put).toHaveBeenCalledWith('/integrations/rms', { base_url: 'https://x' })
    expect(post).toHaveBeenCalledWith('/integrations/rms/test')
    expect(tested.connection).toEqual(rms)
  })

  it('shows the key only as its hint and source, never a value', async () => {
    const wrapper = mountAs(['system.view', 'integration.manage'])
    await flushPromises()

    expect(wrapper.find('[data-test="status"]').text()).toBe('ยังไม่ได้ทดสอบ')
    expect(wrapper.find('[data-test="key-source"]').text()).toContain('rmsk_9bcdb909_…')
    expect(wrapper.find('[data-test="key-source"]').text()).toContain('บันทึกจากหน้าเว็บ')
    expect(wrapper.find('[data-test="api-key"]').element.value).toBe('')
    expect(wrapper.find('[data-test="api-key"]').attributes('type')).toBe('password')
  })

  it('sends a new key only when one is typed, then reloads the card', async () => {
    const update = vi
      .spyOn(integrationsService, 'updateRms')
      .mockResolvedValue({ ...rms, sync_enabled: true, sync_time: '06:00' })
    const wrapper = mountAs(['system.view', 'integration.manage'])
    await flushPromises()

    expect(wrapper.find('[data-test="save"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="sync-enabled"]').setValue(true)
    await wrapper.find('#rms-time').setValue('06:00')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(update).toHaveBeenCalledWith({
      base_url: rms.base_url,
      timeout_seconds: 30,
      sync_enabled: true,
      sync_time: '06:00',
    })
    expect(wrapper.find('[data-test="notice"]').exists()).toBe(true)

    await wrapper.find('[data-test="api-key"]').setValue(' rmsk_new00001_abcdefabcdef ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(update).toHaveBeenLastCalledWith(
      expect.objectContaining({ api_key: 'rmsk_new00001_abcdefabcdef' }),
    )
  })

  it('test is blocked while there are unsaved changes and shows the result', async () => {
    const testRms = vi.spyOn(integrationsService, 'testRms').mockResolvedValue({
      result: {
        ok: false,
        error: 'ip',
        message: 'IP ของเซิร์ฟเวอร์นี้ไม่อยู่ในรายการ',
        duration_ms: 120,
      },
      connection: {
        ...rms,
        last_test: { ok: false, message: 'x', tested_at: '2026-11-12T03:01:00Z' },
      },
    })
    const wrapper = mountAs(['system.view', 'integration.manage'])
    await flushPromises()

    await wrapper.find('[data-test="base-url"]').setValue('https://other.example.test/api')
    expect(wrapper.find('[data-test="test"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('ต้องกรอกกุญแจ API อีกครั้ง')

    await wrapper.find('[data-test="base-url"]').setValue(rms.base_url)
    await wrapper.find('[data-test="test"]').trigger('click')
    await flushPromises()

    expect(testRms).toHaveBeenCalled()
    expect(wrapper.find('[data-test="test-result"]').text()).toContain('เชื่อมต่อไม่สำเร็จ')
    expect(wrapper.find('[data-test="status"]').text()).toBe('เชื่อมต่อไม่ได้')
  })

  it('shows server validation errors next to the field', async () => {
    vi.spyOn(integrationsService, 'updateRms').mockRejectedValue({
      status: 422,
      code: 'VALIDATION_ERROR',
      fields: { api_key: ['เมื่อเปลี่ยนที่อยู่ API ต้องกรอกกุญแจ API อีกครั้ง'] },
    })
    const wrapper = mountAs(['system.view', 'integration.manage'])
    await flushPromises()
    await wrapper.find('[data-test="base-url"]').setValue('https://other.example.test/api')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="error"]').text()).toContain('ต้องกรอกกุญแจ API อีกครั้ง')
  })

  it('is read-only without integration.manage', async () => {
    const wrapper = mountAs(['system.view'])
    await flushPromises()

    expect(wrapper.find('[data-test="save"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="test"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="base-url"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('integration.manage')
  })
})
