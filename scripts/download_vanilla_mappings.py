#!/usr/bin/env python3
"""下载 Mojang official mappings（Vanilla client）到 mappings/vanilla/<version>.txt

遍历 version manifest 的 release 版本，下载有 client_mappings 的（1.14.4 ~ 1.21.11，
共 39 个正式版）；已存在则跳过（增量）。26.x 无混淆、1.14.3 及更早无官方映射自动跳过。
用法：python3 scripts/download_vanilla_mappings.py
"""
import json
import os
import sys
import tempfile
import urllib.request

BASE = "https://launchermeta.mojang.com/mc/game/version_manifest_v2.json"
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "mappings", "vanilla")


def main() -> int:
    os.makedirs(OUT, exist_ok=True)
    print(f"输出目录: {os.path.abspath(OUT)}")
    manifest = json.load(urllib.request.urlopen(BASE, timeout=30))
    versions = [v for v in manifest["versions"] if v["type"] == "release"]
    print(f"release 版本 {len(versions)} 个，开始增量下载...")

    ok = skip = fail = 0
    for v in versions:
        vid = v["id"]
        target = os.path.join(OUT, f"{vid}.txt")
        if os.path.isfile(target) and os.path.getsize(target) > 0:
            skip += 1
            continue
        try:
            vj = json.load(urllib.request.urlopen(v["url"], timeout=30))
            cm = vj.get("downloads", {}).get("client_mappings")
            if not cm:
                continue  # 1.14.3 及更早无官方映射 / 26.x 无混淆
            url = cm["url"]
            size = cm["size"] // 1024 // 1024
            print(f"[down] {vid} ({size}MB)")
            data = urllib.request.urlopen(url, timeout=120).read()
            fd, part = tempfile.mkstemp(prefix=f".{vid}.", suffix=".part", dir=OUT)
            closed = False
            try:
                with os.fdopen(fd, "wb") as f:
                    f.write(data)
                closed = True  # fdopen 的 with 块已关闭 fd
                os.replace(part, target)
            except Exception:
                # 仅当 fdopen 尚未接管（或接管后异常已自行关闭）时兜底关闭，
                # 避免二次 close 抛 EBADF 掩盖原始网络错误
                if not closed:
                    try:
                        os.close(fd)
                    except OSError:
                        pass
                try:
                    os.unlink(part)
                except FileNotFoundError:
                    pass
                raise
            ok += 1
        except Exception as e:  # noqa: BLE001
            print(f"[FAIL] {vid}: {e}")
            fail += 1

    print(f"完成: 下载 {ok}, 跳过 {skip}, 失败 {fail}")
    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
