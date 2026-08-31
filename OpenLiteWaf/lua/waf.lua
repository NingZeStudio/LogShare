-- OpenLiteWaf 检查入口：由 docker/nginx/default.conf 中的 access_by_lua_file 调用
require("openlitewaf").access()
