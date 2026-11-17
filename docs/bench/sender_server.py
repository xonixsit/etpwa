"""Local send bench for the live WhatsApp Cloud API number.

Start:  python sender_server.py        then open  http://localhost:8765

Reads the System User token from wa_token.txt and never sends it to the browser.
Recipients must appear in test_numbers.txt. Every send is appended to send_log.csv.
"""

import csv
import json
import os
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))
TOKEN_PATH = os.environ.get("SENDER_TOKEN", os.path.join(HERE, "wa_token.txt"))
ALLOW_PATH = os.environ.get("SENDER_ALLOW", os.path.join(HERE, "test_numbers.txt"))
LOG_PATH = os.environ.get("SENDER_LOG", os.path.join(HERE, "send_log.csv"))
PAGE_PATH = os.path.join(HERE, "sender.html")

WABA_ID = "1717463009877473"
PHONE_ID = "1427706937088002"
GRAPH = "https://graph.facebook.com/v20.0"

PRICE_AED = 0.0125          # Meta utility rate, North America, billed in AED
MAX_UNIQUE = 100            # this test campaign: unique numbers allowed in total
DAY_CAP = 250               # TIER_250: unique recipients per rolling 24 hours
BATCH_GAP = 1.0             # seconds between batch sends
BATCH_MAX_ROWS = 100
ABORT_AFTER_FAILS = 3

# Templates whose approved header is a media placeholder must be sent the image again.
# Meta rejected the flat {"type":"image","link":...} shape; the nested one below is what
# this account's v20.0 endpoint accepts. Verified live on 6 Nov 2026.
HEADER_IMAGE = {
    "etp_opener_brand": "https://www.etaxplanner.com/assets/images/logo/etaxplanner_logo.png",
}

# The inbox app holds the opt-out truth. If it cannot be reached, nothing is sent.
INBOX_URL = os.environ.get("SENDER_INBOX", "").rstrip("/")
INBOX_TOKEN = os.environ.get("SENDER_INBOX_TOKEN", "")
SUPPRESSION_TTL = 60.0

LOCK = threading.Lock()
_tpl_cache = {"at": 0.0, "data": None}
_suppressions = {"at": 0.0, "numbers": None}

PLACEHOLDER = re.compile(r"\{\{(\d+)\}\}")


# ---------------------------------------------------------------- graph calls

def token():
    with open(TOKEN_PATH, encoding="utf-8") as f:
        t = f.read().strip()
    if not t:
        raise RuntimeError("wa_token.txt is empty")
    return t


def graph(method, path, payload=None, params=None):
    url = "%s%s" % (GRAPH, path)
    if params:
        url += "?" + urllib.parse.urlencode(params)
    body = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(
        url, data=body, method=method,
        headers={"Authorization": "Bearer " + token(),
                 "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=45) as r:
            return json.loads(r.read().decode("utf-8", "replace"))
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        try:
            err = json.loads(raw).get("error", {})
        except ValueError:
            err = {"message": raw[:200]}
        raise RuntimeError("%s %s: %s" % (e.code, err.get("code", ""), err.get("message", "")))


# ------------------------------------------------------------- suppression list

def fetch_suppressions():
    """Opted-out numbers from the inbox app. Raises when the list cannot be read."""
    if not INBOX_URL:
        raise RuntimeError("SENDER_INBOX is not set")
    req = urllib.request.Request(
        INBOX_URL + "/wa/suppressions",
        headers={"Authorization": "Bearer " + INBOX_TOKEN})
    with urllib.request.urlopen(req, timeout=8) as r:
        body = r.read().decode("utf-8", "replace")
    return {digits(line) for line in body.splitlines() if digits(line)}


def suppressions(force=False):
    """Cached for SUPPRESSION_TTL seconds so a 100-row batch makes one call."""
    now = time.time()
    if (not force and _suppressions["numbers"] is not None
            and now - _suppressions["at"] < SUPPRESSION_TTL):
        return _suppressions["numbers"]
    numbers = fetch_suppressions()
    _suppressions.update({"at": now, "numbers": numbers})
    return numbers


def record_send(to, template, lang, msg_id):
    """Tell the inbox what was sent. Best effort: a failure is reported, never fatal."""
    if not INBOX_URL or not msg_id:
        return ""
    payload = json.dumps({"to": to, "template": template, "language": lang,
                          "message_id": msg_id,
                          "utc_time": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")}).encode()
    req = urllib.request.Request(
        INBOX_URL + "/inbound/record-send", data=payload, method="POST",
        headers={"Authorization": "Bearer " + INBOX_TOKEN, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=8) as r:
            r.read()
        return ""
    except Exception as e:                                      # noqa: BLE001
        return "inbox record failed: %s" % e


def load_templates(refresh=False):
    """Approved Utility templates, with their placeholders and example values."""
    now = time.time()
    if not refresh and _tpl_cache["data"] and now - _tpl_cache["at"] < 60:
        return _tpl_cache["data"]

    res = graph("GET", "/%s/message_templates" % WABA_ID, params={
        "fields": "name,status,category,language,components", "limit": 100})
    out = []
    for t in res.get("data", []):
        if t.get("status") != "APPROVED" or t.get("category") != "UTILITY":
            continue
        comps, order, examples = {}, [], []
        for c in t.get("components", []) or []:
            ctype = c.get("type")
            if ctype in ("HEADER", "BODY"):
                text = c.get("text") or ""
                idx = [int(m) for m in PLACEHOLDER.findall(text)]
                comps[ctype] = {"text": text, "idx": idx,
                                "format": c.get("format", "TEXT")}
                order += idx
                ex = (c.get("example") or {})
                got = ex.get("body_text") or ex.get("header_text") or []
                if got and isinstance(got[0], list):
                    examples.extend(got[0])
                elif got:
                    examples.extend(got)
                handle = (ex.get("header_handle") or [""])[0]
                if handle:
                    comps[ctype]["handle"] = handle
                    comps[ctype]["kind"] = "MEDIA"     # header ships a sample image/video/doc
            elif ctype == "FOOTER":
                comps[ctype] = {"text": c.get("text") or ""}
            elif ctype == "BUTTONS":
                texts = [b.get("text", "") for b in c.get("buttons", [])]
                comps[ctype] = {"buttons": texts}
                links = [b.get("type") for b in c.get("buttons", []) if b.get("type") != "QUICK_REPLY"]
                if links:
                    comps[ctype]["kinds"] = links
        lang = t.get("language")
        lang = lang["code"] if isinstance(lang, dict) else str(lang or "en_US")
        n = max(order) if order else 0
        out.append({
            "name": t["name"],
            "language": lang,
            "variables": n,
            "examples": examples[:n],
            "firstIsName": bool(examples) and examples[0] == "Ahmed",
            "components": comps,
            "buttons": comps.get("BUTTONS", {}).get("buttons", []),
            "preview": " / ".join(comps.get("BUTTONS", {}).get("buttons", [])),
        })
    out.sort(key=lambda x: x["name"])
    _tpl_cache["at"], _tpl_cache["data"] = now, out
    return out


# ------------------------------------------------------------- allow-list/log

def digits(s):
    return re.sub(r"\D", "", s or "")


def allow_list():
    """E.164 numbers from test_numbers.txt, mapped to the label after the comma."""
    people = {}
    if os.path.exists(ALLOW_PATH):
        with open(ALLOW_PATH, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                num, _, label = line.partition(",")
                d = digits(num)
                if d:
                    people[d] = label.strip() or d
    return people


def read_log():
    rows = []
    if os.path.exists(LOG_PATH):
        with open(LOG_PATH, encoding="utf-8", newline="") as f:
            for r in csv.DictReader(f):
                rows.append(r)
    return rows


def append_log(to, template, lang, status, msg_id, error):
    new = not os.path.exists(LOG_PATH)
    with LOCK:
        with open(LOG_PATH, "a", encoding="utf-8", newline="") as f:
            w = csv.writer(f)
            if new:
                w.writerow(["utc_time", "to", "template", "language",
                            "status", "message_id", "error"])
            w.writerow([datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ"),
                        to, template, lang, status, msg_id, error])


def unique_in_log():
    return {r["to"] for r in read_log() if r.get("message_id") or r.get("status") == "sent"}


def last_24h_unique():
    cut = datetime.utcnow() - timedelta(hours=24)
    out = set()
    for r in read_log():
        try:
            when = datetime.strptime(r["utc_time"], "%Y-%m-%dT%H:%M:%SZ")
        except (KeyError, ValueError):
            continue
        if when >= cut and (r.get("message_id") or r.get("status") == "sent"):
            out.add(r["to"])
    return out


def today_count():
    day = datetime.utcnow().strftime("%Y-%m-%d")
    return sum(1 for r in read_log() if (r.get("utc_time") or "").startswith(day)
               and (r.get("message_id") or r.get("status") == "sent"))


# ------------------------------------------------------------------ building

def build_values(tpl, name, extras):
    """Ordered variable list for one template, with the client name slotted in."""
    extras = [e for e in (extras or []) if str(e).strip() != ""]
    if tpl["variables"] == 0:
        return []
    if tpl["firstIsName"]:
        vals = [(name or "").strip()] + extras
    else:
        vals = extras
    if len(vals) != tpl["variables"]:
        raise ValueError("template %s needs %d value%s, got %d"
                         % (tpl["name"], tpl["variables"], "" if tpl["variables"] == 1 else "s", len(vals)))
    if tpl["firstIsName"] and not vals[0]:
        raise ValueError("client name is required by template %s" % tpl["name"])
    return vals


def build_payload(tpl, to, values):
    components = []
    hdr = tpl["components"].get("HEADER") or {}
    if hdr.get("kind") == "MEDIA":
        img = HEADER_IMAGE.get(tpl["name"])
        if not img:
            raise ValueError("%s has a %s header but no image URL in HEADER_IMAGE"
                             % (tpl["name"], hdr.get("format", "MEDIA")))
        components.append({"type": "header",
                           "parameters": [{"type": "image", "image": {"link": img}}]})
    for ctype in ("HEADER", "BODY"):
        c = tpl["components"].get(ctype)
        if not c or not c["idx"] or c.get("kind") == "MEDIA":
            continue
        params = [{"type": "text", "text": str(values[i - 1])} for i in c["idx"]]
        components.append({"type": ctype.lower(), "parameters": params})
    payload = {"messaging_product": "whatsapp", "to": to,
               "type": "template",
               "template": {"name": tpl["name"], "language": {"code": tpl["language"]}}}
    if components:
        payload["template"]["components"] = components
    return payload


def find_template(name):
    for t in load_templates():
        if t["name"] == name:
            return t
    return None


def check_gates(to, allowed, used, in24, blocked=frozenset()):
    """Every refusal reason that is not about the payload itself."""
    if to not in allowed:
        return "refused", "%s is not in test_numbers.txt" % to
    if len(to) < 10:
        return "refused", "%s looks too short to be E.164" % to
    if to in blocked:
        return "refused", "%s is on the suppression list (opted out)" % to
    if to not in used and len(used) >= MAX_UNIQUE:
        return "cap", "campaign cap reached: %d unique numbers" % MAX_UNIQUE
    if to not in in24 and len(in24) >= DAY_CAP:
        return "cap", "rolling 24h cap reached: %d unique recipients" % DAY_CAP
    return "ok", ""


def send_one(tpl, to, name, extras):
    """Validate, gate, send, log. Returns a dict for the browser."""
    values = build_values(tpl, name, extras)
    payload = build_payload(tpl, to, values)
    try:
        blocked = suppressions()
    except Exception as e:                                      # noqa: BLE001
        why = "send refused: suppression list unreachable (%s)" % e
        append_log(to, tpl["name"], tpl["language"], "refused", "", why)
        return {"ok": False, "to": to, "status": "refused", "error": why}
    allowed, used, in24 = allow_list(), unique_in_log(), last_24h_unique()
    verdict, why = check_gates(to, allowed, used, in24, blocked)
    if verdict != "ok":
        append_log(to, tpl["name"], tpl["language"], verdict, "", why)
        return {"ok": False, "to": to, "status": verdict, "error": why}
    try:
        res = graph("POST", "/%s/messages" % PHONE_ID, payload)
    except RuntimeError as e:
        append_log(to, tpl["name"], tpl["language"], "error", "", str(e))
        return {"ok": False, "to": to, "status": "error", "error": str(e)}
    msg_id = ""
    for m in res.get("messages", []) or []:
        msg_id = m.get("id", "")
    append_log(to, tpl["name"], tpl["language"], "sent", msg_id, "")
    record_note = record_send(to, tpl["name"], tpl["language"], msg_id)
    note = "accepted by Meta; delivery and replies now arrive in the inbox"
    if record_note:
        note += " (" + record_note + ")"
    return {"ok": True, "to": to, "status": "sent", "message_id": msg_id, "note": note}


# -------------------------------------------------------------------- handler

class Handler(BaseHTTPRequestHandler):
    server_version = "ETPSender/1"

    def log_message(self, fmt, *args):
        pass

    def reply(self, code, obj):
        raw = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(raw)

    def page(self):
        with open(PAGE_PATH, "rb") as f:
            raw = f.read()
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def body(self):
        n = int(self.headers.get("Content-Length") or 0)
        try:
            return json.loads(self.rfile.read(n).decode("utf-8")) if n else {}
        except ValueError:
            return {}

    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        try:
            if path == "/":
                return self.page()
            if path == "/api/templates":
                return self.reply(200, {"templates": load_templates(
                    refresh=self.path.endswith("refresh=1"))})
            if path == "/api/state":
                allowed = allow_list()
                used = unique_in_log()
                in24 = last_24h_unique()
                try:
                    blocked = suppressions()
                    sup_note = "%d opted out" % len(blocked)
                except Exception as e:                          # noqa: BLE001
                    blocked, sup_note = None, "inbox unreachable - sends are blocked: %s" % e
                return self.reply(200, {
                    "allow_list": len(allowed),
                    "allow": [{"to": k, "label": v} for k, v in sorted(allowed.items())],
                    "unique_used": len(used),
                    "unique_left": max(0, MAX_UNIQUE - len(used)),
                    "in_24h": len(in24),
                    "sent_today": today_count(),
                    "cost_today_aed": round(today_count() * PRICE_AED, 4),
                    "max_unique": MAX_UNIQUE,
                    "day_cap": DAY_CAP,
                    "price_aed": PRICE_AED,
                    "suppressed": None if blocked is None else sorted(blocked),
                    "suppression_note": sup_note,
                    "inbox_configured": bool(INBOX_URL),
                })
            return self.reply(404, {"error": "no such path"})
        except Exception as e:                                  # noqa: BLE001
            return self.reply(500, {"error": str(e)})

    def do_POST(self):
        path = urllib.parse.urlparse(self.path).path
        data = self.body()
        try:
            tpl = find_template(data.get("template", ""))
            if not tpl:
                return self.reply(400, {"ok": False,
                                        "error": "template is not an approved Utility template"})
            if path == "/api/send":
                to = digits(data.get("to", ""))
                res = send_one(tpl, to, data.get("name"), data.get("extras"))
                return self.reply(200 if res["ok"] else 400, res)
            if path == "/api/batch":
                rows = data.get("rows") or []
                if not rows:
                    return self.reply(400, {"ok": False, "error": "no rows"})
                if len(rows) > BATCH_MAX_ROWS:
                    return self.reply(400, {"ok": False,
                                            "error": "max %d rows per batch" % BATCH_MAX_ROWS})
                if data.get("preview"):
                    seen, out = set(), []
                    for r in rows:
                        d = digits(r.get("to", ""))
                        if d not in seen:
                            seen.add(d)
                            out.append({"to": d, "name": (r.get("name") or "").strip()})
                    return self.reply(200, {"ok": True, "rows": out, "unique": len(out),
                                            "cost_aed": round(len(out) * PRICE_AED, 4)})
                results, fails = [], 0
                for r in rows:
                    try:
                        res = send_one(tpl, digits(r.get("to", "")), r.get("name"),
                                       data.get("extras"))
                    except ValueError as e:
                        res = {"ok": False, "to": digits(r.get("to", "")),
                               "status": "refused", "error": str(e)}
                    results.append(res)
                    fails = 0 if res["ok"] else fails + 1
                    if fails >= ABORT_AFTER_FAILS:
                        results.append({"ok": False, "status": "aborted",
                                        "error": "stopped after %d straight failures"
                                                 % ABORT_AFTER_FAILS})
                        break
                    time.sleep(BATCH_GAP)
                return self.reply(200, {"ok": True, "results": results})
            return self.reply(404, {"error": "no such path"})
        except ValueError as e:
            return self.reply(400, {"ok": False, "error": str(e)})
        except Exception as e:                                  # noqa: BLE001
            return self.reply(500, {"ok": False, "error": str(e)})


def main():
    for p, why in ((TOKEN_PATH, "token"), (PAGE_PATH, "page"), (ALLOW_PATH, "allow-list")):
        if not os.path.exists(p):
            raise SystemExit("missing %s file: %s" % (why, p))
    srv = ThreadingHTTPServer(("127.0.0.1", 8765), Handler)
    print("sender on http://localhost:8765  (cap %d unique, %d per 24h)" % (MAX_UNIQUE, DAY_CAP))
    srv.serve_forever()


if __name__ == "__main__":
    main()
