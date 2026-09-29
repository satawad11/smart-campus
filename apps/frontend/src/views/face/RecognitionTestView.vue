<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import facesService from '@/services/faces'

const authStore = useAuthStore()

const probeFile = ref(null)
const previewUrl = ref('')
const topK = ref(5)
const minConfidence = ref(0.5)
const loading = ref(false)
const errorMessage = ref('')
const testResult = ref(null)

const canTest = computed(() => authStore.can('face.test'))

function onFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return

  probeFile.value = file
  previewUrl.value = URL.createObjectURL(file)
  testResult.value = null
  errorMessage.value = ''
}

async function runTest() {
  if (!probeFile.value) {
    errorMessage.value = 'Please select a probe face image for testing.'
    return
  }

  loading.value = true
  errorMessage.value = ''
  testResult.value = null

  const formData = new FormData()
  formData.append('image', probeFile.value)
  formData.append('top_k', topK.value)
  formData.append('min_confidence', minConfidence.value)

  try {
    const res = await facesService.runRecognitionTest(formData)
    testResult.value = res.data
  } catch (err) {
    errorMessage.value = err.message || 'Recognition diagnostic test failed.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="recognition-test-view max-w-6xl mx-auto p-6 space-y-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
        Recognition Diagnostic Test (FACE-018)
      </h1>
      <p class="text-sm text-gray-500 dark:text-gray-400">
        Restricted developer & operator tool to evaluate vector similarity search and perception
        pipeline latency.
      </p>
    </div>

    <!-- Permission Guard -->
    <div v-if="!canTest" class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
      Access denied. You require the <code class="font-semibold">face.test</code> permission to
      execute recognition diagnostics.
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Probe Configuration -->
      <div
        class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 space-y-4"
      >
        <h2 class="text-base font-bold text-gray-900 dark:text-white">Probe Parameters</h2>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Probe Image</label
          >
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            @change="onFileSelected"
            class="w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-blue-50 file:text-blue-700"
          />
        </div>

        <div v-if="previewUrl">
          <p class="text-xs text-gray-400 mb-1">Probe Preview</p>
          <img :src="previewUrl" alt="Probe" class="w-full h-44 object-cover rounded-lg border" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Top K Matches (1 - 20)</label
          >
          <input
            v-model.number="topK"
            type="number"
            min="1"
            max="20"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:text-white"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Min Confidence (0.0 - 1.0)</label
          >
          <input
            v-model.number="minConfidence"
            type="number"
            step="0.05"
            min="0.0"
            max="1.0"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:text-white"
          />
        </div>

        <button
          @click="runTest"
          :disabled="loading || !probeFile"
          class="w-full py-2.5 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition"
        >
          <span v-if="loading">Evaluating Vector Similarity...</span>
          <span v-else>Run Diagnostic Test</span>
        </button>

        <div v-if="errorMessage" class="p-3 bg-red-50 text-red-700 rounded-lg text-xs">
          {{ errorMessage }}
        </div>
      </div>

      <!-- Results Display -->
      <div class="lg:col-span-2 space-y-6">
        <div
          v-if="!testResult && !loading"
          class="bg-white dark:bg-gray-800 p-12 text-center rounded-xl border text-gray-400 text-sm"
        >
          Run a diagnostic probe to view ranked nearest neighbor similarity candidates.
        </div>

        <div v-if="testResult" class="space-y-4">
          <!-- Diagnostics & Latency Banner -->
          <div
            class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs"
          >
            <div>
              <div class="text-gray-400">Model</div>
              <div class="font-bold text-gray-900 dark:text-white">
                {{ testResult.model?.code }}
              </div>
            </div>
            <div>
              <div class="text-gray-400">Probe Quality</div>
              <div class="font-bold text-blue-600">
                {{ (testResult.probe_quality_score * 100).toFixed(1) }}%
              </div>
            </div>
            <div>
              <div class="text-gray-400">Total Latency</div>
              <div class="font-bold text-emerald-600">{{ testResult.latency_ms?.total_ms }} ms</div>
            </div>
            <div>
              <div class="text-gray-400">Candidates Found</div>
              <div class="font-bold text-gray-900 dark:text-white">
                {{ testResult.candidates_count }}
              </div>
            </div>
          </div>

          <!-- Candidates List -->
          <div
            class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
          >
            <div class="p-4 border-b">
              <h3 class="font-bold text-sm text-gray-900 dark:text-white">
                Ranked Candidate Matches (pgvector HNSW)
              </h3>
            </div>

            <div
              v-if="testResult.candidates?.length === 0"
              class="p-8 text-center text-gray-400 text-sm"
            >
              No candidates found above confidence threshold
              {{ (minConfidence * 100).toFixed(0) }}%.
            </div>

            <div v-else class="divide-y divide-gray-100 dark:divide-gray-700">
              <div
                v-for="candidate in testResult.candidates"
                :key="candidate.rank"
                class="p-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700/20 text-sm"
              >
                <div class="flex items-center space-x-4">
                  <span
                    class="w-7 h-7 flex items-center justify-center rounded-full bg-indigo-50 text-indigo-700 font-bold text-xs"
                  >
                    #{{ candidate.rank }}
                  </span>
                  <div>
                    <div class="font-mono text-xs font-semibold text-gray-900 dark:text-white">
                      Person: {{ candidate.person_uuid }}
                    </div>
                    <div class="text-xs text-gray-400 font-mono">
                      Profile: {{ candidate.face_profile_uuid }}
                    </div>
                  </div>
                </div>

                <div class="text-right">
                  <div class="font-bold text-sm text-indigo-600">
                    {{ (candidate.similarity * 100).toFixed(2) }}%
                  </div>
                  <div class="text-xs text-gray-400">
                    Distance: {{ candidate.distance?.toFixed(4) }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
