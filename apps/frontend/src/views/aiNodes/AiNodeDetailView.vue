<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import aiNodesService from '@/services/aiNodes'
import NodeOperationsPanel from '@/components/aiNodes/NodeOperationsPanel.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const node = ref(null)
const loading = ref(false)
const errorMessage = ref('')
const activeTab = ref('overview')

const canManage = computed(() => authStore.can('ai_node.manage'))

// Credentials tab
const credentials = ref([])
const credentialsLoading = ref(false)
const issuedToken = ref(null)
const issueError = ref('')
const issueLoading = ref(false)

// Health tab
const healthLogs = ref([])
const healthLoading = ref(false)

async function fetchNode() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await aiNodesService.getNode(route.params.uuid)
    node.value = res.data
  } catch (err) {
    errorMessage.value = err.message || 'Failed to load AI node.'
  } finally {
    loading.value = false
  }
}

async function fetchCredentials() {
  credentialsLoading.value = true
  try {
    const res = await aiNodesService.listCredentials(node.value.uuid)
    credentials.value = res.data || []
  } catch {
    credentials.value = []
  } finally {
    credentialsLoading.value = false
  }
}

async function issueCredential() {
  issueLoading.value = true
  issueError.value = ''
  issuedToken.value = null
  try {
    const res = await aiNodesService.issueCredential(node.value.uuid)
    issuedToken.value = res.data.token
    await fetchCredentials()
  } catch (err) {
    issueError.value = err.message || 'Failed to issue credential.'
  } finally {
    issueLoading.value = false
  }
}

async function revokeCredential(credential) {
  try {
    await aiNodesService.revokeCredential(node.value.uuid, credential.uuid)
    await fetchCredentials()
  } catch (err) {
    issueError.value = err.message || 'Failed to revoke credential.'
  }
}

async function fetchHealth() {
  healthLoading.value = true
  try {
    const res = await aiNodesService.getHealth(node.value.uuid, { per_page: 20 })
    healthLogs.value = res.data || []
  } catch {
    healthLogs.value = []
  } finally {
    healthLoading.value = false
  }
}

function setTab(tab) {
  activeTab.value = tab
  if (tab === 'credentials' && credentials.value.length === 0) fetchCredentials()
  if (tab === 'health' && healthLogs.value.length === 0) fetchHealth()
}

function healthBadgeClass(status) {
  if (status === 'ONLINE')
    return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
  if (status === 'DEGRADED')
    return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300'
  return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
}

function credentialBadgeClass(status) {
  return status === 'ACTIVE'
    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
    : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
}

onMounted(() => {
  fetchNode()
})
</script>

<template>
  <div class="ai-node-detail-view max-w-5xl mx-auto p-6 space-y-6">
    <button
      @click="router.push({ name: 'ai-nodes.index' })"
      class="text-sm text-blue-600 hover:underline"
    >
      &larr; Back to AI Nodes
    </button>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-500">Loading AI node...</div>

    <template v-else-if="node">
      <div class="border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ node.name }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 font-mono">{{ node.node_code }}</p>
      </div>

      <div class="flex gap-2 border-b border-gray-200 dark:border-gray-700 text-sm">
        <button
          v-for="tab in ['overview', 'credentials', 'health', 'operations']"
          :key="tab"
          @click="setTab(tab)"
          class="px-4 py-2 font-medium capitalize"
          :class="
            activeTab === tab
              ? 'border-b-2 border-blue-600 text-blue-600'
              : 'text-gray-500 hover:text-gray-700'
          "
        >
          {{ tab }}
        </button>
      </div>

      <!-- Overview -->
      <div
        v-if="activeTab === 'overview'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-3 text-sm"
      >
        <div class="grid grid-cols-2 gap-4">
          <div><span class="text-gray-500">Status:</span> {{ node.status }}</div>
          <div><span class="text-gray-500">Site:</span> {{ node.site?.name || 'Unassigned' }}</div>
          <div><span class="text-gray-500">Hostname:</span> {{ node.hostname || '-' }}</div>
          <div><span class="text-gray-500">IP Address:</span> {{ node.ip_address || '-' }}</div>
          <div><span class="text-gray-500">API URL:</span> {{ node.api_url || '-' }}</div>
          <div>
            <span class="text-gray-500">Software Version:</span> {{ node.software_version || '-' }}
          </div>
          <div data-test="node-model">
            <span class="text-gray-500">Model:</span>
            {{ node.model_code ? `${node.model_code} ${node.model_version}` : '-' }}
            <span
              v-if="node.model_compatible === false"
              class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800"
              >ไม่ตรงกับทะเบียนโมเดล</span
            >
            <span
              v-else-if="node.model_compatible === true"
              class="ml-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800"
              >ตรงกับทะเบียนโมเดล</span
            >
          </div>
          <div>
            <span class="text-gray-500">Hardware Type:</span> {{ node.hardware_type || '-' }}
          </div>
          <div><span class="text-gray-500">CPU Model:</span> {{ node.cpu_model || '-' }}</div>
          <div><span class="text-gray-500">GPU:</span> {{ node.gpu_name || '-' }}</div>
          <div>
            <span class="text-gray-500">GPU Memory:</span>
            {{ node.gpu_memory_mb ? `${node.gpu_memory_mb} MB` : '-' }}
          </div>
          <div><span class="text-gray-500">Max Cameras:</span> {{ node.max_camera ?? '-' }}</div>
          <div>
            <span class="text-gray-500">Last Seen:</span>
            {{ node.last_seen_at ? new Date(node.last_seen_at).toLocaleString() : 'Never' }}
          </div>
        </div>
      </div>

      <!-- Credentials -->
      <div
        v-if="activeTab === 'credentials'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4"
      >
        <p class="text-sm text-gray-500 dark:text-gray-400">
          Per-node bearer credentials used by the machine-to-machine /internal/v1 surface (CAM-004).
          A newly issued token is shown once and cannot be retrieved again.
        </p>

        <button
          v-if="canManage"
          @click="issueCredential"
          :disabled="issueLoading"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          {{ issueLoading ? 'Issuing...' : 'Issue New Credential' }}
        </button>

        <p v-if="issueError" class="text-sm text-red-600">{{ issueError }}</p>

        <div
          v-if="issuedToken"
          class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm space-y-1"
        >
          <p class="font-semibold text-yellow-800">New credential token (shown once):</p>
          <code class="block break-all bg-white p-2 rounded border text-xs">{{ issuedToken }}</code>
        </div>

        <div v-if="credentialsLoading" class="text-center py-6 text-gray-500">
          Loading credentials...
        </div>
        <table
          v-else-if="credentials.length > 0"
          class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm"
        >
          <thead class="bg-gray-50 dark:bg-gray-700/50">
            <tr>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Status</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Issued</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Last Used</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Revoked</th>
              <th class="px-4 py-2 text-right font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="cred in credentials" :key="cred.uuid">
              <td class="px-4 py-2">
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-semibold"
                  :class="credentialBadgeClass(cred.status)"
                  >{{ cred.status }}</span
                >
              </td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ cred.created_at ? new Date(cred.created_at).toLocaleString() : '-' }}
              </td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ cred.last_used_at ? new Date(cred.last_used_at).toLocaleString() : 'Never' }}
              </td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ cred.revoked_at ? new Date(cred.revoked_at).toLocaleString() : '-' }}
              </td>
              <td class="px-4 py-2 text-right">
                <button
                  v-if="canManage && cred.status === 'ACTIVE'"
                  @click="revokeCredential(cred)"
                  class="px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded text-xs"
                >
                  Revoke
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="text-center py-6 text-gray-400 text-sm">No credentials issued yet.</p>
      </div>

      <!-- Health -->
      <div
        v-if="activeTab === 'health'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
      >
        <div v-if="healthLoading" class="text-center py-8 text-gray-500">
          Loading health history...
        </div>
        <div v-else-if="healthLogs.length === 0" class="text-center py-8 text-gray-400">
          No health telemetry recorded yet.
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
          <thead class="bg-gray-50 dark:bg-gray-700/50">
            <tr>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Status</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">CPU</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">RAM</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">GPU</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Queue Depth</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Recorded At</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="(log, i) in healthLogs" :key="i">
              <td class="px-4 py-2">
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-semibold"
                  :class="healthBadgeClass(log.status)"
                  >{{ log.status }}</span
                >
              </td>
              <td class="px-4 py-2">{{ log.cpu_usage != null ? `${log.cpu_usage}%` : '-' }}</td>
              <td class="px-4 py-2">{{ log.ram_usage != null ? `${log.ram_usage}%` : '-' }}</td>
              <td class="px-4 py-2">{{ log.gpu_usage != null ? `${log.gpu_usage}%` : '-' }}</td>
              <td class="px-4 py-2">{{ log.queue_depth ?? '-' }}</td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ log.recorded_at ? new Date(log.recorded_at).toLocaleString() : '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Operations (COM-03) -->
      <NodeOperationsPanel v-if="activeTab === 'operations'" :node-uuid="node.uuid" />
    </template>
  </div>
</template>
