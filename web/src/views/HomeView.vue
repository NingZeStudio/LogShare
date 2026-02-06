<script setup lang="ts">
import { ref } from 'vue'
import { apiClient } from '@/lib/api'
import { useRouter } from 'vue-router'
import { t } from '@/lib/i18n'

const content = ref('')
const loading = ref(false)
const error = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const router = useRouter()
const isDragging = ref(false)

const triggerFileSelect = () => {
  fileInput.value?.click()
}

const onFileSelected = async (event: Event) => {
  const input = event.target as HTMLInputElement
  if (!input.files || input.files.length === 0) return

  const file = input.files[0]
  if (!file) return

  if (file.size > 10 * 1024 * 1024) {
    error.value = t('file_too_large')
    return
  }

  try {
    const text = await file.text()
    content.value = text
  } catch (e) {
    error.value = t('file_read_error')
  }
}

// Drag and drop functionality
const handleDragOver = (event: DragEvent) => {
  event.preventDefault()
  isDragging.value = true
}

const handleDragLeave = () => {
  isDragging.value = false
}

const handleDrop = async (event: DragEvent) => {
  event.preventDefault()
  isDragging.value = false

  if (!event.dataTransfer || !event.dataTransfer.files || event.dataTransfer.files.length === 0) return

  const file = event.dataTransfer.files[0]
  if (!file) return

  if (file.size > 10 * 1024 * 1024) {
    error.value = t('file_too_large');
    return;
  }

  try {
    const text = await file.text()
    content.value = text
  } catch (e) {
    error.value = t('file_read_error')
  }
}

const save = async () => {
  if (!content.value.trim()) return

  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams()
    params.append('content', content.value)

    const response = await apiClient.post('/1/log', params, {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        }
    })

    if (response.data.success) {
      router.push(`/${response.data.id}`)
    } else {
      error.value = response.data.error || t('unknown_error')
    }
  } catch (e: any) {
    console.error(e)
    error.value = e.response?.data?.error || e.message || t('save_failed')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="container mx-auto px-4 py-12 flex flex-col items-center gap-8 relative overflow-hidden">
    <!-- Grid background -->
    <div class="fixed inset-0 -z-10 h-full w-full bg-white bg-[linear-gradient(to_right,#f0f0f0_1px,transparent_1px),linear-gradient(to_bottom,#f0f0f0_1px,transparent_1px)] bg-[size:6rem_4rem]"></div>
    <div class="text-center space-y-4 relative">
      <h1 class="text-4xl font-extrabold tracking-tight lg:text-5xl bg-gradient-to-r from-primary to-primary/80 bg-clip-text text-transparent">
      NingZeLogs
      </h1>
      <p class="text-xl text-muted-foreground">
        {{ t('home_subtitle') }}
      </p>
    </div>


    <!-- Main Paste Area with Mac-style window -->
    <div
      class="w-full max-w-4xl overflow-hidden flex flex-col relative group"
      @dragover="handleDragOver"
      @dragleave="handleDragLeave"
      @drop="handleDrop"
    >
      <!-- Mac-style window header -->
      <div class="bg-gray-800 dark:bg-gray-700 rounded-t-lg px-4 py-2 flex items-center justify-between border-b border-gray-700 dark:border-gray-600">
        <div class="flex items-center gap-2">
          <div class="flex gap-1.5">
            <div class="w-3 h-3 rounded-full bg-red-500"></div>
            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
            <div class="w-3 h-3 rounded-full bg-green-500"></div>
          </div>
          <span class="text-gray-300 dark:text-gray-200 text-sm ml-2">{{ t('log') }}</span>
        </div>
        <div class="flex gap-2">
          <input type="file" ref="fileInput" class="hidden" @change="onFileSelected">
          <button
              @click="triggerFileSelect"
              class="text-gray-300 dark:text-gray-200 hover:text-white text-sm flex items-center gap-1"
          >
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
              </svg>
              {{ t('select_file') }}
          </button>
          <button
              @click="save"
              :disabled="loading || !content"
              class="text-gray-300 dark:text-gray-200 hover:text-white text-sm flex items-center gap-1"
          >
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                <polyline points="17 21 17 13 7 13 7 21"></polyline>
                <polyline points="7 3 7 8 15 8"></polyline>
                <line x1="8" y1="12" x2="16" y2="12"></line>
              </svg>
              {{ loading ? t('saving') : t('save_log') }}
          </button>

        </div>
      </div>

      <!-- Drop zone indicator -->
      <div v-show="isDragging" class="absolute inset-0 bg-blue-500 bg-opacity-20 border-2 border-dashed border-blue-400 rounded-lg flex items-center justify-center z-10 pointer-events-none">
        <div class="bg-blue-500 text-white px-4 py-2 rounded-lg text-lg font-semibold">
          {{ t('home_subtitle') }}
        </div>
      </div>

      <!-- Main content area -->
      <div class="bg-[#1a1a1a] dark:bg-gray-900 border border-gray-700 dark:border-gray-600 rounded-b-lg shadow-lg overflow-hidden flex flex-col">
        <div class="relative flex-1">
            <textarea
              v-model="content"
              class="w-full h-[500px] p-4 bg-[#1a1a1a] dark:bg-gray-900 text-gray-100 dark:text-gray-100 font-mono text-sm resize-none focus:outline-none"
              :placeholder="t('paste_here')"
            ></textarea>

            <div v-if="error" class="absolute bottom-4 left-4 right-4 bg-destructive/10 text-destructive border border-destructive/20 p-3 rounded-md text-sm">
              {{ error }}
            </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.minecraft-text, .logs-text {
  display: inline-block;
  position: relative;
  animation: hueRotate 4s infinite linear;
}

@keyframes hueRotate {
  0% {
    filter: hue-rotate(0deg);
  }
  100% {
    filter: hue-rotate(360deg);
  }
}
</style>
 type="text"
                  v-model="searchTerm"
                  @keyup="handleSearchInput"
                  placeholder="搜索..."
                  class="bg-gray-700 dark:bg-gray-600 text-white dark:text-white text-sm rounded px-3 py-1 w-24 sm:w-32 md:w-40 focus:outline-none focus:ring-1 focus:ring-primary transition-colors duration-300"
              >
              <button @click="performSearch" class="ml-1 text-gray-300 dark:text-gray-200 hover:text-white transition-colors duration-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="11" cy="11" r="8"></circle>
                  <path d="m21 21-4.3-4.3"></path>
                </svg>
              </button>

              <div v-if="searchResults.length > 0" class="ml-2 text-xs text-gray-400 dark:text-gray-300 transition-colors duration-300">
                {{ searchIndex + 1 }}/{{ searchResults.length }}
              </div>
              <button v-if="searchResults.length > 0" @click="goToPrevResult" class="ml-1 text-gray-300 dark:text-gray-200 hover:text-white transition-colors duration-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
              </button>
              <button v-if="searchResults.length > 0" @click="goToNextResult" class="ml-1 text-gray-300 dark:text-gray-200 hover:text-white transition-colors duration-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
              </button>
            </div>

            <button
              v-if="isFullscreen"
              @click="toggleFullscreen"
              class="text-gray-300 dark:text-gray-200 hover:text-white text-sm flex items-center gap-1 transition-colors duration-300"
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8h-6V2"></path>
                <path d="M6 16h6v6"></path>
                <path d="M3 3l6 6"></path>
                <path d="M21 21l-6-6"></path>
              </svg>
              退出全屏
            </button>
          </div>
        </div>

        <!-- Log Content Area -->
        <div class="bg-[#1a1a1a] dark:bg-gray-900 border border-gray-700 dark:border-gray-600 rounded-b-lg shadow-lg overflow-hidden text-white transition-colors duration-300" :class="{ 'h-full flex flex-col': isFullscreen, 'log-no-wrap': !wrapLines }">
          <div :class="isFullscreen ? 'flex-1 overflow-auto' : 'overflow-x-auto'">
            <div class="log-content font-mono text-xs p-4" :class="{ 'show-errors-only': showErrorsOnly, 'log-wrap': wrapLines }" v-html="logContent"></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<style>
/* Ensure the table takes full width */
.log-content table {
    width: 100%;
}

.log-content .line-number-container {
    width: 1%;
    white-space: nowrap;
}

.log-content.show-errors-only .entry-no-error {
    display: none;
}

.log-no-wrap .log-content {
    white-space: pre;
}

.log-no-wrap .level {
    white-space: pre !important;
}

/* Search highlight */
.search-highlight {
    background-color: rgba(255, 255, 0, 0.5) !important;
    animation: highlightFade 2s forwards;
    transition: background-color 0.3s ease;
}

@keyframes highlightFade {
    from { background-color: rgba(255, 255, 0, 0.5); }
    to { background-color: transparent; }
}

/* Hide lines that don't match search term */
.hidden-search-result {
    display: none !important;
}

/* Error line styling */
.log-content .entry[data-level="error"],
.log-content .entry[data-level="critical"],
.log-content .entry[data-level="emergency"] {
    background-color: rgba(239, 68, 68, 0.2) !important; /* red-500 with opacity */
    transition: background-color 0.3s ease;
}

.log-content .entry[data-level="warning"] {
    background-color: rgba(245, 158, 11, 0.2) !important; /* amber-500 with opacity */
    transition: background-color 0.3s ease;
}

/* Dark mode error line styling */
.dark .log-content .entry[data-level="error"],
.dark .log-content .entry[data-level="critical"],
.dark .log-content .entry[data-level="emergency"] {
    background-color: rgba(239, 68, 68, 0.3) !important; /* red-500 with more opacity in dark mode */
}

.dark .log-content .entry[data-level="warning"] {
    background-color: rgba(245, 158, 11, 0.3) !important; /* amber-500 with more opacity in dark mode */
}

/* Search highlight in dark mode */
.dark .search-highlight {
    background-color: rgba(255, 255, 0, 0.4) !important;
}

/* Wrap/No-wrap styling */
.log-wrap {
    white-space: normal;
}

.log-no-wrap {
    white-space: pre;
}

/* Fullscreen mode */
.fullscreen-log-view {
  overflow: hidden;
}

/* Smooth transitions for log content */
.log-content {
    transition: background-color 0.3s ease, color 0.3s ease;
}
</style>
