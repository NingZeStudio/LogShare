<?php
$urls = Config::Get('urls');
$legal = Config::Get('legal');
$storage = \Config::Get('storage');
?>
<!DOCTYPE html>
<html lang="zh">
   <head>
       <meta charset="utf-8" />
       <meta name="theme-color" content="#2d3943" />

       <title>NingZeLogs - 粘贴、分享并分析您的 Minecraft 日志</title>

       <base href="/" />

       
       
       <link rel="stylesheet" href="css/btn.css" />
       <link rel="stylesheet" href="css/mclogs.css?v=2601194" />

       <link rel="shortcut icon" href="img/favicon.ico" type="image/x-icon" />

       <meta name="description" content="轻松粘贴您的 Minecraft 日志以进行分享和分析。">
       <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />

       <script>
           let _paq = window._paq = window._paq || [];
           _paq.push(['disableCookies']);
           _paq.push(['trackPageView']);
           _paq.push(['enableLinkTracking']);
           (function() {
               _paq.push(['setTrackerUrl', '/data']);
               _paq.push(['setSiteId', '5']);
               let d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
               g.async=true; g.src='/data.js'; s.parentNode.insertBefore(g,s);
           })();
       </script>
   </head>
   <body>
       <header class="row navigation">
           <div class="row-inner">
               <a href="/" class="logo">
                   <img src="img/logo.png" />
               </a>
           </div>
       </header>
       <div class="row dark title">
           <div class="row-inner">
               <h1 class="title-container">
                   <span class="title-bc">粘贴、分享和分析</span>您的 Minecraft 日志
               </h1>
           </div>
       </div>
       <div class="row dark paste">
           <div class="row-inner">
               <div class="paste-box">
                   <div class="paste-header">
                       <div class="paste-header-text">
                           在此粘贴您的日志
                       </div>
                       <span class="paste-save btn btn-green btn-no-margin" id="paste-select-file">选择文件</span>
                       <div class="paste-save btn btn-green btn-no-margin">
                            保存
                       </div>
                   </div>
                   <div id="dropzone" class="paste-body">
                       <textarea id="paste" autocomplete="off" spellcheck="false" data-max-length="<?=$storage['maxLength']?>" data-max-lines="<?=$storage['maxLines']?>"></textarea>
                   </div>
                   <div class="paste-footer">
                       <div class="paste-save btn btn-green btn-no-margin">
                            保存
                       </div>
                   </div>
               </div>
           </div>
       </div>
       <div class="row dark api" id="api">
           <div class="row-inner">
               <div class="article left">
                   <div class="article-icon">
                       API
                   </div>
                   <div class="article-info">
                       <div class="article-title">
                           使用我们的 API。
                       </div>
                       <div class="article-text">
                           将 <strong>NingZeLogs</strong> 直接集成到您的服务器面板、托管软件或任何其他平台中。此平台专为高性能自动化而构建，可通过我们的 HTTP API 轻松集成到任何现有软件中。
                       </div>
                       <div class="article-buttons">
                           <a href="<?=$urls['apiBaseUrl']?>" class="btn btn-blue btn-no-margin">
                                API 文档
                           </a>
                       </div>
                   </div>
               </div>
           </div>
       </div>
       <div class="row footer">
           <div class="row-inner">
               &copy; <?=date("Y"); ?> 由 NingZeLogs 提供 - <a href="https://zeinklab.com/">ZeinkLab</a> 旗下服务
           </div>
       </div>
       <script src="js/mclogs.js?v=260119"></script>
   </body>
</html>