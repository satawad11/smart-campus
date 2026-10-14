import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import { licenseService } from '@/services/license'
import { useAuthStore } from '@/stores/auth'
import LicenseView from '@/views/system/LicenseView.vue'

const VALID_STATE = {
  state: 'VALID',
  installation_id: '11111111-2222-4333-8444-555555555555',
  enforcement: 'enforce',
  clock_rollback_detected: false,
  license: {
    licensee_name: 'Pilot College',
    edition: 'COMMERCIAL',
    plan: 'STANDARD',
    features: ['face', 'report'],
    limits: { max_cameras: 8, max_ai_nodes: null },
    expires_at: '2027-10-01T00:00:00+00:00',
    grace_ends_at: '2027-10-15T00:00:00+00:00',
    days_until_expiry: 352,
  },
}

describe('COM-02: licence status UI', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
  })

  function mountAs(permissions) {
    useAuthStore().user = { username: 'admin', permissions }
    return mount(LicenseView, { global: { plugins: [pinia] } })
  }

  it('calls the licence endpoints', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: {} } })
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: {} } })

    await licenseService.getStatus()
    await licenseService.install('{"x":1}')
    await licenseService.importRevocations('{"y":2}')

    expect(get).toHaveBeenCalledWith('/system/license')
    expect(post).toHaveBeenNthCalledWith(1, '/system/license', { license: '{"x":1}' })
    expect(post).toHaveBeenNthCalledWith(2, '/system/license/revocations', {
      revocation_list: '{"y":2}',
    })
  })

  it('shows state, installation ID and limits; hides install form without system.manage', async () => {
    vi.spyOn(licenseService, 'getStatus').mockResolvedValue(VALID_STATE)

    const wrapper = mountAs(['system.view'])
    await flushPromises()

    expect(wrapper.get('[data-test="license-state"]').text()).toBe('ใช้งานได้')
    expect(wrapper.get('[data-test="installation-id"]').text()).toBe(VALID_STATE.installation_id)
    expect(wrapper.get('[data-test="license-limits"]').text()).toBe('8 / ไม่จำกัด')
    expect(wrapper.find('[data-test="install-license"]').exists()).toBe(false)
  })

  it('installs a licence and shows the new state', async () => {
    vi.spyOn(licenseService, 'getStatus').mockResolvedValue({
      ...VALID_STATE,
      state: 'NONE',
      license: null,
    })
    const install = vi.spyOn(licenseService, 'install').mockResolvedValue(VALID_STATE)

    const wrapper = mountAs(['system.view', 'system.manage'])
    await flushPromises()
    expect(wrapper.get('[data-test="license-state"]').text()).toBe('ยังไม่ได้ติดตั้ง License')

    await wrapper
      .get('[data-test="license-document"]')
      .setValue('{"format":"smart-campus-signed/v1"}')
    await wrapper.get('[data-test="install-license"]').trigger('click')
    await flushPromises()

    expect(install).toHaveBeenCalledWith('{"format":"smart-campus-signed/v1"}')
    expect(wrapper.get('[data-test="license-state"]').text()).toBe('ใช้งานได้')
    expect(wrapper.get('[role="status"]').text()).toContain('ติดตั้ง License สำเร็จ')
  })

  it('maps licence rejection codes to Thai messages', async () => {
    vi.spyOn(licenseService, 'getStatus').mockResolvedValue(VALID_STATE)
    vi.spyOn(licenseService, 'install').mockRejectedValue({ code: 'LICENSE_SIGNATURE_INVALID' })

    const wrapper = mountAs(['system.view', 'system.manage'])
    await flushPromises()
    await wrapper.get('[data-test="license-document"]').setValue('tampered')
    await wrapper.get('[data-test="install-license"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('ลายเซ็นของ License ไม่ถูกต้อง')
  })

  it('does not call the API with an empty document', async () => {
    vi.spyOn(licenseService, 'getStatus').mockResolvedValue(VALID_STATE)
    const install = vi.spyOn(licenseService, 'install')

    const wrapper = mountAs(['system.view', 'system.manage'])
    await flushPromises()
    await wrapper.get('[data-test="install-license"]').trigger('click')
    await flushPromises()

    expect(install).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('กรุณาวางเนื้อหาไฟล์')
  })
})
