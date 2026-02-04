<script setup lang="ts">
import { ref } from 'vue'
import { apiClient } from '@/lib/api'
import { useRouter } from 'vue-router'
import { saveUserLogRecord, getUserLogRecords, deleteUserLogRecord } from '@/lib/localStorage'

const content = ref('')
const loading = ref(false)
const error = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const router = useRouter()
const isDragging = ref(false)
const userLogRecords = ref<any[]>([])
const showHistory = ref(false)

// 加载用户日志记录
const loadUserLogRecords = () => {
  userLogRecords.value = getUserLogRecords()
}

// 显示历史记录
const toggleHistory = () => {
  showHistory.value = !showHistory.value
  if (showHistory.value) {
    loadUserLogRecords()
  }
}

// 删除用户日志记录
const deleteUserLog = (id: string) => {
  deleteUserLogRecord(id)
  loadUserLogRecords()
}

const triggerFileSelect = () => {
  fileInput.value?.click()
}

const onFileSelected = async (event: Event) => {
  const input = event.target as HTMLInputElement
  if (!input.files || input.files.length === 0) return

  const file = input.files[0]
  if (!file) return

  if (file.size > 10 * 1024 * 1024) {
    error.value = "文件过大 (最大 10MB)"
    return
  }

  try {
    const text = await file.text()
    content.value = text
  } catch (e) {
    error.value = "读取文件失败"
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
    error.value = "文件过大 (最大 10MB)"
    return
  }

  try {
    const text = await file.text()
    content.value = text
  } catch (e) {
    error.value = "读取文件失败"
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
      // 保存用户日志记录到本地存储
      saveUserLogRecord(response.data.id, response.data.title || '未命名日志')

      router.push(`/${response.data.id}`)
    } else {
      error.value = response.data.error || '未知错误'
    }
  } catch (e: any) {
    console.error(e)
    error.value = e.response?.data?.error || e.message || '保存失败'
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
        轻松粘贴或上传您的 Minecraft/Hytale 日志以进行分享和分析。
      </p>
    </div>

    <!-- 历史记录面板 -->
    <div v-if="showHistory" class="w-full max-w-4xl mb-6 bg-card dark:bg-gray-800 border dark:border-gray-700 rounded-lg p-5 shadow-sm">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold">我的日志记录</h2>
        <button
          @click="showHistory = false"
          class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
        </button>
      </div>

      <div v-if="userLogRecords.length === 0" class="text-center py-8 text-muted-foreground">
        暂无日志记录
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="record in userLogRecords"
          :key="record.id"
          class="border rounded-lg p-4 hover:bg-secondary/50 transition-colors cursor-pointer group"
          @click="router.push(`/${record.id}`)"
        >
          <div class="flex justify-between items-start">
            <div class="truncate flex-1">
              <h3 class="font-medium truncate">{{ record.title }}</h3>
              <p class="text-xs text-muted-foreground mt-1">{{ record.id }}</p>
            </div>
            <button
              @click.stop="deleteUserLog(record.id)"
              class="opacity-0 group-hover:opacity-100 text-destructive hover:text-destructive/80 transition-opacity"
              title="删除记录"
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18"></path>
                <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path>
                <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
              </svg>
            </button>
          </div>
          <p class="text-xs text-muted-foreground mt-2">
            {{ new Date(record.timestamp).toLocaleString() }}
          </p>
        </div>
      </div>
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
          <span class="text-gray-300 dark:text-gray-200 text-sm ml-2">新建日志</span>
        </div>
        <div class="flex gap-2">
          <input type="file" ref="fileInput" class="hidden" @change="onFileSelected" accept=".log,.txt">
          <button
              @click="triggerFileSelect"
              class="text-gray-300 dark:text-gray-200 hover:text-white text-sm flex items-center gap-1"
          >
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
              </svg>
              选择文件
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
              {{ loading ? '保存中...' : '保存' }}
          </button>

          <!-- 历史记录按钮 -->
          <button
              @click="toggleHistory"
              class="text-gray-300 dark:text-gray-200 hover:text-white text-sm flex items-center gap-1"
          >
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
              </svg>
              历史记录
          </button>
        </div>
      </div>

      <!-- Drop zone indicator -->
      <div v-show="isDragging" class="absolute inset-0 bg-blue-500 bg-opacity-20 border-2 border-dashed border-blue-400 rounded-lg flex items-center justify-center z-10 pointer-events-none">
        <div class="bg-blue-500 text-white px-4 py-2 rounded-lg text-lg font-semibold">
          释放以上传日志文件
        </div>
      </div>

      <!-- Main content area -->
      <div class="bg-[#1a1a1a] dark:bg-gray-900 border border-gray-700 dark:border-gray-600 rounded-b-lg shadow-lg overflow-hidden flex flex-col">
        <div class="relative flex-1">
            <textarea
              v-model="content"
              class="w-full h-[500px] p-4 bg-[#1a1a1a] dark:bg-gray-900 text-gray-100 dark:text-gray-100 font-mono text-sm resize-none focus:outline-none"
              placeholder="[12:00:00] [Server thread/INFO]: Starting minecraft server version 1.20.1..."
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
