<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { apiClient } from '@/lib/api'
import { parseLog } from '@/lib/logParser'
import MarkdownIt from 'markdown-it'
import hljs from 'highlight.js'
import 'highlight.js/styles/github-dark.css'
import {
  saveAIAnalysisRecord,
  getAIAnalysisRecords
} from '@/lib/localStorage'
import { setPageTitle } from '@/lib/pageTitle'

const md = new MarkdownIt({
    html: false,
    linkify: true,
    highlight: function (str: string, lang: string): string {
        if (lang && hljs.getLanguage(lang)) {
            try {
                return '<pre class="hljs"><code>' +
                    hljs.highlight(str, { language: lang, ignoreIllegals: true }).value +
                    '</code></pre>';
            } catch (__) { }
        }
        return ''; // use external default escaping
    }
})

const route = useRoute()
const id = route.params.id as string
const log = ref<any>(null)
const logContent = ref('')
const loading = ref(true)
const error = ref('')
const showErrorsOnly = ref(false)
const wrapLines = ref(false) // 默认关闭自动换行
const analyzing = ref(false)
const aiResult = ref('')
const searchTerm = ref('')
const searchIndex = ref(0)
const searchResults = ref<number[]>([])
const isFullscreen = ref(false)
const isCopySuccess = ref(false)
const showHistory = ref(false)
const aiAnalysisHistory = ref<any[]>([])

const formattedAiResult = computed(() => {
    if (!aiResult.value) return ''
    if (aiResult.value.startsWith('Error') || aiResult.value.startsWith('Analysis failed')) {
        // Render errors as plain text (or wrap in a warning block if preferred)
        return `<div class="text-destructive">${aiResult.value}</div>`
    }
    return md.render(aiResult.value)
})

const analyzeLog = async () => {
    analyzing.value = true
    aiResult.value = ''
    try {
        const { data } = await apiClient.get(`/1/ai-analysis/${id}`)
        if (data.success) {
            aiResult.value = data.analysis
            // 保存到本地存储
            saveAIAnalysisRecord(id, data.analysis)
        } else {
            aiResult.value = "Analysis failed: " + (data.analysis || 'Unknown error')
        }
    } catch (e: any) {
        console.error(e)
        const msg = e.response?.data?.analysis || e.response?.data?.error || e.message || "Unknown error";
        aiResult.value = "Error requesting analysis: " + msg
    } finally {
        analyzing.value = false
    }
}

// 加载AI分析历史记录
const loadAIAnalysisHistory = () => {
    aiAnalysisHistory.value = getAIAnalysisRecords(id)
}

// 切换历史记录显示
const toggleHistory = () => {
    showHistory.value = !showHistory.value
    if (showHistory.value) {
        loadAIAnalysisHistory()
    }
}

// 使用历史记录中的分析结果
const useHistoricalAnalysis = (analysis: string) => {
    aiResult.value = analysis
    showHistory.value = false
}

onMounted(async () => {
  try {
    const [rawRes, insightsRes] = await Promise.all([
        apiClient.get(`/1/raw/${id}`),
        apiClient.get(`/1/insights/${id}`)
    ]);

    log.value = insightsRes.data;
    const rawText = typeof rawRes.data === 'string' ? rawRes.data : JSON.stringify(rawRes.data);
    logContent.value = parseLog(rawText);

    // 更新页面标题
    if (log.value?.title) {
      setPageTitle('log', { title: log.value.title, id: id });
    } else {
      setPageTitle('log', { id: id });
    }

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

const deleteLog = async () => {
  if (!confirm('确定要删除这个日志吗？此操作不可撤销。')) {
    return
  }

  try {
    const { data } = await apiClient.delete(`/1/delete/${id}`)
    
    if (data.success) {
      alert('日志已成功删除')
      window.location.href = '/'
    } else {
      alert('删除失败: ' + (data.error || '未知错误'))
    }
  } catch (e: any) {
    console.error('Failed to delete log:', e)
    const msg = e.response?.data?.error || e.message || '网络错误'
    alert('删除失败: ' + msg)
  }
}

const copyShareMessage = async () => {
  if (!log.value || !log.value.analysis) {
    // If analysis isn't loaded yet, get it first
    try {
      const insightsRes = await apiClient.get(`/1/insights/${id}`);
      log.value = insightsRes.data;
    } catch (e) {
      console.error('Failed to load analysis for share message:', e);
    }
  }

  // Construct the share message
  let shareMessage = '我遇到了一个问题，';

  if (log.value && log.value.analysis && log.value.analysis.information) {
    // Find server software and version information
    const softwareInfo = log.value.analysis.information.find((info: any) =>
      info.label.toLowerCase().includes('software') ||
      info.label.toLowerCase().includes('version') ||
      info.label.toLowerCase().includes('server')
    );

    if (softwareInfo) {
      shareMessage += `是${softwareInfo.label.replace(':', '')} ${softwareInfo.value} `;
    }
  }

  shareMessage += '的，链接如下：\n\n';
  shareMessage += window.location.href;

  try {
    await navigator.clipboard.writeText(shareMessage);
    isCopySuccess.value = true;
    setTimeout(() => {
      isCopySuccess.value = false;
    }, 2000);
  } catch (err) {
    console.error('Failed to copy text: ', err);
    // Fallback: create a temporary textarea to copy
    const textArea = document.createElement('textarea');
    textArea.value = shareMessage;
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand('copy');
    document.body.removeChild(textArea);
    isCopySuccess.value = true;
    setTimeout(() => {
      isCopySuccess.value = false;
    }, 2000);
  }
}

// Download log functionality
const downloadLog = async () => {
  try {
    const response = await apiClient.get(`/1/raw/${id}`, {
      responseType: 'blob' // Important: specify blob response type
    });

    // Create a blob from the response data
    const blob = new Blob([response.data], { type: 'text/plain' });

    // Create a download link
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `${id}.log`); // Set filename

    // Trigger download
    document.body.appendChild(link);
    link.click();

    // Clean up
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  } catch (error) {
    console.error('Failed to download log:', error);
    alert('下载日志失败');
  }
}

// Toggle fullscreen mode for log viewer
const toggleFullscreen = () => {
  isFullscreen.value = !isFullscreen.value;

  if (isFullscreen.value) {
    document.body.classList.add('fullscreen-log-view');
  } else {
    document.body.classList.remove('fullscreen-log-view');
  }
}

// Search functions
const performSearch = () => {
  if (!searchTerm.value.trim()) {
    // Clear search highlights and show all lines
    const logElement = document.querySelector('.log-content')
    if (logElement) {
      const allLines = logElement.querySelectorAll('.log-line')
      allLines.forEach(line => {
        line.classList.remove('hidden-search-result')
        line.classList.remove('search-highlight')
      })
    }
    searchResults.value = []
    searchIndex.value = 0
    return
  }

  // Get all lines from the log content
  const logElement = document.querySelector('.log-content')
  if (!logElement) return

  // Find all elements that contain the search term
  const allLines = logElement.querySelectorAll('.log-line')
  const results: number[] = []

  allLines.forEach((line, index) => {
    if (line.textContent && line.textContent.toLowerCase().includes(searchTerm.value.toLowerCase())) {
      results.push(index)
      line.classList.remove('hidden-search-result')
    } else {
      line.classList.add('hidden-search-result')
    }
  })

  searchResults.value = results
  searchIndex.value = 0

  if (results.length > 0 && results[0] !== undefined) {
    scrollToSearchResult(results[0])
  } else {
    alert('未找到匹配项')
  }
}

const scrollToSearchResult = (index: number) => {
  const logElement = document.querySelector('.log-content')
  if (!logElement) return

  const lines = logElement.querySelectorAll('.log-line')
  if (lines[index]) {
    // Remove highlight from all lines
    lines.forEach(line => line.classList.remove('search-highlight'))

    // Add highlight to current result
    lines[index].classList.add('search-highlight')

    lines[index].scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
}

const goToNextResult = () => {
  if (searchResults.value.length === 0) return

  searchIndex.value = (searchIndex.value + 1) % searchResults.value.length
  const index = searchResults.value[searchIndex.value]
  if (index !== undefined) {
    scrollToSearchResult(index)
  }
}

const goToPrevResult = () => {
  if (searchResults.value.length === 0) return

  const len = searchResults.value.length
  searchIndex.value = (searchIndex.value - 1 + len) % searchResults.value.length
  const index = searchResults.value[searchIndex.value]
  if (index !== undefined) {
    scrollToSearchResult(index)
  }
}

const handleSearchInput = (event: KeyboardEvent) => {
  if (event.key === 'Enter') {
    performSearch()
  }
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

  <div v-else :class="isFullscreen ? 'fixed inset-0 z-50 bg-background' : 'container mx-auto px-4 py-6'">
    <div :class="isFullscreen ? 'h-full flex flex-col' : 'flex flex-col lg:flex-row gap-6'">

      <!-- Sidebar / Info (Hidden in fullscreen mode) -->
      <div v-if="!isFullscreen" class="w-full lg:w-1/3 space-y-6">
        <div class="bg-card dark:bg-gray-800 border dark:border-gray-700 rounded-lg p-5 shadow-sm text-card-foreground dark:text-gray-100 transition-colors duration-300">
           <h1 class="text-xl font-bold break-all transition-colors duration-300">{{ log.title }}</h1>
           <div class="text-xs text-muted-foreground dark:text-gray-400 mt-1 transition-colors duration-300">类型: {{ log.id }}</div>

           <!-- Hint for users who don't understand the page -->
           <div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800/30 rounded-md transition-colors duration-300">
               <p class="text-sm text-blue-800 dark:text-blue-200 transition-colors duration-300">
                   <strong>提示：</strong>如果你看不懂本页面的信息，请点击"分享链接"按钮将本页面分享给别人
               </p>
           </div>

           <!-- Action buttons -->
           <div class="grid grid-cols-2 gap-2 mt-4">
             <button @click="downloadLog" class="text-sm bg-secondary hover:bg-secondary/80 text-secondary-foreground px-3 py-2 rounded text-center flex items-center justify-center gap-2 transition-colors duration-300">
               <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                 <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                 <polyline points="7 10 12 15 17 10"></polyline>
                 <line x1="12" y1="15" x2="12" y2="3"></line>
               </svg>
               下载日志
             </button>

             <button @click="toggleErrors" class="text-sm px-3 py-2 rounded transition-colors text-center transition-colors duration-300" :class="showErrorsOnly ? 'bg-destructive text-destructive-foreground' : 'bg-secondary hover:bg-secondary/80 text-secondary-foreground'">
                 {{ showErrorsOnly ? '显示全部' : '只看错误' }}
             </button>

             <button @click="scrollToTop" class="text-sm bg-secondary hover:bg-secondary/80 text-secondary-foreground px-3 py-2 rounded flex items-center justify-center gap-1 transition-colors duration-300">
                 <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                   <line x1="12" y1="19" x2="12" y2="5"></line>
                   <polyline points="5 12 12 5 19 12"></polyline>
                 </svg>
                 顶部
             </button>
             <button @click="scrollToBottom" class="text-sm bg-secondary hover:bg-secondary/80 text-secondary-foreground px-3 py-2 rounded flex items-center justify-center gap-1 transition-colors duration-300">
                 <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                   <line x1="12" y1="5" x2="12" y2="19"></line>
                   <polyline points="19 12 12 19 5 12"></polyline>
                 </svg>
                 底部
             </button>
           </div>

           <!-- More action buttons -->
           <div class="grid grid-cols-2 gap-2 mt-3">
             <button @click="deleteLog" class="text-sm bg-destructive hover:bg-destructive/90 text-destructive-foreground px-3 py-2 rounded flex items-center justify-center gap-2 transition-colors duration-300">
                 <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                   <path d="M3 6h18"></path>
                   <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path>
                   <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                 </svg>
                 删除日志
             </button>

             <button @click="copyShareMessage" class="text-sm bg-primary hover:bg-primary/90 text-primary-foreground px-3 py-2 rounded flex items-center justify-center gap-2 transition-colors duration-300" :class="isCopySuccess ? 'bg-green-600 hover:bg-green-700' : ''">
                 <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                   <circle cx="18" cy="5" r="3"></circle>
                   <circle cx="6" cy="12" r="3"></circle>
                   <circle cx="18" cy="19" r="3"></circle>
                   <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                   <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                 </svg>
                 <span>{{ isCopySuccess ? '✓ 已复制!' : '分享链接' }}</span>
             </button>
           </div>

           <!-- Fullscreen toggle button -->
           <div class="mt-3">
             <button @click="toggleFullscreen" class="w-full text-sm bg-secondary hover:bg-secondary/80 text-secondary-foreground px-3 py-2 rounded flex items-center justify-center gap-2 transition-colors duration-300">
                 <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                   <path d="M8 3H5a2 2 0 0 0-2 2v3"></path>
                   <path d="M21 8V5a2 2 0 0 0-2-2h-3"></path>
                   <path d="M3 16v3a2 2 0 0 0 2 2h3"></path>
                   <path d="M16 21h3a2 2 0 0 0 2-2v-3"></path>
                 </svg>
                 {{ isFullscreen ? '退出全屏' : '全屏模式' }}
             </button>
           </div>

           <!-- Options -->
           <div class="mt-4 flex items-center gap-2 pt-3 border-t dark:border-gray-700 transition-colors duration-300">
               <label class="text-sm text-muted-foreground dark:text-gray-400 select-none cursor-pointer transition-colors duration-300">自动换行</label>
               <button
                 @click="wrapLines = !wrapLines"
                 :class="wrapLines ? 'bg-primary' : 'bg-gray-600'"
                 class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none"
               >
                 <span
                   :class="wrapLines ? 'translate-x-5' : 'translate-x-1'"
                   class="inline-block h-3 w-3 transform rounded-full bg-white transition-transform"
                 />
               </button>
           </div>

        </div>

        <div v-if="log.analysis && log.analysis.problems && log.analysis.problems.length > 0" class="bg-destructive/10 dark:bg-red-900/20 border border-destructive/20 dark:border-red-800/30 rounded-lg p-5 transition-colors duration-300">
            <h3 class="font-bold text-destructive dark:text-red-400 mb-3 flex items-center gap-2 transition-colors duration-300">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
              </svg>
              检测到的问题
            </h3>
            <div class="space-y-4">
                <div v-for="(prob, idx) in log.analysis.problems" :key="idx" class="text-sm p-3 bg-destructive/5 dark:bg-red-900/10 rounded border border-destructive/10 dark:border-red-800/20 transition-colors duration-300">
                    <div class="font-medium flex items-start gap-2">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-destructive dark:text-red-400 flex-shrink-0 mt-0.5 transition-colors duration-300">
                        <polygon points="7.86 2 16.14 2 21.48 10.66 16.14 19.32 7.86 19.32 2.52 10.66 7.86 2"></polygon>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                      </svg>
                      <span>{{ prob.message }}</span>
                      <span v-if="prob.line" class="text-xs text-muted-foreground dark:text-gray-400 ml-1 transition-colors duration-300">(行 {{ prob.line }})</span>
                    </div>
                    <div v-if="prob.solutions && prob.solutions.length" class="mt-2 pl-5 border-l-2 border-destructive/30 dark:border-red-700/40 space-y-1 transition-colors duration-300">
                        <div v-for="sol in prob.solutions" :key="sol.message" class="text-muted-foreground dark:text-gray-300 text-sm flex items-start gap-2 transition-colors duration-300">
                          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-green-500 dark:text-green-400 flex-shrink-0 mt-0.5 transition-colors duration-300">
                            <polyline points="20 6 9 17 4 12"></polyline>
                          </svg>
                          <span>{{ sol.message }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Analysis -->
        <div class="relative bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-900 border dark:border-gray-700 rounded-lg p-5 shadow-sm text-card-foreground overflow-hidden transition-colors duration-300">
            <!-- Background icon in top-right corner -->
            <div class="absolute top-4 right-4 opacity-10 transition-opacity duration-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M3.81 6.46a10 10 0 1 0 17.08 5.07"></path>
                  <path d="M12 18v-8"></path>
                  <path d="M8 8l4-4 4 4"></path>
                </svg>
            </div>
            <h3 class="font-bold mb-3 flex items-center gap-2 relative z-10 transition-colors duration-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M3.81 6.46a10 10 0 1 0 17.08 5.07"></path>
                  <path d="M12 18v-8"></path>
                  <path d="M8 8l4-4 4 4"></path>
                </svg>
                <span>大模型智能分析</span>
                <button
                  @click="toggleHistory"
                  class="ml-auto text-xs bg-secondary hover:bg-secondary/80 text-secondary-foreground px-2 py-1 rounded transition-colors duration-300"
                  title="查看历史分析记录"
                >
                  历史
                </button>
            </h3>

            <!-- 历史记录面板 -->
            <div v-if="showHistory" class="mb-4 bg-secondary/30 dark:bg-gray-700/30 p-3 rounded-lg border border-secondary/50 dark:border-gray-600 relative z-10">
              <div class="flex justify-between items-center mb-2">
                <h4 class="font-medium text-sm">历史分析记录</h4>
                <button
                  @click="showHistory = false"
                  class="text-xs text-muted-foreground hover:text-foreground"
                >
                  关闭
                </button>
              </div>

              <div v-if="aiAnalysisHistory.length === 0" class="text-sm text-muted-foreground italic py-2">
                暂无历史记录
              </div>

              <div v-else class="space-y-2 max-h-40 overflow-y-auto">
                <div
                  v-for="(record, index) in aiAnalysisHistory"
                  :key="index"
                  class="p-2 bg-white dark:bg-gray-800 rounded border border-border text-sm cursor-pointer hover:bg-secondary/50 dark:hover:bg-gray-700/50 transition-colors"
                  @click="useHistoricalAnalysis(record.analysis)"
                >
                  <div class="flex justify-between">
                    <span>{{ new Date(record.timestamp).toLocaleString() }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="!aiResult && !analyzing" class="relative z-10">
                <button @click="analyzeLog" class="w-full bg-[#3b82f6] text-white hover:bg-[#2563eb] px-4 py-3 rounded font-medium transition-colors duration-300">
                    开始智能分析
                </button>
                <p class="text-xs text-muted-foreground mt-3 text-center transition-colors duration-300">内容由AI生成，本站不对AI生成的内容负责</p>
            </div>
            <div v-else-if="analyzing" class="text-center py-6 relative z-10">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[#3b82f6] mx-auto"></div>
                <p class="text-sm text-muted-foreground mt-3 transition-colors duration-300">正在分析日志...</p>
            </div>
            <div v-else class="text-sm bg-secondary/50 dark:bg-gray-800 p-4 rounded-lg border dark:border-gray-700 overflow-x-auto max-h-64 overflow-y-auto relative z-10 transition-colors duration-300">
                <div class="prose prose-sm dark:prose-invert max-w-none break-words transition-colors duration-300" v-html="formattedAiResult"></div>
            </div>
        </div>

        <div v-if="log.analysis && log.analysis.information && log.analysis.information.length > 0" class="bg-card dark:bg-gray-800 border dark:border-gray-700 rounded-lg p-5 shadow-sm text-card-foreground dark:text-gray-100 transition-colors duration-300">
            <h3 class="font-bold mb-3 flex items-center gap-2 transition-colors duration-300">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="20" height="14" x="2" y="3" rx="2"></rect>
                <path d="M8 21h8"></path>
                <path d="M12 17v4"></path>
              </svg>
              服务器信息
            </h3>
            <div class="space-y-3">
                <div v-for="info in log.analysis.information" :key="info.label" class="flex justify-between text-sm py-2 border-b dark:border-gray-700/30 last:border-0 transition-colors duration-300">
                    <span class="text-muted-foreground dark:text-gray-400 transition-colors duration-300">{{ info.label }}</span>
                    <span class="font-medium text-right break-all max-w-[50%] dark:text-gray-200 transition-colors duration-300">{{ info.value }}</span>
                </div>
            </div>
        </div>
      </div>

      <!-- Log Content -->
      <div :class="isFullscreen ? 'flex-1' : 'w-full lg:w-2/3'">
        <!-- Mac-style window header -->
        <div class="bg-gray-800 dark:bg-gray-700 rounded-t-lg px-4 py-2 flex items-center justify-between border-b border-gray-700 dark:border-gray-600 transition-colors duration-300">
          <div class="flex items-center gap-2">
            <div class="flex gap-1.5">
              <div class="w-3 h-3 rounded-full bg-red-500"></div>
              <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
              <div class="w-3 h-3 rounded-full bg-green-500"></div>
            </div>
            <span class="text-gray-300 dark:text-gray-200 text-sm ml-2 transition-colors duration-300">{{ id }}.log</span>
          </div>
          <div class="flex items-center gap-2">
            <!-- Search functionality in header -->
            <div class="relative flex items-center">
              <input
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