import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiClient, getApiUrl } from '@/lib/api'
import { parseLog } from '@/lib/logParser'
import MarkdownIt from 'markdown-it'
// Import only the languages we need to reduce bundle size
import {
  saveAIAnalysisRecord
} from '@/lib/localStorage'
import { setPageTitle } from '@/lib/pageTitle'
import { t } from '@/lib/i18n'

// Initialize markdown parser with syntax highlighting
let md: any = null;

const initializeMarkdownParser = async () => {
  // Dynamically import highlight.js and needed languages only when required
  const hljs = await import('highlight.js');
  // Import only the specific languages we need: PHP, TypeScript, and Shell
  await Promise.all([
    import('highlight.js/lib/languages/php'),
    import('highlight.js/lib/languages/typescript'),
    import('highlight.js/lib/languages/shell'),
  ]).then(langModules => {
    langModules.forEach(langModule => {
      if (langModule.default && langModule.default.name) {
        hljs.default.registerLanguage(langModule.default.name, langModule.default);
      }
    });
  });

  md = new MarkdownIt({
    html: false,
    linkify: true,
    highlight: function (str: string, lang: string): string {
      if (lang && hljs.default.getLanguage(lang)) {
        try {
          return '<pre class="hljs"><code>' +
            hljs.default.highlight(str, { language: lang, ignoreIllegals: true }).value +
            '</code></pre>';
        } catch (__) { }
      }
      return ''; // use external default escaping
    }
  });
};

// Log view composable
export const useLogView = () => {
  const route = useRoute()
  const id = route.params.id as string
  const log = ref<any>(null)
  const logContent = ref('')
  const loading = ref(true)
  const error = ref('')
  const showErrorsOnly = ref(false)
  const wrapLines = ref(false)
  const analyzing = ref(false)
  const aiResult = ref('')
  const searchTerm = ref('')
  const searchIndex = ref(0)
  const searchResults = ref<number[]>([])
  const isFullscreen = ref(false)
  const isCopySuccess = ref(false)
  let cachedAllRecords: any[] | null = null

  const showHistory = ref(false)
  const aiAnalysisHistory = ref<any[]>([])

  // Function to format AI result (since computed properties can't be async)
  const formatAiResult = async (): Promise<string> => {
    if (!aiResult.value) return ''
    if (aiResult.value.startsWith('Error') || aiResult.value.startsWith('Analysis failed')) {
      return `<div class="text-destructive">${aiResult.value}</div>`
    }

    if (aiResult.value.length > 50000) {
      return `<div class="text-destructive">分析结果过长，已截断。请直接查看原始日志。</div>`
    }

    // Initialize markdown parser if not already done
    if (!md) {
      await initializeMarkdownParser();
    }

    try {
      return md.render(aiResult.value)
    } catch (error) {
      console.error('Markdown渲染失败:', error)
      return `<div class="text-destructive">渲染分析结果时发生错误: ${(error as Error).message}</div>`
    }
  }

  // Function to analyze log
  const analyzeLog = async () => {
    analyzing.value = true
    aiResult.value = ''
    try {
      const { data } = await apiClient.get(`/1/ai-analysis/${id}`)
      if (data.success) {
        aiResult.value = data.analysis
        saveAIAnalysisRecord(id, data.analysis)
        cachedAllRecords = null
      } else {
        aiResult.value = t('analysis_failed') + ": " + (data.analysis || t('unknown_error'))
      }
    } catch (e: any) {
      console.error(e)
      const msg = e.response?.data?.analysis || e.response?.data?.error || e.message || t('unknown_error')
      aiResult.value = t('analysis_failed') + ": " + msg
    } finally {
      analyzing.value = false
    }
  }

  // Function to load AI analysis history
  const loadAIAnalysisHistory = () => {
    if (cachedAllRecords === null) {
      try {
        cachedAllRecords = JSON.parse(localStorage.getItem('ai_analysis_history') || '[]')
      } catch (error) {
        console.error('解析AI分析历史记录失败:', error)
        cachedAllRecords = []
      }
    }

    aiAnalysisHistory.value = cachedAllRecords ? cachedAllRecords.filter((record: any) => record.logId === id) : []
  }

  // Function to toggle history
  const toggleHistory = () => {
    showHistory.value = !showHistory.value
    if (showHistory.value) {
      loadAIAnalysisHistory()
    }
  }

  // Function to use historical analysis
  const useHistoricalAnalysis = (analysis: string) => {
    aiResult.value = analysis
    showHistory.value = false
  }

  // Function to load log data
  const loadLogData = async () => {
    try {
      const [rawRes, insightsRes] = await Promise.all([
        apiClient.get(`/1/raw/${id}`),
        apiClient.get(`/1/insights/${id}`)
      ])

      log.value = insightsRes.data
      let rawText = typeof rawRes.data === 'string' ? rawRes.data : JSON.stringify(rawRes.data)

      // Check log size, truncate if too large to prevent performance issues
      if (rawText.length > 1000000) { // Limit to 1MB
        rawText = rawText.substring(0, 1000000) + '\n\n[日志过长，已截断...]'
      }

      // Save original log text for search functionality
      originalLogText.value = rawText

      logContent.value = parseLog(rawText)

      // Update page title
      if (log.value?.title) {
        setPageTitle('log', { title: log.value.title, id: id })
      } else {
        setPageTitle('log', { id: id })
      }
    } catch (e: any) {
      console.error("Failed to load log:", e)
      error.value = e.response?.data?.error || t('log_not_found')
    } finally {
      loading.value = false
    }
  }

  // Function to toggle errors only view
  const toggleErrors = () => {
    showErrorsOnly.value = !showErrorsOnly.value
  }

  // Function to delete log
  const deleteLog = async () => {
    if (!confirm(t('delete_log_confirm'))) {
      return
    }

    try {
      const response = await fetch(`${getApiUrl('1/delete/')}${id}`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json'
        }
      })

      const data = await response.json()

      if (data.success) {
        alert(t('delete_log_success'))
        window.location.href = '/'
      } else {
        alert(t('delete_log_failed') + ': ' + (data.error || t('unknown_error')))
      }
    } catch (e: any) {
      console.error('Failed to delete log:', e)
      alert(t('delete_log_failed') + ': ' + (e.message || t('network_error')))
    }
  }

  // Function to copy share message
  const copyShareMessage = async () => {
    if (!log.value || !log.value.analysis) {
      try {
        const insightsRes = await apiClient.get(`/1/insights/${id}`)
        log.value = insightsRes.data
      } catch (e) {
        console.error('Failed to load analysis for share message:', e)
      }
    }

    let shareMessage = '我遇到了一个问题，'

    if (log.value && log.value.analysis && log.value.analysis.information) {
      const softwareInfo = log.value.analysis.information.find((info: any) =>
        info.label.toLowerCase().includes('software') ||
        info.label.toLowerCase().includes('version') ||
        info.label.toLowerCase().includes('server')
      )

      if (softwareInfo) {
        shareMessage += `是${softwareInfo.label.replace(':', '')} ${softwareInfo.value} `
      }
    }

    shareMessage += '的，链接如下：\n\n'
    shareMessage += window.location.href

    try {
      await navigator.clipboard.writeText(shareMessage)
      isCopySuccess.value = true
      setTimeout(() => {
        isCopySuccess.value = false
      }, 2000)
    } catch (err) {
      console.error('Failed to copy text: ', err)
      const textArea = document.createElement('textarea')
      textArea.value = shareMessage
      document.body.appendChild(textArea)
      textArea.select()
      document.execCommand('copy')
      document.body.removeChild(textArea)
      isCopySuccess.value = true
      setTimeout(() => {
        isCopySuccess.value = false
      }, 2000)
    }
  }

  // Function to download log
  const downloadLog = async () => {
    try {
      const response = await apiClient.get(`/1/raw/${id}`, {
        responseType: 'blob'
      })

      // Create a blob from the response data
      const blob = new Blob([response.data], { type: 'text/plain' })

      // Create a download link
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', `${id}.log`) // Set filename

      // Trigger download
      document.body.appendChild(link)
      link.click()

      // Clean up
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
    } catch (error) {
      console.error('Failed to download log:', error)
      alert('下载日志失败')
    }
  }

  // Function to toggle fullscreen mode
  const toggleFullscreen = () => {
    isFullscreen.value = !isFullscreen.value

    if (isFullscreen.value) {
      document.body.classList.add('fullscreen-log-view')
    } else {
      document.body.classList.remove('fullscreen-log-view')
    }
  }

  const originalLogText = ref('')

  // Function to perform search
  const performSearch = () => {
    if (!searchTerm.value.trim()) {
      logContent.value = parseLog(originalLogText.value)
      searchResults.value = []
      searchIndex.value = 0
      return
    }

    const lines = originalLogText.value.split('\n')
    const results: number[] = []
    const matchingLines: string[] = []

    lines.forEach((line, index) => {
      const lowerLine = line.toLowerCase()
      const searchTerms = searchTerm.value.toLowerCase().split(/\s+/).filter(term => term.length > 0)

      if (searchTerms.length > 0 && searchTerms.every(term => lowerLine.includes(term))) {
        results.push(index)

        let highlightedLine = line
        const sortedTerms = [...searchTerms].sort((a, b) => b.length - a.length)

        sortedTerms.forEach(term => {
          const escapedTerm = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
          const regex = new RegExp(`(${escapedTerm})`, 'gi')
          highlightedLine = highlightedLine.replace(regex, '<mark>$1</mark>')
        })

        matchingLines.push(highlightedLine)
      }
    })

    if (matchingLines.length > 0) {
      const highlightedContent = matchingLines.join('\n')
      logContent.value = parseLog(highlightedContent)
    } else {
      logContent.value = `<div class="text-center p-8 text-gray-500 dark:text-gray-400">${t('no_results')}</div>`
    }

    searchResults.value = results
    searchIndex.value = 0

    if (results.length === 0) {
      alert(t('no_results'))
    }
  }

  // Function to scroll to search result
  const scrollToSearchResult = (_index: number) => {
    const element = document.querySelector('.log-content')
    if (element) {
      element.scrollIntoView({ behavior: 'smooth', block: 'start' })
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

  const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })

  // Function to scroll to footer
  const scrollToFooter = () => {
    const footer = document.querySelector('footer')
    if (footer) {
      footer.scrollIntoView({ behavior: 'smooth' })
    } else {
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' })
    }
  }

  return {
    id,
    log,
    logContent,
    loading,
    error,
    showErrorsOnly,
    wrapLines,
    analyzing,
    aiResult,
    searchTerm,
    searchIndex,
    searchResults,
    isFullscreen,
    isCopySuccess,
    showHistory,
    aiAnalysisHistory,
    formatAiResult,
    analyzeLog,
    loadAIAnalysisHistory,
    toggleHistory,
    useHistoricalAnalysis,
    loadLogData,
    toggleErrors,
    deleteLog,
    copyShareMessage,
    downloadLog,
    toggleFullscreen,
    performSearch,
    scrollToSearchResult,
    goToNextResult,
    goToPrevResult,
    handleSearchInput,
    scrollToTop,
    scrollToFooter
  }
}