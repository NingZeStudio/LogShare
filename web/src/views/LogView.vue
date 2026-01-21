<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiClient, getApiUrl } from '@/lib/api'
import { parseLog } from '@/lib/logParser'

const route = useRoute()
const id = route.params.id as string
const log = ref<any>(null)
const logContent = ref('')
const loading = ref(true)
const error = ref('')
const showErrorsOnly = ref(false)
const wrapLines = ref(true)

onMounted(async () => {
  try {
    const [rawRes, insightsRes] = await Promise.all([
        apiClient.get(`/1/raw/${id}`),
        apiClient.get(`/1/insights/${id}`)
    ]);
    
    log.value = insightsRes.data;
    const rawText = typeof rawRes.data === 'string' ? rawRes.data : JSON.stringify(rawRes.data);
    logContent.value = parseLog(rawText);

  } catch (e: any) {
    console.error("Failed to load log:", e)
    error.value = e.response?.data?.error || '日志未找到或网络错误'
  } finally {
    loading.value = false
  }
})

const toggleErrors = () => {
  showErrorsOnly.value = !showErrorsOnly.value
}

const scrollToBottom = () => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' })
const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })
</script>

<template>
  <div v-if="loading" class="container mx-auto px-4 py-12 text-center">
    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
    <p class="mt-4 text-muted-foreground">正在加载日志...</p>
  </div>
  
  <div v-else-if="error" class="container mx-auto px-4 py-12 text-center">
    <h2 class="text-2xl font-bold text-destructive">错误</h2>
    <p class="text-muted-foreground">{{ error }}</p>
  </div>

  <div v-else class="container mx-auto px-4 py-6">
    <div class="flex flex-col lg:flex-row gap-6">
      
      <!-- Sidebar / Info -->
      <div class="w-full lg:w-1/3 space-y-6">
        <div class="bg-card border rounded-lg p-4 shadow-sm text-card-foreground">
           <h1 class="text-xl font-bold break-all">{{ log.title }}</h1>
           <div class="text-xs text-muted-foreground mt-1">Type: {{ log.id }}</div>
           <div class="flex gap-2 mt-4 flex-wrap">
             <a :href="getApiUrl(`1/raw/${id}`)" target="_blank" class="text-sm bg-secondary hover:bg-secondary/80 text-secondary-foreground px-3 py-1 rounded">原始文件</a>
             
             <button @click="toggleErrors" class="text-sm px-3 py-1 rounded transition-colors" :class="showErrorsOnly ? 'bg-destructive text-destructive-foreground' : 'bg-secondary hover:bg-secondary/80 text-secondary-foreground'">
                 {{ showErrorsOnly ? '显示全部' : '只看错误' }}
             </button>

             <button @click="scrollToBottom" class="text-sm bg-secondary hover:bg-secondary/80 text-secondary-foreground px-3 py-1 rounded">
                 ↓ 底部
             </button>
             <button @click="scrollToTop" class="text-sm bg-secondary hover:bg-secondary/80 text-secondary-foreground px-3 py-1 rounded">
                 ↑ 顶部
             </button>
           </div>
           
           <div class="mt-4 flex items-center gap-2">
               <input type="checkbox" id="wrap-checkbox" v-model="wrapLines" class="accent-primary">
               <label for="wrap-checkbox" class="text-sm text-muted-foreground select-none cursor-pointer">自动换行</label>
           </div>
        </div>

        <div v-if="log.analysis && log.analysis.problems && log.analysis.problems.length > 0" class="bg-destructive/10 border border-destructive/20 rounded-lg p-4">
            <h3 class="font-bold text-destructive mb-3">检测到的问题</h3>
            <div class="space-y-4">
                <div v-for="(prob, idx) in log.analysis.problems" :key="idx" class="text-sm">
                    <div class="font-medium">
                        {{ prob.message }}
                        <span v-if="prob.line" class="text-xs text-muted-foreground ml-1">(行 {{ prob.line }})</span>
                    </div>
                    <div v-if="prob.solutions && prob.solutions.length" class="mt-1 pl-2 border-l-2 border-destructive/30">
                        <div v-for="sol in prob.solutions" :key="sol.message" class="text-muted-foreground text-xs">
                             💡 {{ sol.message }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="log.analysis && log.analysis.information && log.analysis.information.length > 0" class="bg-card border rounded-lg p-4 shadow-sm text-card-foreground">
            <h3 class="font-bold mb-3">服务器信息</h3>
            <div class="space-y-2 text-sm">
                <div v-for="info in log.analysis.information" :key="info.label" class="flex justify-between">
                    <span class="text-muted-foreground">{{ info.label }}</span>
                    <span class="font-medium">{{ info.value }}</span>
                </div>
            </div>
        </div>
      </div>

      <!-- Log Content -->
      <div class="w-full lg:w-2/3 bg-[#2d3943] border rounded-lg shadow-sm overflow-hidden text-white" :class="{ 'log-no-wrap': !wrapLines }">
        <div class="overflow-x-auto p-0">
             <div class="log-content font-mono text-xs" :class="{ 'show-errors-only': showErrorsOnly }" v-html="logContent"></div>
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
</style>