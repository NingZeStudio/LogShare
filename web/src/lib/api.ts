import axios from 'axios'

// In production, we point directly to the API domain.
// In development, we use the local proxy (defined in vite.config.ts) which points to /api
// The Vite proxy rewrites /api/xxx to /xxx
const isProd = import.meta.env.PROD
const baseURL = isProd ? 'https://api.mclogs.lemwood.icu' : '/api'

export const apiClient = axios.create({
  baseURL: baseURL
})

export const getApiUrl = (endpoint: string) => {
    // Ensure endpoint doesn't start with / if we are appending to a base that might not have a slash or we want to control the slash
    const cleanEndpoint = endpoint.startsWith('/') ? endpoint.substring(1) : endpoint
    // Handle the case where baseURL might be just '/api' or a full URL
    return `${baseURL}/${cleanEndpoint}`
}
