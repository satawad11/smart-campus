import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import router from '@/router'

// AUDIT-A14 (owner decision 2026-11-20): a SECURITY_OFFICER (seeded permissions,
// dashboard.view but no report.view) lands on the dashboard after sign-in.
// The backend remains authoritative; this only checks the UI routing.
const SECURITY_OFFICER_PERMISSIONS = [
  'dashboard.view',
  'monitor.view',
  'student.view',
  'camera.view',
  'camera.live',
  'event.view',
  'staff.view',
  'guardian.view',
  'visitor.view',
  'visitor.manage',
  'visit.view',
  'visit.manage',
]

describe('SECURITY_OFFICER landing page (AUDIT-A14)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    const store = useAuthStore()
    store.initialized = true
    store.user = {
      username: 'officer',
      roles: ['SECURITY_OFFICER'],
      permissions: SECURITY_OFFICER_PERMISSIONS,
      mfa_enrollment_required: false,
    }
  })

  it('lands on the dashboard from the root and from the login page', async () => {
    await router.push('/')
    expect(router.currentRoute.value.name).toBe('dashboard')

    await router.push('/login')
    expect(router.currentRoute.value.name).toBe('dashboard')
  })
})
