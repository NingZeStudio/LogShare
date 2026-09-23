#!/usr/bin/env python3
"""parse_crash.py — Minecraft 崩溃日志摘要提取器

用法:
    python3 parse_crash.py <日志文件> [--json]

输出 JSON 摘要（或人类可读文本），包含:
    kind          : crash-report / hs_err / log
    loader        : fabric / forge / neoforge / vanilla / unknown
    description   : crash-report 的 Description 行
    exceptions    : 异常链（Exception/Caused by 序列）
    first_mod_frame : 堆栈中第一个疑似 Mod 包名帧
    mods          : 报告中列出的 Mod（id@version）
    mc_version / system : 版本与系统信息
    problem_frame : hs_err 的 Problematic frame
    errors        : 普通日志中的 ERROR/WARN/FATAL 行（截断）
"""
import json
import re
import sys

EXC_RE = re.compile(
    r"^\s*(?:Caused by:\s*)?([\w.$]+(?:Exception|Error|Throwable)[\w.$]*)"
    r"(?::\s*(.*))?$"
)
MOD_FRAME_RE = re.compile(r"at\s+([a-z][\w]*((?!\.)[\w$]+\.)*[A-Z]\w*)\.")
LOADER_RES = {
    "fabric": re.compile(r"fabricmc|fabric\s*loader|fabric\s*api", re.I),
    "neoforge": re.compile(r"neoforged", re.I),
    "forge": re.compile(r"net\.minecraftforge|\bFML\b|Forge Mod Loader", re.I),
}
MC_VER_RE = re.compile(r"Minecraft Version:\s*(.+)")
SYS_RE = re.compile(r"Operating System:\s*(.+)")
DESC_RE = re.compile(r"^Description:\s*(.+)", re.M)
PROB_FRAME_RE = re.compile(r"Problematic frame:\s*\n#\s*(.+)", re.M)
SIG_RE = re.compile(r"^\s*#\s+(SIGSEGV|SIGBUS|EXCEPTION_ACCESS_VIOLATION)\b.*")
DEP_RE = re.compile(r"[-•]?\s*(?:Mod\s+)?([\w-]+)\s+requires\s+([\w-]+)\s+([^\s,]+)")
MOD_LINE_RE = re.compile(r"^\s*-?\s*(modid|ID):\s*(\S+).*?(?:version[: ]\s*(\S+))?", re.I)


def classify(lines):
    text = "\n".join(lines)
    if "Minecraft Crash Report" in text or DESC_RE.search(text):
        return "crash-report"
    if "A fatal error has been detected" in text or PROB_FRAME_RE.search(text):
        return "hs_err"
    return "log"


def find_loader(text):
    for name, rx in LOADER_RES.items():
        if rx.search(text):
            return name
    return "vanilla" if "-- System Details --" in text else "unknown"


def exception_chain(lines):
    chain = []
    for ln in lines:
        m = EXC_RE.match(ln.strip())
        if m:
            chain.append({"exc": m.group(1), "msg": (m.group(2) or "").strip()[:200]})
    # 去重相邻重复，保留顺序；最底层(最后)是根因候选
    out = []
    for c in chain:
        if not out or out[-1]["exc"] != c["exc"] or out[-1]["msg"] != c["msg"]:
            out.append(c)
    return out


def first_mod_frame(lines):
    for ln in lines:
        if ln.strip().startswith("at ") and ("net.minecraft" in ln or "java." in ln):
            continue
        m = MOD_FRAME_RE.search(ln)
        if m and not any(
            p in m.group(1) for p in ("net.minecraft", "java.", "sun.", "javax.", "kotlin.", "org.lwjgl", "com.mojang", "org.spongepowered", "net.fabricmc", "net.minecraftforge", "net.neoforged")
        ):
            return m.group(1)
    return None


def extract_mods(text):
    """System Details 里 'Mod List:' 后的条目（Forge 风格），以及 mods 数量行。"""
    mods = []
    m = re.search(r"(\d+)\s+mods?\b", text)
    count = int(m.group(1)) if m else None
    ml = re.search(r"Mod List:?(.*?)(?:\n\s*\n|\Z)", text, re.S)
    if ml:
        block = re.findall(r"^\s*-\s*([\w-]+)\s*\|?\s*(?:version:\s*)?([\w.\-+]*)\s*$", ml.group(1), re.M)
        mods.extend(f"{a}@{b}" if b else a for a, b in block[:80])
    return {"count": count, "list": mods}


def tail_errors(lines, limit=30):
    keys = ("ERROR", "FATAL", "Exception", "# A fatal error")
    hits = [ln.rstrip() for ln in lines if any(k in ln for k in keys)]
    return hits[-limit:]


def parse(path):
    raw = open(path, encoding="utf-8", errors="replace").read()
    lines = raw.splitlines()
    text = "\n".join(lines)
    kind = classify(lines)
    info = {
        "file": path,
        "kind": kind,
        "loader": find_loader(text),
        "mc_version": (MC_VER_RE.search(text) or [None]) and (MC_VER_RE.search(text).group(1).strip() if MC_VER_RE.search(text) else None),
        "os": (SYS_RE.search(text) or [None]) and (SYS_RE.search(text).group(1).strip() if SYS_RE.search(text) else None),
    }
    if kind == "crash-report":
        d = DESC_RE.search(text)
        info["description"] = d.group(1).strip() if d else None
        info["exceptions"] = exception_chain(lines)
        info["first_mod_frame"] = first_mod_frame(lines)
        info["mods"] = extract_mods(text)
        deps = [ {"who": w, "needs": n, "range": r} for w, n, r in DEP_RE.findall(text)[:10] ]
        if deps:
            info["missing_dependencies"] = deps
    elif kind == "hs_err":
        pf = PROB_FRAME_RE.search(text)
        info["problem_frame"] = pf.group(1).strip() if pf else None
        sig = SIG_RE.search(text)
        info["signal"] = sig.group(1) if sig else None
        info["exceptions"] = exception_chain(lines)
    else:
        info["errors"] = tail_errors(lines)
        info["exceptions"] = exception_chain(lines)[-5:]
    return info


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    as_json = "--json" in sys.argv
    path = next(a for a in sys.argv[1:] if not a.startswith("--"))
    info = parse(path)
    if as_json:
        print(json.dumps(info, ensure_ascii=False, indent=2))
    else:
        for k, v in info.items():
            if isinstance(v, list) and v and isinstance(v[0], dict):
                print(f"{k}:")
                for item in v:
                    print(f"  - {item}")
            elif isinstance(v, dict):
                print(f"{k}: {json.dumps(v, ensure_ascii=False)}")
            elif isinstance(v, list):
                print(f"{k}: {len(v)} 条")
                for x in v[:8]:
                    print(f"  | {x}")
            else:
                print(f"{k}: {v}")


if __name__ == "__main__":
    main()
