import { ref } from 'vue'

// Tab management for API documentation examples
export const useApiDocTabs = () => {
  const activeTab = ref('js')

  const setTab = (tab: string) => {
    activeTab.value = tab
  }

  return {
    activeTab,
    setTab
  }
}

// API endpoints documentation
export const apiEndpoints = [
  {
    id: 'log',
    title: 'paste_log',
    method: 'POST',
    url: 'https://api.mclogs.lemwood.icu/1/log',
    description: 'home_subtitle',
    params: [
      {
        field: 'content',
        type: 'string',
        description: '原始日志文件内容字符串。最大长度为10MiB和25k行，必要时将被截断。'
      }
    ],
    successResponse: {
      success: true,
      id: "8FlTowW",
      url: "https://mclogs.lemwood.icu/8FlTowW",
      raw: "https://api.mclogs.lemwood.icu/1/raw/8FlTowW"
    },
    errorResponse: {
      success: false,
      error: "必需的 POST 参数 'content' 为空。"
    }
  },
  {
    id: 'analyse',
    title: 'instant_analysis',
    method: 'POST',
    url: 'https://api.mclogs.lemwood.icu/1/analyse',
    description: 'instant_analysis_desc',
    params: []
  },
  {
    id: 'insights',
    title: 'get_insights',
    method: 'GET',
    url: 'https://api.mclogs.lemwood.icu/1/insights/[id]',
    description: 'get_insights_desc'
  },
  {
    id: 'raw',
    title: 'get_raw_log',
    method: 'GET',
    url: 'https://api.mclogs.lemwood.icu/1/raw/[id]',
    description: 'get_raw_log_desc'
  },
  {
    id: 'ai-analysis',
    title: 'ai_analysis',
    method: 'GET',
    url: 'https://api.mclogs.lemwood.icu/1/ai-analysis/[id]',
    description: 'ai_analysis_desc'
  },
  {
    id: 'limits',
    title: 'get_limits',
    method: 'GET',
    url: 'https://api.mclogs.lemwood.icu/1/limits',
    description: 'get_limits_desc'
  },
  {
    id: 'delete',
    title: '删除日志文件',
    method: 'DELETE',
    url: 'https://api.mclogs.lemwood.icu/1/delete/[id]',
    description: '删除指定 ID 的日志文件。此操作不可逆，请谨慎使用。',
    params: [
      {
        field: 'id',
        type: 'string',
        description: '要删除的日志文件的唯一标识符'
      }
    ]
  },
  {
    id: 'rate-error',
    title: '速率限制错误信息',
    method: 'GET',
    url: 'https://api.mclogs.lemwood.icu/1/errors/rate',
    description: '返回标准的 429 Too Many Requests 错误响应。这主要用于测试或前端显示标准错误消息。'
  }
]

// API example code snippets
export const apiCodeExamples = {
  js: {
    log: `const content = "Your log content here...";
const response = await fetch('https://api.mclogs.lemwood.icu/1/log', {
    method: 'POST',
    body: new URLSearchParams({ content })
});
const data = await response.json();
console.log(data);`,
    insights: `const response = await fetch('https://api.mclogs.lemwood.icu/1/insights/8FlTowW');
const data = await response.json();
console.log(data);`,
    raw: `const response = await fetch('https://api.mclogs.lemwood.icu/1/raw/8FlTowW');
const text = await response.text();
console.log(text);`,
    'ai-analysis': `const response = await fetch('https://api.mclogs.lemwood.icu/1/ai-analysis/8FlTowW');
const data = await response.json();
console.log(data);`,
    limits: `const response = await fetch('https://api.mclogs.lemwood.icu/1/limits');
const data = await response.json();
console.log(data);`,
    delete: `const logId = "8FlTowW";
const response = await fetch(\`https://api.mclogs.lemwood.icu/1/delete/\${logId}\`, {
    method: 'DELETE',
    headers: {
        'Content-Type': 'application/json'
    }
});
const data = await response.json();
console.log(data);`,
    'rate-error': `const response = await fetch('https://api.mclogs.lemwood.icu/1/errors/rate');
const data = await response.json();
console.log(data);`
  },
  php: {
    log: `<?php
\$content = "Your log content here...";
\$ch = curl_init('https://api.mclogs.lemwood.icu/1/log');
curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt(\$ch, CURLOPT_POSTFIELDS, http_build_query(['content' => \$content]));
\$response = curl_exec(\$ch);
\$data = json_decode(\$response, true);
curl_close(\$ch);
print_r(\$data);`,
    insights: `<?php
\$data = json_decode(file_get_contents('https://api.mclogs.lemwood.icu/1/insights/8FlTowW'), true);
print_r(\$data);`,
    raw: `<?php
echo file_get_contents('https://api.mclogs.lemwood.icu/1/raw/8FlTowW');`,
    'ai-analysis': `<?php
\$data = json_decode(file_get_contents('https://api.mclogs.lemwood.icu/1/ai-analysis/8FlTowW'), true);
print_r(\$data);`,
    limits: `<?php
\$data = json_decode(file_get_contents('https://api.mclogs.lemwood.icu/1/limits'), true);
print_r(\$data);`,
    delete: `<?php
\$logId = "8FlTowW";
\$ch = curl_init("https://api.mclogs.lemwood.icu/1/delete/\$logId");
curl_setopt(\$ch, CURLOPT_CUSTOMREQUEST, "DELETE");
curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt(\$ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json"
]);
\$response = curl_exec(\$ch);
\$data = json_decode(\$response, true);
curl_close(\$ch);
print_r(\$data);`,
    'rate-error': `<?php
\$data = json_decode(file_get_contents('https://api.mclogs.lemwood.icu/1/errors/rate'), true);
print_r(\$data);`
  },
  curl: {
    log: `curl -X POST --data-urlencode 'content@path/to/latest.log' 'https://api.mclogs.lemwood.icu/1/log'`,
    insights: `curl https://api.mclogs.lemwood.icu/1/insights/8FlTowW`,
    raw: `curl https://api.mclogs.lemwood.icu/1/raw/8FlTowW`,
    'ai-analysis': `curl https://api.mclogs.lemwood.icu/1/ai-analysis/8FlTowW`,
    limits: `curl https://api.mclogs.lemwood.icu/1/limits`,
    delete: `curl -X DELETE -H "Content-Type: application/json" 'https://api.mclogs.lemwood.icu/1/delete/8FlTowW'`,
    'rate-error': `curl https://api.mclogs.lemwood.icu/1/errors/rate`
  }
}