// Shared utilities for both ApiDocsView and LogView components

// Common constants
export const API_BASE_URL = 'https://api.mclogs.lemwood.icu'
export const FRONTEND_BASE_URL = 'https://mclogs.lemwood.icu'

// Common API endpoints
export const API_ENDPOINTS = {
  LOG: '/1/log',
  ANALYSE: '/1/analyse',
  INSIGHTS: '/1/insights/',
  RAW: '/1/raw/',
  AI_ANALYSIS: '/1/ai-analysis/',
  LIMITS: '/1/limits',
  DELETE: '/1/delete/',
  RATE_ERROR: '/1/errors/rate'
}

// Common HTTP methods
export const HTTP_METHODS = {
  GET: 'GET',
  POST: 'POST',
  PUT: 'PUT',
  DELETE: 'DELETE',
  PATCH: 'PATCH'
}

// Common response types
export interface ApiResponse {
  success: boolean
  error?: string
}

// Common log levels
export const LOG_LEVELS = {
  ERROR: 'error',
  WARNING: 'warning',
  INFO: 'info',
  DEBUG: 'debug',
  CRITICAL: 'critical',
  EMERGENCY: 'emergency'
}

// Utility function to get API URL
export const getApiUrl = (endpoint: string): string => {
  return `${API_BASE_URL}${endpoint}`
}

// Utility function to get frontend URL
export const getFrontendUrl = (path: string): string => {
  return `${FRONTEND_BASE_URL}${path}`
}

// Utility function to validate log ID format
export const isValidLogId = (id: string): boolean => {
  // Assuming log IDs are alphanumeric with certain length
  const logIdRegex = /^[a-zA-Z0-9-_]+$/;
  return logIdRegex.test(id) && id.length >= 3 && id.length <= 50
}

// Utility function to format bytes to human readable format
export const formatBytes = (bytes: number, decimals: number = 2): string => {
  if (bytes === 0) return '0 Bytes'

  const k = 1024
  const dm = decimals < 0 ? 0 : decimals
  const sizes = ['Bytes', 'KB', 'MB', 'GB']

  const i = Math.floor(Math.log(bytes) / Math.log(k))

  return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i]
}

// Utility function to truncate text
export const truncateText = (text: string, maxLength: number, suffix: string = '...'): string => {
  if (text.length <= maxLength) return text
  return text.substring(0, maxLength) + suffix
}

// Utility function to debounce function calls
export const debounce = <T extends (...args: any[]) => any>(
  func: T,
  delay: number
): ((...args: Parameters<T>) => void) => {
  let timeoutId: ReturnType<typeof setTimeout> | null = null
  
  return (...args: Parameters<T>): void => {
    if (timeoutId) clearTimeout(timeoutId)
    timeoutId = setTimeout(() => func(...args), delay)
  }
}

// Utility function to throttle function calls
export const throttle = <T extends (...args: any[]) => any>(
  func: T,
  limit: number
): ((...args: Parameters<T>) => void) => {
  let inThrottle: boolean
  
  return (...args: Parameters<T>): void => {
    if (!inThrottle) {
      func(...args)
      inThrottle = true
      setTimeout(() => inThrottle = false, limit)
    }
  }
}

// Utility function to copy text to clipboard
export const copyToClipboard = async (text: string): Promise<boolean> => {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      return true
    } else {
      // Fallback for older browsers or insecure contexts
      const textArea = document.createElement('textarea')
      textArea.value = text
      document.body.appendChild(textArea)
      textArea.focus()
      textArea.select()
      
      const successful = document.execCommand('copy')
      document.body.removeChild(textArea)
      return successful
    }
  } catch (error) {
    console.error('Failed to copy text to clipboard:', error)
    return false
  }
}

// Utility function to download content as file
export const downloadFile = (content: string, filename: string, contentType: string = 'text/plain'): void => {
  const blob = new Blob([content], { type: contentType })
  const url = URL.createObjectURL(blob)
  
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.style.display = 'none'
  
  document.body.appendChild(link)
  link.click()
  
  // Clean up
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

// Utility function to detect if running in a mobile environment
export const isMobile = (): boolean => {
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
}

// Utility function to get current timestamp
export const getCurrentTimestamp = (): number => {
  return Math.floor(Date.now() / 1000)
}

// Utility function to format timestamp to readable date
export const formatDate = (timestamp: number): string => {
  const date = new Date(timestamp * 1000)
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString()
}