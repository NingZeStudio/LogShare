<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@/lib/i18n'

const activeTab = ref('js')

const setTab = (tab: string) => {
    activeTab.value = tab
}
</script>

<template>
    <div class="container mx-auto px-4 py-12 max-w-4xl">
        <h1 class="text-3xl font-bold mb-6">{{ t('api_docs') }}</h1>
        <p class="mb-8 text-muted-foreground">
            {{ t('home_subtitle') }} <strong>NingZeLogs</strong> {{ t('integration_text') }}
        </p>

        <div class="space-y-16">
            <!-- Paste Log -->
            <section class="space-y-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold">{{ t('paste_log') }}</h2>
                    <span class="bg-blue-600 text-white px-2 py-1 rounded text-xs font-bold">POST</span>
                </div>
                
                <div class="font-mono bg-muted p-3 rounded-md overflow-x-auto text-sm border border-border">
                    https://api.mclogs.lemwood.icu/1/log
                </div>

                <div class="space-y-4">
                    <h3 class="text-lg font-medium">请求参数</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border-collapse border border-border">
                            <thead class="bg-muted">
                                <tr>
                                    <th class="border border-border p-2 text-left">字段</th>
                                    <th class="border border-border p-2 text-left">类型</th>
                                    <th class="border border-border p-2 text-left">描述</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="border border-border p-2 font-mono text-primary">content</td>
                                    <td class="border border-border p-2">string</td>
                                    <td class="border border-border p-2">原始日志文件内容字符串。最大长度为10MiB和25k行，必要时将被截断。</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">调用示例</h3>
                        <div class="flex bg-muted rounded-md p-1 border border-border">
                            <button 
                                @click="setTab('js')" 
                                :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'js' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']"
                            >JavaScript</button>
                            <button 
                                @click="setTab('php')" 
                                :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'php' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']"
                            >PHP</button>
                            <button 
                                @click="setTab('curl')" 
                                :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'curl' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']"
                            >cURL</button>
                        </div>
                    </div>

                    <!-- JS Example -->
                    <div v-show="activeTab === 'js'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">const</span> content = <span class="text-green-400">"Your log content here..."</span>;
<span class="text-cyan-400">const</span> response = <span class="text-cyan-400">await</span> <span class="text-yellow-400">fetch</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/log'</span>, {
    method: <span class="text-green-400">'POST'</span>,
    body: <span class="text-cyan-400">new</span> <span class="text-yellow-400">URLSearchParams</span>({ content })
});
<span class="text-cyan-400">const</span> data = <span class="text-cyan-400">await</span> response.<span class="text-yellow-400">json</span>();
<span class="text-cyan-400">console</span>.<span class="text-yellow-400">log</span>(data);</pre>
                    </div>

                    <!-- PHP Example -->
                    <div v-show="activeTab === 'php'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">&lt;?php</span>
<span class="text-pink-400">$content</span> = <span class="text-green-400">"Your log content here..."</span>;
<span class="text-pink-400">$ch</span> = <span class="text-yellow-400">curl_init</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/log'</span>);
<span class="text-yellow-400">curl_setopt</span>(<span class="text-pink-400">$ch</span>, CURLOPT_RETURNTRANSFER, <span class="text-cyan-400">true</span>);
<span class="text-yellow-400">curl_setopt</span>(<span class="text-pink-400">$ch</span>, CURLOPT_POSTFIELDS, <span class="text-yellow-400">http_build_query</span>([<span class="text-green-400">'content'</span> => <span class="text-pink-400">$content</span>]));
<span class="text-pink-400">$response</span> = <span class="text-yellow-400">curl_exec</span>(<span class="text-pink-400">$ch</span>);
<span class="text-pink-400">$data</span> = <span class="text-yellow-400">json_decode</span>(<span class="text-pink-400">$response</span>, <span class="text-cyan-400">true</span>);
<span class="text-yellow-400">curl_close</span>(<span class="text-pink-400">$ch</span>);
<span class="text-yellow-400">print_r</span>(<span class="text-pink-400">$data</span>);</pre>
                    </div>

                    <!-- cURL Example -->
                    <div v-show="activeTab === 'curl'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
curl -X POST --data-urlencode 'content@path/to/latest.log' 'https://api.mclogs.lemwood.icu/1/log'</pre>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <h3 class="font-semibold text-sm">成功响应 (200 OK)</h3>
                        <pre class="bg-muted p-3 rounded-md text-xs border border-border overflow-x-auto whitespace-pre">{
    "success": true,
    "id": "8FlTowW",
    "url": "https://mclogs.lemwood.icu/8FlTowW",
    "raw": "https://api.mclogs.lemwood.icu/1/raw/8FlTowW"
}</pre>
                    </div>
                    <div class="space-y-2">
                        <h3 class="font-semibold text-sm">错误响应</h3>
                        <pre class="bg-muted p-3 rounded-md text-xs border border-border overflow-x-auto whitespace-pre">{
    "success": false,
    "error": "必需的 POST 参数 'content' 为空。"
}</pre>
                    </div>
                </div>
            </section>

            <!-- Analyse -->
            <section class="space-y-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold">{{ t('instant_analysis') }}</h2>
                    <span class="bg-blue-600 text-white px-2 py-1 rounded text-xs font-bold">POST</span>
                </div>
                <div class="font-mono bg-muted p-3 rounded-md overflow-x-auto text-sm border border-border">
                    https://api.mclogs.lemwood.icu/1/analyse
                </div>
                <p class="text-sm text-muted-foreground">{{ t('instant_analysis_desc') }}</p>
                <div class="space-y-2">
                    <h3 class="font-semibold text-sm">{{ t('request_params') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ t('params_same_as_log') }} <code class="bg-muted px-1 rounded">/1/log</code> {{ t('params_required_field') }} <code class="bg-muted px-1 rounded font-mono text-primary">content</code>)。</p>
                </div>
            </section>

            <!-- Insights -->
            <section class="space-y-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold">{{ t('get_insights') }}</h2>
                    <span class="bg-green-600 text-white px-2 py-1 rounded text-xs font-bold">GET</span>
                </div>
                <div class="font-mono bg-muted p-3 rounded-md overflow-x-auto text-sm border border-border">
                    https://api.mclogs.lemwood.icu/1/insights/[id]
                </div>
                <p class="text-sm text-muted-foreground">{{ t('get_insights_desc') }}</p>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">{{ t('call_examples') }}</h3>
                        <div class="flex bg-muted rounded-md p-1 border border-border">
                            <button @click="setTab('js')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'js' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">JavaScript</button>
                            <button @click="setTab('php')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'php' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">PHP</button>
                            <button @click="setTab('curl')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'curl' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">cURL</button>
                        </div>
                    </div>
                    <div v-show="activeTab === 'js'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">const</span> response = <span class="text-cyan-400">await</span> <span class="text-yellow-400">fetch</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/insights/8FlTowW'</span>);
<span class="text-cyan-400">const</span> data = <span class="text-cyan-400">await</span> response.<span class="text-yellow-400">json</span>();
<span class="text-cyan-400">console</span>.<span class="text-yellow-400">log</span>(data);</pre>
                    </div>
                    <div v-show="activeTab === 'php'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">&lt;?php</span>
<span class="text-pink-400">$data</span> = <span class="text-yellow-400">json_decode</span>(<span class="text-yellow-400">file_get_contents</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/insights/8FlTowW'</span>), <span class="text-cyan-400">true</span>);
<span class="text-yellow-400">print_r</span>(<span class="text-pink-400">$data</span>);</pre>
                    </div>
                    <div v-show="activeTab === 'curl'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
curl https://api.mclogs.lemwood.icu/1/insights/8FlTowW</pre>
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="font-semibold text-sm">{{ t('response_example') }}</h3>
                    <pre class="bg-muted p-3 rounded-md text-xs overflow-x-auto whitespace-pre max-h-64 border border-border">{
    "analysis": {
        "software": "Spigot",
        "version": "1.20.1",
        "issues": [
            {
                "message": "A plugin crashed during enable.",
                "solutions": [
                    { "message": "Update the plugin or check its configuration." }
                ]
            }
        ]
    }
}</pre>
                </div>
            </section>

            <!-- Raw Log -->
            <section class="space-y-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold">{{ t('get_raw_log') }}</h2>
                    <span class="bg-green-600 text-white px-2 py-1 rounded text-xs font-bold">GET</span>
                </div>
                <div class="font-mono bg-muted p-3 rounded-md overflow-x-auto text-sm border border-border">
                    https://api.mclogs.lemwood.icu/1/raw/[id]
                </div>
                <p class="text-sm text-muted-foreground">{{ t('get_raw_log_desc') }}</p>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">{{ t('call_examples') }}</h3>
                        <div class="flex bg-muted rounded-md p-1 border border-border">
                            <button @click="setTab('js')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'js' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">JavaScript</button>
                            <button @click="setTab('php')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'php' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">PHP</button>
                            <button @click="setTab('curl')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'curl' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">cURL</button>
                        </div>
                    </div>
                    <div v-show="activeTab === 'js'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">const</span> response = <span class="text-cyan-400">await</span> <span class="text-yellow-400">fetch</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/raw/8FlTowW'</span>);
<span class="text-cyan-400">const</span> text = <span class="text-cyan-400">await</span> response.<span class="text-yellow-400">text</span>();
<span class="text-cyan-400">console</span>.<span class="text-yellow-400">log</span>(text);</pre>
                    </div>
                    <div v-show="activeTab === 'php'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">echo</span> <span class="text-yellow-400">file_get_contents</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/raw/8FlTowW'</span>);</pre>
                    </div>
                    <div v-show="activeTab === 'curl'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
curl https://api.mclogs.lemwood.icu/1/raw/8FlTowW</pre>
                    </div>
                </div>
            </section>

            <!-- AI Analysis -->
            <section class="space-y-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold">{{ t('ai_analysis') }}</h2>
                    <span class="bg-green-600 text-white px-2 py-1 rounded text-xs font-bold">GET</span>
                </div>
                <div class="font-mono bg-muted p-3 rounded-md overflow-x-auto text-sm border border-border">
                    https://api.mclogs.lemwood.icu/1/ai-analysis/[id]
                </div>
                <p class="text-sm text-muted-foreground">{{ t('ai_analysis_desc') }}</p>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">{{ t('call_examples') }}</h3>
                        <div class="flex bg-muted rounded-md p-1 border border-border">
                            <button @click="setTab('js')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'js' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">JavaScript</button>
                            <button @click="setTab('php')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'php' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">PHP</button>
                            <button @click="setTab('curl')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'curl' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">cURL</button>
                        </div>
                    </div>
                    <div v-show="activeTab === 'js'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">const</span> response = <span class="text-cyan-400">await</span> <span class="text-yellow-400">fetch</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/ai-analysis/8FlTowW'</span>);
<span class="text-cyan-400">const</span> data = <span class="text-cyan-400">await</span> response.<span class="text-yellow-400">json</span>();
<span class="text-cyan-400">console</span>.<span class="text-yellow-400">log</span>(data);</pre>
                    </div>
                    <div v-show="activeTab === 'php'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">&lt;?php</span>
<span class="text-pink-400">$data</span> = <span class="text-yellow-400">json_decode</span>(<span class="text-yellow-400">file_get_contents</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/ai-analysis/8FlTowW'</span>), <span class="text-cyan-400">true</span>);
<span class="text-yellow-400">print_r</span>(<span class="text-pink-400">$data</span>);</pre>
                    </div>
                    <div v-show="activeTab === 'curl'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
curl https://api.mclogs.lemwood.icu/1/ai-analysis/8FlTowW</pre>
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="font-semibold text-sm">{{ t('response_example') }}</h3>
                    <pre class="bg-muted p-3 rounded-md text-xs overflow-x-auto whitespace-pre max-h-64 border border-border">{
    "success": true,
    "analysis": "### 分析结果\n\n检测到内存溢出错误 (java.lang.OutOfMemoryError)...\n\n### 解决方案\n\n1. 增加 JVM 分配的内存..."
}</pre>
                </div>
            </section>

            <!-- Limits -->
            <section class="space-y-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold">{{ t('get_limits') }}</h2>
                    <span class="bg-green-600 text-white px-2 py-1 rounded text-xs font-bold">GET</span>
                </div>
                <div class="font-mono bg-muted p-3 rounded-md overflow-x-auto text-sm border border-border">
                    https://api.mclogs.lemwood.icu/1/limits
                </div>
                <p class="text-sm text-muted-foreground">{{ t('get_limits_desc') }}</p>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">{{ t('call_examples') }}</h3>
                        <div class="flex bg-muted rounded-md p-1 border border-border">
                            <button @click="setTab('js')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'js' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">JavaScript</button>
                            <button @click="setTab('php')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'php' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">PHP</button>
                            <button @click="setTab('curl')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'curl' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">cURL</button>
                        </div>
                    </div>
                    <div v-show="activeTab === 'js'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">const</span> response = <span class="text-cyan-400">await</span> <span class="text-yellow-400">fetch</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/limits'</span>);
<span class="text-cyan-400">const</span> data = <span class="text-cyan-400">await</span> response.<span class="text-yellow-400">json</span>();
<span class="text-cyan-400">console</span>.<span class="text-yellow-400">log</span>(data);</pre>
                    </div>
                    <div v-show="activeTab === 'php'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">&lt;?php</span>
<span class="text-pink-400">$data</span> = <span class="text-yellow-400">json_decode</span>(<span class="text-yellow-400">file_get_contents</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/limits'</span>), <span class="text-cyan-400">true</span>);
<span class="text-yellow-400">print_r</span>(<span class="text-pink-400">$data</span>);</pre>
                    </div>
                    <div v-show="activeTab === 'curl'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
curl https://api.mclogs.lemwood.icu/1/limits</pre>
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="font-semibold text-sm">{{ t('response_example') }}</h3>
                    <pre class="bg-muted p-3 rounded-md text-xs overflow-x-auto whitespace-pre border border-border">{
    "storageTime": 86400,
    "maxLength": 10485760,
    "maxLines": 25000
}</pre>
                </div>
            </section>

            <!-- Delete Log -->
            <section class="space-y-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold">删除日志文件</h2>
                    <span class="bg-red-600 text-white px-2 py-1 rounded text-xs font-bold">DELETE</span>
                </div>

                <div class="font-mono bg-muted p-3 rounded-md overflow-x-auto text-sm border border-border">
                    https://api.mclogs.lemwood.icu/1/delete/[id]
                </div>

                <p class="text-sm text-muted-foreground">删除指定 ID 的日志文件。此操作不可逆，请谨慎使用。</p>

                <div class="space-y-4">
                    <h3 class="text-lg font-medium">请求参数</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border-collapse border border-border">
                            <thead class="bg-muted">
                                <tr>
                                    <th class="border border-border p-2 text-left">字段</th>
                                    <th class="border border-border p-2 text-left">类型</th>
                                    <th class="border border-border p-2 text-left">描述</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="border border-border p-2 font-mono text-primary">id</td>
                                    <td class="border border-border p-2">string</td>
                                    <td class="border border-border p-2">要删除的日志文件的唯一标识符</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">调用示例</h3>
                        <div class="flex bg-muted rounded-md p-1 border border-border">
                            <button
                                @click="setTab('js')"
                                :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'js' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']"
                            >JavaScript</button>
                            <button
                                @click="setTab('php')"
                                :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'php' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']"
                            >PHP</button>
                            <button
                                @click="setTab('curl')"
                                :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'curl' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']"
                            >cURL</button>
                        </div>
                    </div>

                    <!-- JS Example -->
                    <div v-show="activeTab === 'js'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">const</span> logId = <span class="text-green-400">"8FlTowW"</span>;
<span class="text-cyan-400">const</span> response = <span class="text-cyan-400">await</span> <span class="text-yellow-400">fetch</span>(<span class="text-green-400">`https://api.mclogs.lemwood.icu/1/delete/<span class="text-yellow-400">${logId}</span>`</span>, {
    method: <span class="text-green-400">'DELETE'</span>,
    headers: {
        'Content-Type': 'application/json'
    }
});
<span class="text-cyan-400">const</span> data = <span class="text-cyan-400">await</span> response.<span class="text-yellow-400">json</span>();
<span class="text-cyan-400">console</span>.<span class="text-yellow-400">log</span>(data);</pre>
                    </div>

                    <!-- PHP Example -->
                    <div v-show="activeTab === 'php'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">&lt;?php</span>
<span class="text-pink-400">$logId</span> = <span class="text-green-400">"8FlTowW"</span>;
<span class="text-pink-400">$ch</span> = <span class="text-yellow-400">curl_init</span>(<span class="text-green-400">"https://api.mclogs.lemwood.icu/1/delete/<span class="text-pink-400">$logId</span>"</span>);
<span class="text-yellow-400">curl_setopt</span>(<span class="text-pink-400">$ch</span>, CURLOPT_CUSTOMREQUEST, <span class="text-green-400">"DELETE"</span>);
<span class="text-yellow-400">curl_setopt</span>(<span class="text-pink-400">$ch</span>, CURLOPT_RETURNTRANSFER, <span class="text-cyan-400">true</span>);
<span class="text-yellow-400">curl_setopt</span>(<span class="text-pink-400">$ch</span>, CURLOPT_HTTPHEADER, [
    <span class="text-green-400">"Content-Type: application/json"</span>
]);
<span class="text-pink-400">$response</span> = <span class="text-yellow-400">curl_exec</span>(<span class="text-pink-400">$ch</span>);
<span class="text-pink-400">$data</span> = <span class="text-yellow-400">json_decode</span>(<span class="text-pink-400">$response</span>, <span class="text-cyan-400">true</span>);
<span class="text-yellow-400">curl_close</span>(<span class="text-pink-400">$ch</span>);
<span class="text-yellow-400">print_r</span>(<span class="text-pink-400">$data</span>);</pre>
                    </div>

                    <!-- cURL Example -->
                    <div v-show="activeTab === 'curl'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
curl -X DELETE -H "Content-Type: application/json" 'https://api.mclogs.lemwood.icu/1/delete/8FlTowW'</pre>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <h3 class="font-semibold text-sm">成功响应 (200 OK)</h3>
                        <pre class="bg-muted p-3 rounded-md text-xs border border-border overflow-x-auto whitespace-pre">{
    "success": true,
    "message": "Log deleted successfully"
}</pre>
                    </div>
                    <div class="space-y-2">
                        <h3 class="font-semibold text-sm">错误响应</h3>
                        <pre class="bg-muted p-3 rounded-md text-xs border border-border overflow-x-auto whitespace-pre">{
    "success": false,
    "error": "Log not found"
}</pre>
                    </div>
                </div>
            </section>

            <!-- Rate Error -->
            <section class="space-y-6">
                <div class="flex items-center gap-4">
                    <h2 class="text-2xl font-semibold">速率限制错误信息</h2>
                    <span class="bg-green-600 text-white px-2 py-1 rounded text-xs font-bold">GET</span>
                </div>
                <div class="font-mono bg-muted p-3 rounded-md overflow-x-auto text-sm border border-border">
                    https://api.mclogs.lemwood.icu/1/errors/rate
                </div>
                <p class="text-sm text-muted-foreground">返回标准的 429 Too Many Requests 错误响应。这主要用于测试或前端显示标准错误消息。</p>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">调用示例</h3>
                        <div class="flex bg-muted rounded-md p-1 border border-border">
                            <button @click="setTab('js')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'js' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">JavaScript</button>
                            <button @click="setTab('php')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'php' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">PHP</button>
                            <button @click="setTab('curl')" :class="['px-3 py-1 text-xs rounded-sm transition-all', activeTab === 'curl' ? 'bg-background shadow-sm text-foreground' : 'text-muted-foreground hover:text-foreground']">cURL</button>
                        </div>
                    </div>
                    <div v-show="activeTab === 'js'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">const</span> response = <span class="text-cyan-400">await</span> <span class="text-yellow-400">fetch</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/errors/rate'</span>);
<span class="text-cyan-400">const</span> data = <span class="text-cyan-400">await</span> response.<span class="text-yellow-400">json</span>();
<span class="text-cyan-400">console</span>.<span class="text-yellow-400">log</span>(data);</pre>
                    </div>
                    <div v-show="activeTab === 'php'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
<span class="text-cyan-400">&lt;?php</span>
<span class="text-pink-400">$data</span> = <span class="text-yellow-400">json_decode</span>(<span class="text-yellow-400">file_get_contents</span>(<span class="text-green-400">'https://api.mclogs.lemwood.icu/1/errors/rate'</span>), <span class="text-cyan-400">true</span>);
<span class="text-yellow-400">print_r</span>(<span class="text-pink-400">$data</span>);</pre>
                    </div>
                    <div v-show="activeTab === 'curl'" class="relative animate-in fade-in duration-300">
                        <pre class="bg-slate-950 text-slate-50 p-4 rounded-lg text-xs overflow-x-auto whitespace-pre leading-relaxed border border-slate-800">
curl https://api.mclogs.lemwood.icu/1/errors/rate</pre>
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="font-semibold text-sm">响应示例</h3>
                    <pre class="bg-muted p-3 rounded-md text-xs overflow-x-auto whitespace-pre border border-border">{
    "success": false,
    "error": "Unfortunately you have exceeded the rate limit for the current time period. Please try again later."
}</pre>
                </div>
            </section>

            <!-- SDKs -->
            <section class="space-y-6">
                <h2 class="text-2xl font-semibold">本地 SDK</h2>
                <p class="text-sm text-muted-foreground">我们为您提供了开箱即用的本地 SDK，您可以直接下载并集成到您的项目中。</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <a href="/sdk/mclogs-php-sdk.zip" download class="group block p-5 border rounded-xl hover:border-primary/50 hover:bg-primary/5 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-bold text-lg group-hover:text-primary transition-colors">PHP SDK</div>
                            <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-bold">LOCAL</span>
                        </div>
                        <div class="text-sm text-muted-foreground mb-4">轻量级 cURL 封装，支持粘贴、读取及分析日志。</div>
                        <div class="text-xs font-medium text-primary flex items-center gap-1">
                            点击下载 mclogs-php-sdk.zip
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-download"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                        </div>
                    </a>
                    <a href="/sdk/mclogs-js-sdk.zip" download class="group block p-5 border rounded-xl hover:border-primary/50 hover:bg-primary/5 transition-all">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-bold text-lg group-hover:text-primary transition-colors">JavaScript SDK</div>
                            <span class="text-[10px] bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded font-bold">LOCAL</span>
                        </div>
                        <div class="text-sm text-muted-foreground mb-4">基于 Fetch API，适用于浏览器或 Node.js 环境。</div>
                        <div class="text-xs font-medium text-primary flex items-center gap-1">
                            点击下载 mclogs-js-sdk.zip
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-download"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                        </div>
                    </a>
                </div>
            </section>

             <!-- Notes -->
            <section class="space-y-4 bg-secondary/10 p-6 rounded-xl border border-border">
                <h2 class="text-xl font-semibold">API 限制与规范</h2>
                <ul class="list-disc list-inside text-sm space-y-2 text-muted-foreground">
                    <li>速率限制：<strong>每分钟 60 个请求</strong> (按 IP 计算)。</li>
                    <li>内容限制：最大 <strong>10 MiB</strong> 或 <strong>25,000 行</strong>。</li>
                    <li>存储时间：日志在最后一次查看后至少保留 <strong>90 天</strong>。</li>
                    <li>请务必在请求头中设置正确的 <code class="bg-muted px-1 rounded">Content-Type: application/x-www-form-urlencoded</code>。</li>
                </ul>
            </section>
        </div>
    </div>
</template>