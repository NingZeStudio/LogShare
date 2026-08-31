-- OpenLiteWaf 统计页入口：由 docker/nginx/default.conf 中的 content_by_lua_file 调用
-- HTML：/security　JSON：/security/stats
require("openlitewaf").stats()
