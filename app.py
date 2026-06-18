"""
UCC FMS — Gunicorn / Render WSGI entry point
=======================================================
Serves:
  GET /            → index.html  (UCC FMS frontend)
  GET /api/*       → Python REST API via server.py
  POST /api/ai/chat → Anthropic AI proxy (Mo' finance assistant)

Deploy:  gunicorn app:app --bind 0.0.0.0:$PORT --workers 1 --timeout 120 --preload
"""

import os, sys, json, importlib, mimetypes, urllib.request, urllib.error
import gzip as _gzip, hashlib as _hashlib
from pathlib import Path

BASE_DIR   = Path(__file__).parent
INDEX_HTML = BASE_DIR / "index.html"
BRAND_ASSET_DIR = BASE_DIR / "assets" / "branding"
sys.path.insert(0, str(BASE_DIR))

# ── lazy-load backend ─────────────────────────────────────────────────────────
_sv = None
def sv():
    global _sv
    if _sv is None:
        _sv = importlib.import_module("server")
        print("UCC FMS: initialising database…")
        _sv.init_db()
        _sv.init_payroll_db()
        _sv.init_jv_db()
        print("UCC FMS: database ready.")
    return _sv

sv()   # pre-load on startup

# ── response helpers ──────────────────────────────────────────────────────────
_STATUS = {200:"200 OK",201:"201 Created",204:"204 No Content",
           400:"400 Bad Request",401:"401 Unauthorized",
           403:"403 Forbidden",404:"404 Not Found",405:"405 Method Not Allowed",
           500:"500 Internal Server Error"}

# CORS origin: restrict to explicit production origin or env-configured value.
# Wildcard (*) is intentionally avoided for security; configure ALLOWED_ORIGIN in Render env.
_ALLOWED_ORIGIN = os.environ.get("ALLOWED_ORIGIN", "").strip() or "https://sbs-erp-3b70.onrender.com"
CORS = [("Access-Control-Allow-Origin", _ALLOWED_ORIGIN),
        ("Access-Control-Allow-Headers","Content-Type, X-Session-ID"),
        ("Access-Control-Allow-Methods","GET, POST, PUT, DELETE, OPTIONS"),
        ("Vary", "Origin")]

def _j(sr, data, code=200, extra=None):
    body = json.dumps(data).encode()
    h = [("Content-Type","application/json"),("Content-Length",str(len(body)))] + CORS
    if extra: h += extra
    sr(_STATUS.get(code, str(code)), h)
    return [body]

def _html(sr, body_bytes):
    # ETag + revalidate so the browser keeps the ~2MB shell cached and only
    # re-downloads it when it actually changes (304 otherwise). Was no-store,
    # which forced a full re-download on every page open.
    etag = '"' + _hashlib.md5(body_bytes).hexdigest() + '"'
    sr("200 OK",[("Content-Type","text/html; charset=utf-8"),
                 ("Content-Length",str(len(body_bytes))),
                 ("Cache-Control","no-cache, must-revalidate"),
                 ("ETag", etag),
                 ("X-Content-Type-Options","nosniff"),
                 ("X-Frame-Options","SAMEORIGIN"),
                 ("Referrer-Policy","strict-origin-when-cross-origin")])
    return [body_bytes]



def _brand_asset(sr, request_path):
    """Serve bundled institutional logo assets from the local deployment package.
    This prevents logo instability caused by remote URLs, browser cache misses,
    or the SPA fallback returning index.html for image requests.
    """
    try:
        filename = os.path.basename(request_path)
        if not filename or filename.startswith('.'):
            return _j(sr, {"error":"invalid asset"}, 404)
        f = BRAND_ASSET_DIR / filename
        if not f.exists() or not f.is_file():
            return _j(sr, {"error":"asset not found"}, 404)
        body = f.read_bytes()
        ctype = mimetypes.guess_type(str(f))[0] or "application/octet-stream"
        sr("200 OK", [("Content-Type", ctype),
                      ("Content-Length", str(len(body))),
                      ("Cache-Control", "public, max-age=31536000, immutable"),
                      ("X-Content-Type-Options", "nosniff")])
        return [body]
    except Exception as exc:
        return _j(sr, {"error":"asset unavailable", "detail":str(exc)}, 500)

def _no_content(sr):
    h = [("Content-Length","0"),("Cache-Control","no-store")] + CORS
    sr("200 OK", h)
    return [b""]

def _csv(sr, data, filename):
    body = data.encode() if isinstance(data,str) else data
    sr("200 OK",[("Content-Type","text/csv"),
                 ("Content-Disposition",f'attachment; filename="{filename}"'),
                 ("Content-Length",str(len(body)))])
    return [body]

def _bin(sr, data, filename, ctype="application/octet-stream"):
    body = data if isinstance(data,(bytes,bytearray)) else bytes(data or b"")
    sr("200 OK",[("Content-Type",ctype),
                 ("Content-Disposition",f'attachment; filename="{filename}"'),
                 ("Content-Length",str(len(body)))])
    return [body]

def _body(environ):
    try:
        n = int(environ.get("CONTENT_LENGTH",0) or 0)
        if n > 25 * 1024 * 1024:   # 25 MB cap: reject oversized bodies before allocating, to prevent memory exhaustion
            return {}
        return json.loads(environ["wsgi.input"].read(n).decode()) if n else {}
    except: return {}

def _sid(environ):
    s = environ.get("HTTP_X_SESSION_ID","").strip()
    if s: return s
    for p in environ.get("HTTP_COOKIE","").split(";"):
        p = p.strip()
        if p.startswith("sbs_sid="): return p[8:]
    return None

def _qs(environ):
    from urllib.parse import parse_qs
    return parse_qs(environ.get("QUERY_STRING",""))

# ── AI proxy ──────────────────────────────────────────────────────────────────
AI_MODEL      = "claude-haiku-4-5-20251001"
AI_MAX_TOKENS = 500

def _ai_proxy(environ):
    """
    Secure v5.0 AI proxy.
    Supports OPENAI_API_KEY when configured, otherwise falls back to ANTHROPIC_API_KEY.
    Keys are read only from environment variables and are never returned to the browser.
    """
    data = _body(environ)

    def _extract_messages():
        raw_messages = data.get("messages", []) or []
        system_prompt = data.get("system", "") or ""
        clean_messages = []
        double_break = chr(10) + chr(10)
        user_marker = double_break + "User: "
        for msg in raw_messages:
            role = msg.get("role", "user")
            content = msg.get("content", "")
            looks_like_system = isinstance(content, str) and (content.startswith("You are Mo'") or content.startswith("You are Mo,") or content.startswith("You are AOI") or content.startswith("You are SBS"))
            if role == "user" and not system_prompt and looks_like_system:
                if user_marker in content:
                    parts = content.split(user_marker, 1)
                    system_prompt = parts[0]
                    if parts[1].strip():
                        clean_messages.append({"role":"user", "content":parts[1]})
                elif double_break in content:
                    parts = content.split(double_break, 1)
                    system_prompt = parts[0]
                    if parts[1].strip():
                        clean_messages.append({"role":"user", "content":parts[1]})
                else:
                    system_prompt = content
            else:
                clean_messages.append({"role": role if role in ("user","assistant","system") else "user", "content": content})
        if not clean_messages:
            clean_messages = [{"role":"user", "content":"Hello"}]
        return system_prompt, clean_messages

    system_prompt, clean_messages = _extract_messages()

    openai_key = os.environ.get("OPENAI_API_KEY", "").strip().strip('"').strip("'").strip()
    if openai_key:
        model = data.get("model") or os.environ.get("OPENAI_MODEL", "gpt-4o-mini")
        messages = []
        if system_prompt:
            messages.append({"role":"system", "content": system_prompt})
        for m in clean_messages:
            role = m.get("role", "user")
            if role == "system":
                messages.append({"role":"system", "content": m.get("content", "")})
            else:
                messages.append({"role": role if role in ("user","assistant") else "user", "content": m.get("content", "")})
        payload = {
            "model": model,
            "max_tokens": min(int(data.get("max_tokens", AI_MAX_TOKENS)), 1000),
            "messages": messages,
        }
        req = urllib.request.Request(
            "https://api.openai.com/v1/chat/completions",
            data=json.dumps(payload).encode(),
            headers={"Content-Type":"application/json", "Authorization":f"Bearer {openai_key}"},
            method="POST"
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                out = json.loads(resp.read().decode())
                text = (((out.get("choices") or [{}])[0].get("message") or {}).get("content") or "").strip()
                return {"provider":"OpenAI", "model": model, "content":[{"type":"text", "text": text or "No response returned."}]}
        except urllib.error.HTTPError as e:
            try:
                err_body = json.loads(e.read().decode())
                err_msg = (err_body.get('error') or {}).get('message', str(err_body))[:300]
            except Exception:
                err_msg = f"HTTP {e.code}"
            return {"provider":"OpenAI", "content":[{"type":"text", "text":f"⚠️ OpenAI API Error {e.code}: {err_msg}"}]}
        except urllib.error.URLError as e:
            return {"provider":"OpenAI", "content":[{"type":"text", "text":f"⚠️ Cannot reach OpenAI API: {str(e.reason)[:200]}."}]}
        except Exception as e:
            return {"provider":"OpenAI", "content":[{"type":"text", "text":f"⚠️ AI error: {str(e)[:200]}"}]}

    api_key = os.environ.get("ANTHROPIC_API_KEY", "").strip().strip('"').strip("'").strip()
    if not api_key:
        return {"content":[{"type":"text","text":"AI assistant unavailable — set OPENAI_API_KEY or ANTHROPIC_API_KEY in Render Environment."}]}
    if not api_key.startswith("sk-ant-") and not api_key.startswith("sk-"):
        return {"content":[{"type":"text","text":"AI key format error — please check the configured key in Render Environment."}]}
    payload = {
        "model": data.get("model") or os.environ.get("ANTHROPIC_MODEL", AI_MODEL),
        "max_tokens": min(int(data.get("max_tokens", AI_MAX_TOKENS)), 1000),
        "messages": [m for m in clean_messages if m.get('role') != 'system'],
    }
    if system_prompt:
        payload["system"] = system_prompt
    req = urllib.request.Request(
        "https://api.anthropic.com/v1/messages",
        data=json.dumps(payload).encode(),
        headers={"Content-Type":"application/json", "x-api-key":api_key, "anthropic-version":"2023-06-01"},
        method="POST"
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            out = json.loads(resp.read().decode())
            out.setdefault('provider', 'Anthropic')
            return out
    except urllib.error.HTTPError as e:
        try:
            err_body = json.loads(e.read().decode())
            err_msg = err_body.get('error',{}).get('message', str(err_body))[:300]
        except Exception:
            err_msg = f"HTTP {e.code}"
        return {"provider":"Anthropic", "content":[{"type":"text","text":f"⚠️ API Error {e.code}: {err_msg}"}]}
    except urllib.error.URLError as e:
        return {"provider":"Anthropic", "content":[{"type":"text","text":f"⚠️ Cannot reach Anthropic API: {str(e.reason)[:200]}."}]}
    except Exception as e:
        return {"provider":"Anthropic", "content":[{"type":"text","text":f"⚠️ AI error: {str(e)[:200]}"}]}


# ── main WSGI ─────────────────────────────────────────────────────────────────
def app(environ, sr):
    method = environ["REQUEST_METHOD"]
    path   = environ.get("PATH_INFO","/")
    qs     = _qs(environ)

    # CLIENT_FINAL_SESSION_CONTEXT_PATCH: clear any prior demo/main request context before routing.
    try:
        _cf_backend = sv()
        if hasattr(_cf_backend, "set_current_session"):
            _cf_backend.set_current_session(None)
    except Exception:
        pass

    # CORS preflight
    if method == "OPTIONS":
        sr("204 No Content", CORS + [("Content-Length","0")])
        return [b""]


    # ── Public health checks + frontend error capture ─────────────────────────
    if path == "/healthz" and method in ("GET", "HEAD"):
        if method == "HEAD":
            return _no_content(sr)

        _db_ok = True

        try:

            _hc = sv().get_db(); _hc.execute("SELECT 1"); _hc.close()

        except Exception:

            _db_ok = False

        return _j(sr, {"ok": _db_ok, "status": "ok" if _db_ok else "degraded", "db": "ok" if _db_ok else "error", "app": "UCC-FMS", "version": "5.0.28-deployment-stability"}, code=(200 if _db_ok else 503), extra=[("Cache-Control", "no-store")])

    # Render/uptime monitors may send HEAD /; return OK without touching auth.
    if method == "HEAD" and path == "/":
        return _no_content(sr)

    # Client-side errors must be accepted before login; otherwise Render fills with 401 loops.
    if method == "POST" and path == "/api/client-error":
        data = _body(environ)
        try:
            backend = sv()
            sess0 = backend.get_session(_sid(environ)) or {"username":"anonymous", "role":"Guest"}
            msg = str(data.get("message") or data.get("source") or "client error")[:250]
            print("UCC-FMS client-error:", msg)
            return _j(sr, backend.api_log_client_error(data, sess0), extra=[("Cache-Control","no-store")])
        except Exception as exc:
            print("client-error logging warning:", exc)
            return _j(sr, {"ok": True, "logged": False, "warning":"client error log unavailable"}, 200, extra=[("Cache-Control","no-store")])

    # ── Bundled branding assets ─
    if method == "GET" and path.startswith("/assets/branding/"):
        return _brand_asset(sr, path)

    # ── PWA: manifest + service worker (public) ─
    if method == "GET" and path == "/manifest.json":
        manifest = {
            "name": "University of Cape Coast Financial Management System",
            "short_name": "UCC-FMS",
            "start_url": "/",
            "display": "standalone",
            "theme_color": "#1B2A6B",
            "background_color": "#0f172a",
            "icons": [
                {"src": "/assets/branding/ucc-logo.png", "sizes": "192x192", "type": "image/png"}
            ]
        }
        body = json.dumps(manifest).encode()
        sr("200 OK", [("Content-Type", "application/manifest+json"), ("Content-Length", str(len(body))), ("Cache-Control", "no-cache")])
        return [body]
    if method == "GET" and path == "/sw.js":
        sw = (
            "const CACHE='ucc-fms-v1';\n"
            "self.addEventListener('install',e=>{self.skipWaiting();});\n"
            "self.addEventListener('activate',e=>{e.waitUntil(\n"
            "  caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())\n"
            ");});\n"
            "self.addEventListener('fetch',e=>{\n"
            "  const u=new URL(e.request.url);\n"
            "  if(e.request.method!=='GET'||u.pathname.startsWith('/api/')){return;}\n"
            "  const isHTML=e.request.mode==='navigate'||(e.request.headers.get('accept')||'').includes('text/html');\n"
            "  if(isHTML){\n"
            "    // network-first for the SPA shell so UI/CSS updates appear immediately\n"
            "    e.respondWith(\n"
            "      fetch(e.request).then(resp=>{try{caches.open(CACHE).then(c=>c.put(e.request,resp.clone()));}catch(_){}return resp;}).catch(()=>caches.match(e.request))\n"
            "    );\n"
            "    return;\n"
            "  }\n"
            "  // static assets: stale-while-revalidate\n"
            "  e.respondWith(\n"
            "    caches.open(CACHE).then(c=>c.match(e.request).then(r=>{\n"
            "      const f=fetch(e.request).then(resp=>{try{c.put(e.request,resp.clone());}catch(_){}return resp;}).catch(()=>r);\n"
            "      return r||f;\n"
            "    }))\n"
            "  );\n"
            "});\n"
        ).encode()
        sr("200 OK", [("Content-Type", "application/javascript"), ("Content-Length", str(len(sw))), ("Cache-Control", "no-cache")])
        return [sw]

    # ── Serve frontend ────────────────────────────────────────────────────────
    if method == "GET" and not path.startswith("/api/"):
        # favicon
        if path == "/favicon.ico":
            ico = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="6" fill="#0F2847"/><circle cx="16" cy="16" r="10" fill="#0ea5e9" opacity=".7"/></svg>'.encode()
            sr("200 OK",[("Content-Type","image/svg+xml"),("Content-Length",str(len(ico)))])
            return [ico]
        if path in ("/USER_GUIDE.html","/guide","/user-guide"):
            _gf = BASE_DIR / "USER_GUIDE.html"
            if _gf.exists():
                _gb = _gf.read_bytes()
                sr("200 OK",[("Content-Type","text/html; charset=utf-8"),("Content-Length",str(len(_gb))),("Cache-Control","no-cache")])
                return [_gb]
        # everything else → SPA index
        if INDEX_HTML.exists():
            body = INDEX_HTML.read_bytes()
            return _html(sr, body)
        return _j(sr, {"error":"index.html not found"}, 404)

    # ── Public API ────────────────────────────────────────────────────────────
    if path == "/api/health":
        # Public health check: return minimal safe info only. No db path or key preview.
        return _j(sr, {
            "ok": True,
            "status": "ok",
            "app": "UCC FMS",
            "version": "5.0.28-deployment-stability",
        })

    # ── Scheduled backup trigger for a Render Cron Job. Public but gated by the
    #    CRON_TOKEN env var (unset => disabled). Forces a WAL-consistent backup. ──
    if path == "/api/cron/backup" and method in ("GET", "POST"):
        _cron_tok_env = os.environ.get("CRON_TOKEN", "").strip()
        if not _cron_tok_env:
            return _j(sr, {"ok": False, "error": "Cron backup not enabled (set CRON_TOKEN env var)"}, 403)
        from urllib.parse import parse_qs as _cron_qs_parse
        _cron_qs = _cron_qs_parse(environ.get("QUERY_STRING", ""))
        _req_tok = (environ.get("HTTP_X_CRON_TOKEN", "") or (_cron_qs.get("token") or [""])[0]).strip()
        if _req_tok != _cron_tok_env:
            return _j(sr, {"ok": False, "error": "Invalid cron token"}, 403)
        try:
            _cron_sess = {"username": "system-cron", "role": "Admin", "full_name": "Scheduled Backup", "email": ""}
            _cr = sv().api_create_backup(_cron_sess, "Scheduled cron backup")
            try:
                import datetime as _fdt
                if _fdt.date.today().day == 1:
                    _fr = sv().api_send_flash_email(_cron_sess, None)
                    _cr["flash_email"] = {k: _fr.get(k) for k in ("ok", "sent", "skipped", "period", "error") if isinstance(_fr, dict) and k in _fr}
            except Exception as _fe:
                _cr["flash_email"] = {"ok": False, "error": str(_fe)[:120]}
            return _j(sr, _cr, 200 if _cr.get("ok") else 500, extra=[("Cache-Control", "no-store")])
        except Exception as _ce:
            return _j(sr, {"ok": False, "error": str(_ce)}, 500)

    # ── Token-gated backup DOWNLOAD for an unattended PC script (off-site copy to
    #    OneDrive etc.). Same CRON_TOKEN gate as /api/cron/backup, but streams the
    #    .db file so a scheduled task can pull a fresh backup with no admin login. ──
    if path == "/api/cron/backup/download" and method in ("GET", "POST"):
        _cdl_tok_env = os.environ.get("CRON_TOKEN", "").strip()
        if not _cdl_tok_env:
            return _j(sr, {"ok": False, "error": "Cron backup not enabled (set CRON_TOKEN env var)"}, 403)
        from urllib.parse import parse_qs as _cdl_qs_parse
        _cdl_qs = _cdl_qs_parse(environ.get("QUERY_STRING", ""))
        _cdl_tok = (environ.get("HTTP_X_CRON_TOKEN", "") or (_cdl_qs.get("token") or [""])[0]).strip()
        if _cdl_tok != _cdl_tok_env:
            return _j(sr, {"ok": False, "error": "Invalid cron token"}, 403)
        try:
            _cdl_sess = {"username": "system-cron", "role": "Admin", "full_name": "Scheduled Backup", "email": ""}
            _cdl = sv().api_create_backup(_cdl_sess, "Scheduled off-site download")
            if not _cdl.get("ok"):
                return _j(sr, {"ok": False, "error": _cdl.get("error", "Backup failed")}, 500)
            from pathlib import Path as _CdlPath
            _cdl_bytes = _CdlPath(_cdl["backup_path"]).read_bytes()
            return _bin(sr, _cdl_bytes, _cdl["backup_name"], "application/octet-stream")
        except Exception as _cde:
            return _j(sr, {"ok": False, "error": str(_cde)}, 500)

    # ── Admin recovery (only active when ADMIN_RECOVERY_TOKEN env var is set) ───
    if method == "GET" and path == "/api/admin-recovery":
        _rec_token_env = os.environ.get("ADMIN_RECOVERY_TOKEN", "").strip()
        if not _rec_token_env:
            return _j(sr, {"ok": False, "error": "Recovery not enabled"}, 403)
        from urllib.parse import parse_qs
        _rec_qs = parse_qs(environ.get("QUERY_STRING", ""))
        _req_tok = (_rec_qs.get("token") or [""])[0]
        if _req_tok != _rec_token_env:
            return _j(sr, {"ok": False, "error": "Invalid token"}, 403)
        try:
            import hashlib as _rhl, secrets as _sec
            conn = sv().get_db()
            _new_pw = _sec.token_urlsafe(16)
            new_hash = _rhl.sha256(_new_pw.encode()).hexdigest()
            conn.execute("UPDATE users SET password_hash=?, active=1 WHERE username='admin'", (new_hash,))
            conn.commit()
            conn.close()
            try:
                sv()._v564_login_attempts.pop("admin", None)
            except Exception:
                pass
            print("[RECOVERY] Admin password reset to a new random value. Check server logs for the one-time password.")
            print(f"[RECOVERY] One-time admin password: {_new_pw}")
            return _j(sr, {"ok": True, "message": "Admin password has been reset. The new password was printed to server logs only. Remove ADMIN_RECOVERY_TOKEN env var now."})
        except Exception as _re:
            return _j(sr, {"ok": False, "error": str(_re)}, 500)

    if method == "POST" and path == "/api/login":
        r = sv().api_login(_body(environ))
        if r.get("ok"):
            cookie = f"sbs_sid={r['sid']}; Path=/; HttpOnly; SameSite=Strict; Secure"
            return _j(sr, r, extra=[("Set-Cookie", cookie)])
        if r.get("mfa_required"):
            return _j(sr, r, 200)
        return _j(sr, r, 401)

    if method == "POST" and path == "/api/logout":
        sid = _sid(environ)
        if sid and sid in sv().sessions: del sv().sessions[sid]
        return _j(sr, {"ok":True})

    if method == "GET" and path == "/api/me":
        sess = sv().get_session(_sid(environ))
        if not sess: return _j(sr, {"error":"Not authenticated"}, 401)
        return _j(sr, {"user":sess})

    if method == "POST" and path == "/api/security/mfa/verify":
        return _j(sr, sv().api_verify_mfa(_body(environ)))

    # ── Auth check ────────────────────────────────────────────────────────────
    sess = sv().get_session(_sid(environ))

    # ── Auditor = read-only: every GET/report/export works, no POST mutations.
    if sess and sess.get("role") == "Auditor" and method == "POST" \
            and not path.startswith("/api/mfa/") \
            and path not in ("/api/change-password", "/api/security/mfa/verify", "/api/logout", "/api/client-error"):
        return _j(sr, {"ok": False, "error": "Auditor is a read-only role: viewing and exports are allowed, changes are not."}, 403)
    if not sess: return _j(sr, {"error":"Not authenticated"}, 401)

    # AI proxy: requires authentication (moved after auth check to prevent unauthenticated quota abuse).
    if method == "POST" and path == "/api/ai/chat":
        backend = sv()
        pre = getattr(backend, "api_chatbot_preflight", lambda session: {"ok": True})(sess)
        if not pre.get("ok"):
            return _j(sr, pre, 429)
        res = _ai_proxy(environ)
        try:
            backend.api_log_chatbot_usage(sess, {}, res)
        except Exception as exc:
            print("AI usage logging warning:", exc)
        return _j(sr, res)

    s = sv()
    try:
        if hasattr(s, "set_current_session"):
            s.set_current_session(sess)
    except Exception:
        pass
    p1 = lambda k: qs.get(k,[None])[0]

    # ── GET routes ────────────────────────────────────────────────────────────
    if method == "GET":

        simple = {
            "/api/dashboard":            lambda: s.api_dashboard(sess, p1("unit_code") or p1("unit") or p1("node")),
            "/api/cash-forecast":         lambda: s.api_cash_forecast(sess),
            "/api/inv/items":             lambda: s.api_inv_items(sess),
            "/api/inv/report":            lambda: s.api_inv_report(sess, p1("item_id")),
            "/api/inv/reorder":           lambda: s.api_inv_reorder(sess),
            "/api/ar/customers":          lambda: s.api_ar_customers(sess),
            "/api/ar/invoices":           lambda: s.api_ar_invoices(sess, p1("customer_id")),
            "/api/ar/aging":              lambda: s.api_ar_aging(sess),
            "/api/aged-receivables":      lambda: s.api_get_aged_receivables_v562(sess),
            "/api/aged-payables":         lambda: s.api_get_aged_payables_v562(sess),
            "/api/fx-revaluation":        lambda: s.api_fx_revaluation_state(sess, p1("period")),
            "/api/audit/verify":          lambda: s.api_audit_verify(sess),
            "/api/bank-recon/worklist":   lambda: s.api_bank_recon_worklist(sess, p1("bank_account_id"), p1("as_at")),
            "/api/consolidation/export":  lambda: s.api_consolidation_export(sess, p1("period_from"), p1("period_to")),
            "/api/ar/statement":          lambda: s.api_ar_statement(sess, p1("customer_id")),
            "/api/ar/invoice-lines":      lambda: s.api_ar_invoice_lines(sess, p1("invoice_id")),
            "/api/ar/recurring":          lambda: s.api_ar_recurring(sess),
            "/api/ap/vendors":            lambda: s.api_ap_vendors(sess),
            "/api/ap/bills":              lambda: s.api_ap_bills(sess, p1("vendor_id")),
            "/api/ap/bill-lines":         lambda: s.api_ap_bill_lines(sess, p1("bill_id")),
            "/api/ap/aging":              lambda: s.api_ap_aging(sess),
            "/api/ap/statement":          lambda: s.api_ap_statement(sess, p1("vendor_id")),
            "/api/ap/recurring":           lambda: s.api_ap_recurring(sess),
            "/api/working-capital":       lambda: s.api_working_capital(sess),
            "/api/rec-journals":          lambda: s.api_rec_journals(sess),
            "/api/rec-journal-lines":     lambda: s.api_rec_journal_lines(sess, p1("rec_id")),
            "/api/tax-schedules":         lambda: s.api_tax_schedules(sess, p1("period")),
            "/api/ipsas24":               lambda: s.api_ipsas24(sess, p1("fy")),
            "/api/ppe-schedule":          lambda: s.api_ppe_schedule(sess, p1("fy")),
            "/api/cashbook":              lambda: s.api_cashbook(sess, p1("bank_account_id"), p1("date_from"), p1("date_to"), p1("net")),
            "/api/audit-pack":            lambda: s.api_audit_pack(sess, p1("fy")),
            "/api/payment-runs":          lambda: s.api_payment_runs(sess),
            "/api/flash-recipients":      lambda: s.api_get_flash_recipients(sess),
            "/api/petty-cash2":           lambda: s.api_pc2_state(sess),
            "/api/petty-cash2/ledger":    lambda: s.api_pc2_ledger(sess, p1("float_id")),
            "/api/dunning-preview":       lambda: s.api_dunning_preview(sess),
            "/api/three-way-match":       lambda: s.api_three_way_match(sess),
            "/api/finance-overview":      lambda: s.api_finance_overview(sess),
            "/api/unbudgeted-spend":      lambda: s.api_unbudgeted_spend(sess),
            "/api/trends":                lambda: s.api_trends(sess, p1("months")),
            "/api/flash-pack":            lambda: s.api_flash_pack(sess, p1("period")),
            "/api/projects":             lambda: s.api_get_projects(sess),
            "/api/budgets":              lambda: s.api_get_budgets(sess, p1("project_id")),
            "/api/invoices":             lambda: s.api_get_invoices(sess, p1("status"), p1("project_id")),
            "/api/commitments":          lambda: s.api_get_commitments(sess, p1("project_id")),
            "/api/actuals":              lambda: s.api_get_actuals(sess, p1("project_id")),
            "/api/actuals/lines":        lambda: s.api_get_actual_lines(sess, p1("id") or p1("actual_id")),
            "/api/coa":                  lambda: s.api_get_coa(sess),
            "/api/coa/reset":            lambda: s.api_reset_coa(sess),
            "/api/coa/ucc-status":       lambda: s.api_coa_ucc_status(sess),
            "/api/coa/expense-accounts": lambda: s.api_get_expense_coa(sess, p1("q")),
            "/api/coa/revenue-accounts": lambda: s.api_get_revenue_coa(sess, p1("q")),
            "/api/coa/bank-accounts":    lambda: s.api_get_bank_coa(sess, p1("q")),
            "/api/departments":          lambda: s.api_get_departments(sess),
            "/api/units":                lambda: s.api_get_departments(sess),  # compatibility alias
            "/api/budget-periods":       lambda: s.api_get_budget_periods(sess),
            "/api/quarterly-budgets":    lambda: s.api_get_quarterly_budgets(sess, p1('dept_code') or None, p1('academic_year') or None),
            "/api/quarterly-performance":lambda: s.api_quarterly_performance(sess, p1('dept_code') or None, p1('academic_year') or None, p1('quarter') or None),
            "/api/budget-uploads":       lambda: s.api_get_budget_uploads(sess),
            "/api/dept-summary":         lambda: s.api_dept_summary(sess),
            "/api/unit-summary":         lambda: s.api_dept_summary(sess),  # compatibility alias
            "/api/dept-allocations":     lambda: s.api_get_dept_allocations(sess, p1('dept_code') or None),
            "/api/unit-allocations":     lambda: s.api_get_dept_allocations(sess, p1('dept_code') or p1('unit_code') or None),  # compatibility alias
            "/api/exchange-rates":       lambda: s.api_get_fx_rates(sess),
            "/api/fx-rates":             lambda: s.api_get_fx_rates(sess),  # alias used by vFX()
            "/api/audit":                lambda: s.api_get_audit(sess),
            "/api/bank-accounts":        lambda: s.api_get_bank_accounts(sess),
            "/api/fund-receipts":        lambda: s.api_get_fund_receipts(sess, p1("project_id")),
            "/api/bank-reconciliations": lambda: s.api_get_bank_reconciliations(sess, p1("account_id")),
            "/api/accounting-periods":   lambda: s.api_get_accounting_periods(sess),
            "/api/users":                lambda: s.api_get_users(sess),
            "/api/org-units":            lambda: s.api_org_units(sess),
            "/api/notifications":        lambda: s.api_get_notifications(sess),
            "/api/withholding-payables": lambda: s.api_get_withholding_payables(sess, p1("status")),
            "/api/control-cockpit":       lambda: s.api_control_cockpit(sess),
            "/api/financial-integrity":   lambda: s.api_control_cockpit(sess),
            "/api/dashboard/charts":     lambda: s.api_get_dashboard_charts(sess),
            "/api/fuel-vehicles":       lambda: s.api_get_fuel_vehicles(sess),
            "/api/fuel-coupons/return-source": lambda: s.api_fuel_return_sources(sess),
            "/api/fuel-stock-health": lambda: s.api_fuel_stock_health(sess),
            "/api/fuel-coupons":         lambda: s.api_fuel(sess),
            "/api/fuel-coupons/detail":  lambda: s.api_fuel(sess),
            "/api/fuel-coupons/tracker": lambda: s.api_fuel_tracker(sess),
            "/api/payroll/employees":    lambda: s.api_get_employees(sess),
            "/api/payroll/months":       lambda: s.api_get_payroll_months(sess),
            "/api/payroll/settings":     lambda: s.api_get_payroll_settings(sess),
            "/api/vendors":              lambda: s.api_get_vendors(sess),
            "/api/attachments":          lambda: s.api_get_attachments(sess, p1("module"), p1("record_id")),
            "/api/approval-rules":       lambda: s.api_get_approval_rules(sess),
            "/api/document-watermark":   lambda: s.api_doc_watermark(sess),
            "/api/approvals":            lambda: s.api_get_approvals(sess, p1("status")),
            "/api/procure-to-pay":       lambda: s.api_get_procure_to_pay(sess),
            "/api/project-closeout":     lambda: s.api_project_closeout(sess, p1("project_id")),
            "/api/backup/info":          lambda: s.api_backup_info(sess),
            "/api/backup/offsite-test":  lambda: s.api_offsite_backup_test(sess),
            "/api/dual-control":         lambda: s.api_get_dual_control(sess),
            "/api/reversals-register":   lambda: s.api_reversals_register(sess),
            "/api/permissions":           lambda: s.api_get_permissions_v562(sess),
            "/api/security/mfa":          lambda: s.api_get_mfa_settings(sess),
            "/api/email/status":          lambda: s.api_email_status(sess),
            "/api/backup/schedule":       lambda: s.api_get_backup_schedule(sess),
            "/api/report-designer":       lambda: s.api_get_report_designer(sess),
            "/api/assets":                lambda: s.api_get_assets(sess, p1("status")),
            "/api/import/jobs":           lambda: s.api_get_import_jobs(sess),
            "/api/bank-reconciliation-statement": lambda: s.api_get_bank_recon_statement(sess, p1("account_id")),
            "/api/postgres/readiness":    lambda: s.api_postgres_readiness(sess),
            "/api/system-audit":          lambda: s.api_system_audit_v556(sess),
            "/api/quality-seal":          lambda: s.api_quality_seal(sess),
            "/api/go-live-readiness":    lambda: s.api_go_live_readiness(sess),
            "/api/system-health":        lambda: s.api_system_health(sess),
            "/api/reference-preview":    lambda: s.api_reference_preview(sess),
            "/api/ai-governance":        lambda: s.api_ai_governance(sess),
            "/api/launch-lock":          lambda: s.api_launch_lock(sess),
            "/api/migration-templates":  lambda: s.api_migration_templates(sess),
            "/api/first-time-setup":     lambda: s.api_first_time_setup(sess),
            "/api/support-maintenance":  lambda: s.api_support_maintenance(sess),
            "/api/acceptance-testing":   lambda: s.api_acceptance_testing(sess),
            "/api/takeoff-wizard":       lambda: s.api_takeoff_wizard(sess),
            "/api/stability-audit":      lambda: s.api_stability_audit(sess),
            "/api/final-system-audit":   lambda: s.api_final_system_audit(sess),
            "/api/pilot-feedback":       lambda: s.api_get_pilot_feedback(sess),
            "/api/deleted-items":        lambda: s.api_deleted_items(sess, p1("module")),
            "/api/budget-upload-validation-reports": lambda: s.api_budget_upload_validation_reports(sess),
            "/api/management-alerts":    lambda: s.api_management_alerts(sess),
            "/api/production-control":   lambda: s.api_production_control(sess),
            "/api/workflow-status":      lambda: s.api_workflow_status(sess),
            "/api/app-version":          lambda: s.api_app_version(sess),
            "/api/production-polish":    lambda: s.api_production_polish(sess),
            "/api/client-errors/recent": lambda: s.api_client_errors_recent(sess),
            "/api/system-assurance":     lambda: s.api_system_assurance(sess),
            "/api/database-migration-check": lambda: s.api_database_migration_check(sess),
            "/api/backup-restore-centre": lambda: s.api_backup_restore_centre(sess),
            "/api/tax-reconciliation": lambda: s.api_tax_reconciliation(sess, p1("date_from"), p1("date_to")),
            "/api/pv-analytics": lambda: s.api_pv_analytics(sess),
            "/api/pv/suggest-coa": lambda: s.api_pv_smart_coa_suggestions(sess, p1("payment_type"), p1("q")),
            "/api/workflow-compliance": lambda: s.api_workflow_compliance(sess),
            "/api/institutional-readiness": lambda: s.api_institutional_readiness(sess),
            "/api/management-reports": lambda: s.api_management_reports(sess),
            "/api/fuel-coupons/control-report": lambda: s.api_fuel_control_report(sess),
            "/api/deployment/status": lambda: s.api_deployment_status(sess),
            "/api/institutional-control-centre": lambda: s.api_institutional_control_centre(sess),
            "/api/opening-balance-wizard": lambda: s.api_opening_balance_wizard(sess),
            "/api/opening-balances/list": lambda: s.api_list_opening_journals(sess),
            "/api/ai/context": lambda: s.api_ai_context(sess),
            "/api/deployment-audit": lambda: s.api_final_system_audit(sess),
            "/api/approval-notification-centre": lambda: s.api_approval_notification_centre(sess),
            "/api/exception-audit-dashboard": lambda: s.api_exception_audit_dashboard(sess),
            "/api/user-manual": lambda: s.api_user_manual(sess),
            "/api/go-live-enforcement": lambda: s.api_go_live_enforcement(sess),
            # ── v5.3 feature expansion (GET) ──
            "/api/grant-utilisation":    lambda: s.api_grant_utilisation_v556(sess, p1("date_from") or p1("period_from"), p1("date_to") or p1("period_to"), p1("project_id"), p1("donor")),
            "/api/gra-remittance":       lambda: s.api_gra_remittance(sess, p1("quarter"), p1("year")),
            "/api/statutory-filings":    lambda: s.api_statutory_filings(sess, p1("month"), p1("year")),
            "/api/virements":            lambda: s.api_get_virements(sess),
            "/api/staff-advances":       lambda: s.api_get_staff_advances(sess),
            "/api/staff-advances/report":lambda: s.api_staff_advance_report_v558(sess),
            "/api/ssnit-schedule":       lambda: s.api_ssnit_schedule(sess, p1("month"), p1("year")),
            "/api/purchase-orders":      lambda: s.api_get_purchase_orders(sess),
            "/api/contracts":            lambda: s.api_get_contracts(sess),
            "/api/contracts/alerts":     lambda: s.api_contract_expiry_alerts(sess),
            "/api/depreciation-schedule":lambda: s.api_depreciation_schedule(sess),
            "/api/interunit-transfers":  lambda: s.api_get_interunit_transfers(sess),
            "/api/saved-reports":        lambda: s.api_get_saved_reports(sess),
            "/api/period-close":         lambda: s.api_period_close_checklist(sess, p1("period_code")),
            "/api/active-sessions":      lambda: s.api_get_active_sessions(sess),
            "/api/my-sessions":          lambda: s.api_my_sessions(sess),
            "/api/notification-log":     lambda: s.api_get_notification_log(sess),
            "/api/bulk-approve-queue":   lambda: s.api_get_bulk_approve_queue_v560(sess),
            "/api/notifications/settings": lambda: s.api_get_notification_settings(sess),
            "/api/trial-balance":        lambda: s.api_trial_balance_v556(sess, p1("period_from"), p1("period_to"), p1("unit_code") or p1("unit"), p1("project_id")),
            "/api/budget-variance":      lambda: s.api_budget_variance_v556(sess, p1("period_from"), p1("period_to"), p1("project_id"), p1("unit_code") or p1("unit")),
            "/api/budget-control":       lambda: s.api_budget_control(sess, p1("period_from"), p1("period_to"), p1("project_id"), p1("unit_code") or p1("unit")),
            "/api/cashflow":             lambda: s.api_cashflow_v556(sess, p1("date_from") or p1("period_from"), p1("date_to") or p1("period_to"), p1("project_id"), p1("unit") or p1("dept_code") or p1("unit_code")),
            "/api/sfp":                  lambda: s.api_sfp_v556(sess, p1("date_from") or p1("period_from"), p1("date_to") or p1("period_to"), p1("unit_code") or p1("unit"), p1("project_id")),
            "/api/changes-in-net-assets": lambda: s.api_changes_in_net_assets_v1(sess, p1("date_from") or p1("period_from"), p1("date_to") or p1("period_to"), p1("project_id"), p1("unit_code") or p1("unit")),
            "/api/income-expenditure":   lambda: s.api_income_expenditure_v556(sess, p1("date_from") or p1("period_from"), p1("date_to") or p1("period_to"), p1("unit_code") or p1("unit"), p1("project_id")),
            "/api/notes-to-accounts":    lambda: s.api_notes_to_accounts(sess, p1("as_at"), p1("date_from") or p1("period_from"), p1("date_to") or p1("period_to"), p1("unit_code") or p1("unit"), p1("project_id")),
            "/api/audit-log":            lambda: s.api_audit_log_viewer(sess, p1("page"), p1("filter_action"), p1("filter_user"), p1("date_from"), p1("date_to")),
            "/api/petty-cash":           lambda: s.api_petty_cash(sess),
            "/api/recurring-commitments":lambda: s.api_recurring_commitments(sess),
            "/api/vote-on-account":      lambda: s.api_vote_on_account(sess),
            "/api/notification-summary": lambda: s.api_notification_summary_v558(sess),
            "/api/ssnit-remittance-advice": lambda: s.api_ssnit_remittance_advice(sess, p1("month"), p1("year")),
            "/api/wht-certificate":      lambda: s.api_wht_certificate(sess, p1("actual_id")),
            "/api/pv-print-data":        lambda: s.api_pv_print_data(sess, p1("actual_id")),
            # ── v5.5 core financial (GET) ──
            "/api/journal-vouchers":     lambda: s.api_journal_vouchers_v557(sess, p1("period_from"), p1("period_to")),
            "/api/fixed-assets":         lambda: s.api_fixed_assets_v558d(sess),
            "/api/bank-reconciliation":  lambda: s.api_bank_reconciliation(sess, p1("account_id"), p1("period_month")),
            "/api/budget-revisions":     lambda: s.api_budget_revisions(sess, p1("budget_id")),
            "/api/approval-rules-v55":   lambda: s.api_approval_rules(sess),
            "/api/pending-approvals":    lambda: s.api_pending_approvals_v557b(sess),
            "/api/document-attachments": lambda: s.api_document_attachments(sess, p1("document_type"), p1("document_id")),
            "/api/year-end-status":      lambda: s.api_year_end_status(sess),
            # ── v5.5b compliance & operational (GET) ──
            "/api/vendors-v55":          lambda: s.api_vendor_register(sess, p1("vendor_type"), p1("search")),
            "/api/leave-requests":       lambda: s.api_leave_requests(sess, p1("status"), p1("employee_id")),
            "/api/cagd-mappings":        lambda: s.api_cagd_mappings(sess),
            "/api/donor-reports":        lambda: s.api_donor_reports(sess, p1("project_id")),
            "/api/project-closeouts":    lambda: s.api_project_closeouts(sess),
            "/api/dashboard-kpis-v55":   lambda: s.api_dashboard_kpis_v55(sess),
            "/api/comparative-report":   lambda: s.api_comparative_report(sess, p1("year1"), p1("year2"), p1("project_id")),
        }
        if path in simple:
            try:
                return _j(sr, simple[path]())
            except Exception as _hexc:
                import traceback as _tb, sys as _hsys
                print('GET handler error %s: %s' % (path, _hexc), file=_hsys.stderr)
                _tb.print_exc(file=_hsys.stderr)
                return _j(sr, {"ok": False, "error": "The request could not be completed. Please check your input and try again; if the problem persists, contact the system administrator."}, 500)

        # Advanced query (module in path)
        if path.startswith("/api/advanced-query/"):
            mod = path.split("/api/advanced-query/")[-1]
            return _j(sr, s.api_advanced_query(mod, sess, p1("date_from"), p1("date_to"), p1("project_code"), p1("category"), p1("min_amount")))

        if path == "/api/ppa-check":
            return _j(sr, s.api_ppa_check(sess, p1("amount")))

        # ── API Documentation ─
        if path == "/api/docs":
            rows_html = []
            def _row(m, pth, desc, role="Authenticated"):
                rows_html.append(f"<tr><td><span class='m m-{m.lower()}'>{m}</span></td><td><code>{pth}</code></td><td>{desc}</td><td>{role}</td></tr>")
            _row("GET", "/api/dashboard", "Dashboard KPI summary")
            _row("GET", "/api/grant-utilisation", "Grant utilisation by donor")
            _row("GET", "/api/gra-remittance", "GRA tax remittance schedules")
            _row("GET", "/api/virements", "Budget virement history")
            _row("GET", "/api/staff-advances", "List staff advances & imprest")
            _row("GET", "/api/ssnit-schedule", "SSNIT contribution schedule")
            _row("GET", "/api/purchase-orders", "Purchase requisitions, orders & GRNs")
            _row("GET", "/api/contracts", "Contract register with expiry tracking")
            _row("GET", "/api/depreciation-schedule", "Fixed asset depreciation preview")
            _row("GET", "/api/interunit-transfers", "Interunit transfers")
            _row("GET", "/api/saved-reports", "Saved report configurations")
            _row("GET", "/api/period-close", "Accounting period close checklist")
            _row("GET", "/api/active-sessions", "Active user sessions", "Admin")
            _row("GET", "/api/bulk-approve-queue", "Pending approvals queue")
            _row("GET", "/api/notifications/settings", "Email notification settings")
            _row("POST", "/api/bank-import", "Bank statement CSV import & auto-match")
            _row("POST", "/api/bulk-approve", "Bulk approve pending items", "Admin")
            _row("POST", "/api/period-close/signoff", "Sign off a period close", "Admin")
            _row("POST", "/api/sessions/force-logout", "Force-logout a session", "Admin")
            doc = ("<!doctype html><html><head><meta charset='utf-8'><title>UCC-FMS API Documentation</title>"
                   "<style>body{font-family:system-ui,Segoe UI,sans-serif;margin:0;background:#0f172a;color:#e2e8f0}"
                   "header{padding:28px 32px;background:#1e3a8a}h1{margin:0;font-size:22px}p{margin:6px 0 0;opacity:.8}"
                   "table{width:100%;border-collapse:collapse;margin:0}th,td{text-align:left;padding:10px 32px;border-bottom:1px solid #1e293b;font-size:13px}"
                   "th{background:#111827;text-transform:uppercase;font-size:11px;letter-spacing:.06em}code{background:#1e293b;padding:2px 6px;border-radius:5px;font-family:ui-monospace,monospace}"
                   ".m{font-weight:800;font-size:11px;padding:2px 7px;border-radius:5px}.m-get{background:#064e3b;color:#6ee7b7}.m-post{background:#7c2d12;color:#fdba74}</style></head>"
                   "<body><header><h1>UCC-FMS API Documentation</h1><p>Auto-generated endpoint reference. University of Cape Coast · UCC.</p></header>"
                   "<table><thead><tr><th>Method</th><th>Path</th><th>Description</th><th>Role</th></tr></thead><tbody>"
                   + "".join(rows_html) + "</tbody></table></body></html>")
            body = doc.encode()
            sr("200 OK", [("Content-Type", "text/html; charset=utf-8"), ("Content-Length", str(len(body)))])
            return [body]

        if path == "/api/pv-batch-template":
            return _csv(sr, s.api_pv_batch_template_csv(sess), "pv_batch_template.csv")
        if path.startswith("/api/migration-template/"):
            name = path.split("/")[-1].replace(".csv", "")
            return _csv(sr, s.api_migration_template_csv(name, sess), f"{name}_template.csv")
        if path == "/api/search":
            return _j(sr, s.api_get_search(sess, p1("q") or ""))
        if path == "/api/report":
            return _j(sr, s.api_get_report(sess, p1("type"), p1("project_id")))
        if path == "/api/jvs":
            f={k:p1(k) for k in("project_id","period","status","jv_type") if p1(k)}
            return _j(sr, s.api_get_jvs(sess, f or None))
        if path.startswith("/api/jvs/") and len(path)>9:
            return _j(sr, s.api_get_jv_detail(sess, path.split("/")[-1]))
        if path == "/api/general-ledger":
            f={k:p1(k) for k in("coa_id","coa_code","period","project_id","date_from","date_to") if p1(k)}
            return _j(sr, s.api_get_general_ledger(sess, f or None))
        if path == "/api/ledger-summary":
            return _j(sr, s.api_get_ledger_accounts_summary(sess, p1("period")))
        if path == "/api/report/fuel-coupons":
            return _j(sr, s.api_fuel_report(sess, p1("date_from"), p1("date_to"), p1("project_id")))
        if path == "/api/payroll/register":
            return _j(sr, s.api_get_payroll_register(sess, p1("month")))
        if path == "/api/payroll/schedules":
            m = p1("month")
            if hasattr(s, "api_get_statutory_schedules_safe"):
                return _j(sr, s.api_get_statutory_schedules_safe(sess, m))
            if m: return _j(sr, s.api_get_statutory_schedules(sess, m))
            months = s.api_get_payroll_months(sess) if hasattr(s, "api_get_payroll_months") else []
            if months: return _j(sr, s.api_get_statutory_schedules(sess, months[0].get("payroll_month")))
            return _j(sr, s.api_get_statutory_schedules(sess, __import__("datetime").datetime.now().strftime("%Y-%m")))
        if path == "/api/payroll/payslip":
            # IDOR guard: only Admin and Finance Officer may fetch any payslip.
            if sess.get("role") not in ("Admin", "Finance Officer"):
                return _j(sr, {"error": "Access denied"}, 403)
            return _j(sr, s.api_get_payslip(sess, p1("id")))
        if path == "/api/payroll/payslip/html":
            # IDOR guard: only Admin and Finance Officer may fetch any payslip.
            if sess.get("role") not in ("Admin", "Finance Officer"):
                return _j(sr, {"error": "Access denied"}, 403)
            rec = s.api_get_payslip(sess, p1("id"))
            if rec:
                body = s.payslip_html_single(rec, p1("photo")!="0").encode()
                sr("200 OK",[("Content-Type","text/html; charset=utf-8"),("Content-Length",str(len(body)))])
                return [body]
            return _j(sr, {"error":"Not found"}, 404)
        if path == "/api/payroll/payslips/all":
            recs = s.api_get_payroll_register(sess, p1("month"))
            body = "".join(
                s.payslip_html_single(r, p1("photo")!="0")+'<div style="page-break-after:always"></div>'
                for r in recs).encode()
            sr("200 OK",[("Content-Type","text/html; charset=utf-8"),("Content-Length",str(len(body)))])
            return [body]
        if path.startswith("/api/payroll/photo/"):
            return _j(sr, {"photo": s.api_get_photo_url(path.split("/")[-1])})
        if path == "/api/bog/rate":
            from datetime import date as _d
            return _j(sr, s.api_bog_rate_for_date(p1("currency") or "USD", p1("date") or str(_d.today()), sess))
        if path == "/api/bog/fetch-rates":
            return _j(sr, s.api_bog_fetch_rates(sess, p1("date_from"), p1("date_to")))
        if path == "/api/bank-statement":
            return _j(sr, s.api_get_bank_statement(sess, p1("account_id"), p1("date_from"), p1("date_to")))



        if path == "/api/invoices/html":
            inv_id = p1("id") or ""
            body = s.api_invoice_html(inv_id, sess).encode("utf-8")
            sr("200 OK", [("Content-Type","text/html; charset=utf-8"),("Content-Length",str(len(body))),("Cache-Control","no-store")])
            return [body]
        if path == "/api/export/invoices":
            return _csv(sr, s.api_export_invoices_csv(sess), "invoices.csv")
        if path == "/api/import/template":
            txt = s.api_import_template(sess, p1("module") or "vendors")
            return _csv(sr, txt, f"{p1('module') or 'vendors'}-template.csv")
        if path == "/api/postgres/export":
            fn, body, e = s.api_postgres_export(sess)
            if e: return _j(sr, {"ok":False,"error":e}, 403)
            return _bin(sr, body, fn, "application/sql")
        if path == "/api/backup/download":
            fn, body, e = s.api_download_backup(sess)
            if e: return _j(sr, {"ok":False,"error":e}, 403)
            return _bin(sr, body, fn, "application/octet-stream")
        if path == "/api/export/system-assurance":
            return _csv(sr, s.api_export_system_assurance_csv(sess), "system_assurance.csv")
        if path == "/api/export/production-polish-report":
            return _csv(sr, s.api_export_production_polish_csv(sess), "production_polish_report.csv")
        if path == "/api/export/workflow-status":
            return _csv(sr, s.api_export_workflow_csv(sess), "workflow_status.csv")
        if path == "/api/export/deleted-items":
            return _csv(sr, s.api_export_deleted_items_csv(sess), "deleted_items.csv")
        if path == "/api/export/budget-validation":
            return _csv(sr, s.api_export_budget_validation_csv(sess), "budget_validation.csv")
        if path == "/api/export/audit-trail":
            return _csv(sr, s.api_export_audit_csv(sess, p1("date_from"), p1("date_to"), p1("user"), p1("filter_action")), "audit_trail.csv")
        if path == "/api/export/reversals":
            return _csv(sr, s.api_export_reversals_csv(sess), "reversals_register.csv")
        if path == "/api/export/workflow-compliance":
            return _csv(sr, s.api_export_workflow_compliance_csv(sess), "workflow_compliance.csv")
        if path == "/api/export/fuel-control-report":
            return _csv(sr, s.api_export_fuel_control_csv(sess), "fuel_control_report.csv")

        if path == "/api/export":
            return _j(sr, {"ok": False, "error": "Specify an export route such as /api/export/projects-full, /api/export/payroll or /api/export/workflow-compliance."}, 400)

        # ── CSV / Excel exports ───────────────────────────────────────────────
        if path == "/api/coa/delete":
            coa_id = p1("id")
            if not coa_id: return _j(sr, {"error":"id required"}, 400)
            return _j(sr, s.api_delete_coa(coa_id, sess))
        if path.startswith("/api/export/"):
            table = path.split("/")[-1]
            if table == "audit-trail":
                return _csv(sr, s.api_export_audit_csv(sess, p1("date_from"), p1("date_to"), p1("user"), p1("filter_action")), "audit_trail.csv")
            if table == "payroll":
                month=p1("month"); recs=s.api_get_payroll_register(sess, month)
                hdrs,rows=s.get_payroll_export_rows(recs)
                return _csv(sr, s.export_csv_generic(hdrs,rows,f"UCC PAYROLL - {month or 'ALL'}"), f"payroll_{month or 'all'}.csv")
            elif table == "actuals-full":
                rows=s.api_get_actuals(sess, p1("project_id"))
                hdrs=["Code","Project","Date","Payee","Description","Currency","Amt FCY","FX Rate","Amt GHS",
                      "Has VAT","VAT","Has WHVAT","WHVAT","WHT Type","WHT Rate","WHT Amt","Has UCF","UCF Amt",
                      "FX GL Type","FX GL","Payment Method","Cheque No.","Transfer/MoMo Ref.","Bank Account","Posted"]
                data_rows=[[r.get(k,"") for k in ["actual_code","project_code","expense_date","payee",
                    "description","currency","amount_fcy","pay_fx_rate","amount_ghs"]]+
                    ["Y" if r.get("has_vat") else "N", r.get("vat_amount",0),
                     "Y" if r.get("has_whvat") else "N", r.get("whvat_amount",0),
                     r.get("wht_type","-"), r.get("wht_rate",0), r.get("wht_amount",0),
                     "Y" if r.get("has_ucf") else "N", r.get("ucf_amount",0),
                     r.get("fx_gl_type","-"), r.get("fx_gl_ghs",0), r.get("payment_method","") or "Bank Transfer",
                     r.get("cheque_no","") or (r.get("payment_reference","") if r.get("payment_method")=="Cheque" else ""),
                     r.get("transfer_ref","") or (r.get("payment_reference","") if r.get("payment_method")!="Cheque" else ""),
                     r.get("bank_account_id",""), "Yes" if r.get("is_posted") else "No"] for r in rows]
                return _csv(sr, s.export_csv_generic(hdrs,data_rows,"UCC ACTUALS"), "actuals.csv")
            elif table == "projects-full":
                rows=s.api_get_projects(sess)
                hdrs=["Code","Title","Donor","Division","Start","End","Currency",
                      "Budget FCY","FX Rate","Budget GHS","Committed","Actuals","Available","Util%","Status","PI"]
                data_rows=[]
                for r in rows:
                    comm=r.get("committed_ghs",0) or 0; act=r.get("actual_ghs",0) or 0
                    avail=(r.get("budget_ghs",0) or 0)-comm-act
                    util=round((comm+act)/(r.get("budget_ghs",1) or 1)*100,1)
                    data_rows.append([r.get("project_code",""),r.get("title",""),r.get("donor",""),
                        r.get("division",""),r.get("start_date",""),r.get("end_date",""),
                        r.get("currency",""),r.get("budget_fcy",0),r.get("fx_rate",0),
                        r.get("budget_ghs",0),comm,act,avail,f"{util}%",r.get("status",""),r.get("pi_name","")])
                return _csv(sr, s.export_csv_generic(hdrs,data_rows,"UCC GRANTS"), "projects.csv")
            elif table == "commitments":
                rows=s.api_get_commitments(sess)
                hdrs=["Code","Project","Date","Vendor","Description","Currency","Amount FCY","FX","Amount GHS","Reference","Procurement Method","Due Date","Delivery Status","Status"]
                data_rows=[[r.get(k,"") for k in ["commit_code","project_code","commit_date","vendor",
                    "description","currency","amount_fcy","fx_rate","amount_ghs","reference","procurement_method","due_date","delivery_status","status"]] for r in rows]
                return _csv(sr, s.export_csv_generic(hdrs,data_rows,"UCC COMMITMENTS"), "commitments.csv")
            elif table == "budgets":
                rows=s.api_get_budgets(sess)
                hdrs=["Code","Project","COA Code","Account","Currency","Budget FCY","FX Rate","Budget GHS",
                      "Committed GHS","Actual GHS","Available GHS","Notes"]
                data_rows=[[r.get(k,"") for k in ["budget_code","project_code","coa_code","account_name",
                    "currency","budget_fcy","fx_rate","budget_ghs","committed_ghs","actual_ghs"]] +
                    [round((r.get("budget_ghs",0) or 0)-(r.get("committed_ghs",0) or 0)-(r.get("actual_ghs",0) or 0),2),
                     r.get("notes","")] for r in rows]
                return _csv(sr, s.export_csv_generic(hdrs,data_rows,"UCC BUDGETS"), "budgets.csv")
            elif table == "fuel-coupons":
                d=s.api_fuel(sess); rows=d.get("movements",[])
                hdrs=["Date","Type","Batch No","Project","From","To","Denomination","Qty","Face Value",
                      "Officer","Vehicle","Purpose","Receipt Submitted","Notes"]
                data_rows=[[r.get("movement_date",""),r.get("movement_type",""),r.get("batch_number",""),
                    r.get("project_code",""),r.get("from_entity",""),r.get("to_entity",""),
                    r.get("denomination",0),r.get("quantity",0),r.get("face_value",0),
                    r.get("officer",""),r.get("vehicle_number",""),r.get("purpose",""),
                    "Yes" if r.get("receipt_submitted") else "No",r.get("notes","")] for r in rows]
                return _csv(sr, s.export_csv_generic(hdrs,data_rows,"UCC FUEL COUPONS"), "fuel_coupons.csv")
            else:
                csv_data=s.api_export_csv(table, sess)
                if csv_data is not None: return _csv(sr, csv_data, f"{table}.csv")
                return _j(sr, {"error":f"Unknown export: {table}"}, 400)

        return _j(sr, {"error":"Not found"}, 404)

    # ── POST routes ───────────────────────────────────────────────────────────
    if method == "POST":
        data = _body(environ)
        routes = {
            "/api/projects":                     lambda: s.api_save_project(data, sess),
            "/api/inv/items":                    lambda: s.api_inv_save_item(data, sess),
            "/api/inv/receipt":                  lambda: s.api_inv_receipt(data, sess),
            "/api/inv/issue":                    lambda: s.api_inv_issue(data, sess),
            "/api/inv/adjust":                    lambda: s.api_inv_adjust(data, sess),
            "/api/inv/reorder-po":               lambda: s.api_inv_create_reorder_po(data, sess),
            "/api/inv/import":                   lambda: s.api_inv_import(data, sess),
            "/api/ar/customers":                 lambda: s.api_ar_save_customer(data, sess),
            "/api/ar/invoices":                  lambda: s.api_ar_save_invoice(data, sess),
            "/api/ar/invoices/post":             lambda: s.api_ar_post_invoice(data, sess),
            "/api/ar/receipt":                   lambda: s.api_ar_receipt(data, sess),
            "/api/ar/credit-note":               lambda: s.api_ar_credit_note(data, sess),
            "/api/ar/recurring":                 lambda: s.api_ar_save_recurring(data, sess),
            "/api/ar/recurring/toggle":          lambda: s.api_ar_recurring_toggle(data, sess),
            "/api/ar/recurring/generate":        lambda: s.api_ar_recurring_generate(data, sess),
            "/api/ap/bills":                     lambda: s.api_ap_save_bill(data, sess),
            "/api/ap/bills/post":                lambda: s.api_ap_post_bill(data, sess),
            "/api/ap/payment":                   lambda: s.api_ap_payment(data, sess),
            "/api/ap/debit-note":                lambda: s.api_ap_debit_note(data, sess),
            "/api/ap/batch-pay":                 lambda: s.api_ap_batch_pay(data, sess),
            "/api/ar/batch-receipt":             lambda: s.api_ar_batch_receipt(data, sess),
            "/api/ar/import-invoices":           lambda: s.api_ar_import_invoices(data, sess),
            "/api/ap/import-bills":              lambda: s.api_ap_import_bills(data, sess),
            "/api/export-file":                  lambda: s.api_export_file(data, sess),
            "/api/email-statement":              lambda: s.api_email_statement(data, sess),
            "/api/rec-journals":                 lambda: s.api_save_rec_journal(data, sess),
            "/api/rec-journals/toggle":          lambda: s.api_rec_journal_toggle(data, sess),
            "/api/rec-journals/generate":        lambda: s.api_rec_journal_generate(data, sess),
            "/api/dunning-run":                  lambda: s.api_dunning_run(data, sess),
            "/api/po-to-bill":                   lambda: s.api_po_to_bill(data, sess),
            "/api/ap/recurring":                 lambda: s.api_ap_save_recurring(data, sess),
            "/api/ap/recurring/toggle":          lambda: s.api_ap_recurring_toggle(data, sess),
            "/api/ap/recurring/generate":        lambda: s.api_ap_recurring_generate(data, sess),
            "/api/budgets":                      lambda: s.api_save_budget(data, sess),
            "/api/invoices":                     lambda: s.api_save_invoice(data, sess),
            "/api/commitments":                  lambda: s.api_save_commitment(data, sess),
            "/api/actuals":                      lambda: s.api_save_actual(data, sess),
            "/api/actuals/multiline":            lambda: s.api_save_multiline_actual(data, sess),
            "/api/actuals/update":               lambda: s.api_save_actual(data, sess),
            "/api/actuals/post":                 lambda: s.api_post_actual(data, sess),
            "/api/actuals/tag-budget":           lambda: s.api_tag_actual_budget(data, sess),
            "/api/budgets/renumber-codes":       lambda: s.api_renumber_budget_codes(data, sess),
            "/api/withholding-payables/settle":  lambda: s.api_settle_withholding_payable(data, sess),
            "/api/budgets/vire":                 lambda: s.api_virement(data, sess),
            "/api/coa":                          lambda: s.api_save_coa(data, sess),
            "/api/departments":                    lambda: s.api_save_department(data, sess),
            "/api/units":                          lambda: s.api_save_department(data, sess),  # compatibility alias
            "/api/org-units":                      lambda: s.api_save_org_unit(data, sess),
            "/api/auto-coa":                       lambda: s.api_auto_assign_coa(data, sess),
            "/api/bulk-auto-coa":                  lambda: s.api_bulk_auto_coa(data, sess),
            "/api/quarterly-budgets":               lambda: s.api_save_quarterly_budget(data, sess),
            "/api/budget-upload":                   lambda: s.api_upload_budget(data, sess),
            "/api/employee-upload":                lambda: s.api_upload_employees(data, sess),
            "/api/annual-budget-upload":           lambda: s.api_upload_annual_budgets(data, sess),
            "/api/dept-allocations":               lambda: s.api_save_dept_allocation(data, sess),
            "/api/unit-allocations":               lambda: s.api_save_dept_allocation(data, sess),  # compatibility alias
            "/api/jvs":                          lambda: s.api_save_jv(data, sess),
            "/api/jvs/workflow":                 lambda: s.api_jv_workflow(data, sess),
            "/api/jvs/auto-generate":            lambda: s.api_auto_generate_jv(data, sess),
            "/api/accounting-periods":           lambda: s.api_manage_period(data, sess),
            "/api/fx-rates":                     lambda: s.api_save_fx_rate(data, sess),
            "/api/fuel-vehicles":                 lambda: s.api_save_fuel_vehicle(data, sess),
            "/api/fuel-coupons/batch":           lambda: s.api_save_fc_batch(data, sess),
            "/api/fuel-coupons/batch/update":    lambda: s.api_update_fc_batch(data, sess),
            "/api/fuel-coupons/movement":        lambda: s.api_save_fc_movement(data, sess),
            "/api/fuel-coupons/reorder-level":   lambda: s.api_set_fuel_reorder_level(data, sess),
            "/api/fuel-coupons/receipt":         lambda: s.api_update_fc_receipt(data, sess),
            "/api/fuel-coupons/movement/update": lambda: s.api_update_fc_movement(data, sess),
            "/api/change-password":              lambda: s.api_change_password(data, sess),
            "/api/mfa/totp-setup":               lambda: s.api_mfa_totp_setup(data, sess),
            "/api/mfa/totp-confirm":             lambda: s.api_mfa_totp_confirm(data, sess),
            "/api/bank-accounts":                lambda: s.api_save_bank_account(data, sess),
            "/api/fund-receipts":                lambda: s.api_save_fund_receipt(data, sess),
            "/api/bank-reconciliations":         lambda: s.api_save_bank_reconciliation_v563b(data, sess),
            "/api/payroll/employees":            lambda: s.api_save_employee(data, sess),
            "/api/payroll/run":                  lambda: s.api_run_payroll(data, sess),
            "/api/payroll/approve":              lambda: s.api_approve_payroll(data, sess),
            "/api/payroll/reverse":              lambda: s.api_reverse_payroll(data, sess),
            "/api/payroll/setting":              lambda: s.api_get_payroll_setting_update(data, sess),
            "/api/bog/fetch-rates":              lambda: s.api_bog_fetch_rates(sess),
            "/api/vendors":                       lambda: s.api_save_vendor(sess, data),
            "/api/attachments":                   lambda: s.api_save_attachment(data, sess),
            "/api/attachments/delete":            lambda: s.api_delete_attachment(data, sess),
            "/api/approval-rules":                lambda: s.api_save_approval_rule(data, sess),
            "/api/document-watermark":             lambda: s.api_doc_watermark(sess, (data or {}).get("value")),
            "/api/approvals/submit":              lambda: s.api_submit_approval(data, sess),
            "/api/approvals/action":              lambda: s.api_approval_action(data, sess),
            "/api/dual-control":                  lambda: s.api_set_dual_control(data, sess),
            "/api/ap/payment-run-file":           lambda: s.api_payment_run_file(data, sess),
            "/api/assets/revalue":                lambda: s.api_asset_revalue(data, sess),
            "/api/journals/redate":               lambda: s.api_redate_reversal(data, sess),
            "/api/petty-cash2/float":             lambda: s.api_pc2_setup_float(data, sess),
            "/api/petty-cash2/voucher":           lambda: s.api_pc2_voucher(data, sess),
            "/api/petty-cash2/voucher/void":      lambda: s.api_pc2_void_voucher(data, sess),
            "/api/petty-cash2/replenish":         lambda: s.api_pc2_replenish(data, sess),
            "/api/petty-cash2/reconcile":         lambda: s.api_pc2_reconcile(data, sess),
            "/api/petty-cash2/float/edit":        lambda: s.api_pc2_edit_float(data, sess),
            "/api/petty-cash2/float/close":       lambda: s.api_pc2_close_float(data, sess),
            "/api/petty-cash2/voucher/edit":      lambda: s.api_pc2_edit_voucher(data, sess),
            "/api/fx-revaluation":                lambda: s.api_fx_revaluation_run(data, sess),
            "/api/fx-revaluation/reverse":        lambda: s.api_fx_revaluation_reverse(data, sess),
            "/api/bank-recon/clear":              lambda: s.api_bank_recon_clear(data, sess),
            "/api/flash-recipients":              lambda: s.api_set_flash_recipients(data, sess),
            "/api/flash-email":                   lambda: s.api_send_flash_email(sess, (data or {}).get("period")),
            "/api/backup/create":                 lambda: s.api_create_backup(sess, data.get("notes","Manual backup")),
            "/api/backup/restore":                lambda: s.api_restore_backup(data, sess),
            "/api/permissions":                  lambda: s.api_save_permissions(data, sess),
            "/api/security/mfa":                 lambda: s.api_save_mfa_settings(data, sess),
            "/api/security/mfa/verify":          lambda: s.api_verify_mfa(data),
            "/api/email/test":                   lambda: s.api_send_test_email(data, sess),
            "/api/backup/schedule":              lambda: s.api_save_backup_schedule(data, sess),
            "/api/report-designer":              lambda: s.api_save_report_designer_v557(data, sess),
            "/api/assets":                       lambda: s.api_save_asset(data, sess),
            "/api/assets/dispose":               lambda: s.api_asset_dispose(data, sess),
            "/api/assets/maintenance":           lambda: s.api_save_asset_maintenance(data, sess) if hasattr(s, "api_save_asset_maintenance") else {"ok":False,"error":"Asset maintenance module unavailable"},
            "/api/import/csv":                   lambda: s.api_import_csv(data, sess),
            "/api/util/parse-rows":        lambda: s.api_parse_upload_rows(data, sess),
            "/api/bank-reconciliation-statement": lambda: s.api_save_bank_recon_statement(data, sess),
            "/api/demo-data/load":              lambda: s.api_load_sample_data(sess),
            "/api/demo-data/reset":             lambda: s.api_reset_demo_data(sess),
            "/api/ai-governance":                lambda: s.api_save_ai_governance(data, sess),
            "/api/launch-lock":                  lambda: s.api_set_launch_lock(data, sess),
            "/api/acceptance-testing/run":       lambda: s.api_run_acceptance_test(data, sess),
            "/api/pilot-feedback":              lambda: s.api_save_pilot_feedback(data, sess),
            "/api/client-error":                lambda: s.api_log_client_error(data, sess),
            "/api/client-errors/clear":          lambda: s.api_clear_client_errors(data, sess),
            "/api/deleted-items/restore":       lambda: s.api_restore_deleted_item(data, sess),
            "/api/pv-preview":                 lambda: s.api_pv_preview(data, sess),
            "/api/actuals/batch":              lambda: s.api_pv_batch_upload(data, sess),
            "/api/pv-batch":                  lambda: s.api_pv_batch_upload(data, sess),
            "/api/system-assurance/run":     lambda: s.api_run_system_assurance(data, sess),
            "/api/fuel-coupons/batch/post":      lambda: s.api_post_fuel_procurement_batch(data, sess),
            "/api/fuel-coupons/batch/reverse":   lambda: s.api_reverse_fuel_procurement_batch(data, sess),
            "/api/fuel-coupons/movement/post":   lambda: s.api_post_fuel_movement(data, sess),
            "/api/fuel-coupons/movement/reverse": lambda: s.api_reverse_fuel_movement(data, sess),
            "/api/budget-control/check":         lambda: s.api_budget_control_check(data, sess),
            "/api/deployment/reset-clean":       lambda: s.api_reset_for_deployment(data, sess),
            "/api/opening-balances/post":       lambda: s.api_post_opening_balances(data, sess),
            "/api/opening-balances/reverse":    lambda: s.api_reverse_opening_journal(data, sess),
            "/api/ledger/reset-zero":           lambda: s.api_reset_ledger_zero(data, sess),
            "/api/go-live-enforcement/checklist": lambda: s.api_update_go_live_checklist(data, sess),
            "/api/go-live-enforcement/mode":      lambda: s.api_set_go_live_mode(data, sess),
            "/api/go-live-enforcement/signoff":   lambda: s.api_go_live_signoff(data, sess),
            # ── v5.3 feature expansion (POST) ──
            "/api/staff-advances":               lambda: s.api_save_staff_advance_v563b(data, sess),
            "/api/staff-advances/retire":        lambda: s.api_retire_advance(data, sess),
            "/api/purchase-orders":              lambda: s.api_save_purchase_order(data, sess),
            "/api/purchase-orders/approve":      lambda: s.api_approve_po_v54(data, sess),
            "/api/grns":                         lambda: s.api_save_grn(data, sess),
            "/api/contracts":                    lambda: s.api_save_contract(data, sess),
            "/api/depreciation/run":             lambda: s.api_run_depreciation(data, sess),
            "/api/interunit-transfers":          lambda: s.api_save_interunit_transfer(data, sess),
            "/api/interunit-transfers/approve":  lambda: s.api_approve_interunit_transfer(data, sess),
            "/api/saved-reports":                lambda: s.api_save_report_config(data, sess),
            "/api/saved-reports/delete":         lambda: s.api_delete_saved_report(data, sess),
            "/api/period-close/signoff":         lambda: s.api_sign_off_period_close(data, sess),
            "/api/sessions/force-logout":        lambda: s.api_force_logout_session(data, sess),
            "/api/bank-import":                  lambda: s.api_bank_import(data, sess),
            "/api/bulk-approve":                 lambda: s.api_bulk_approve_v560(data, sess),
            "/api/notifications/settings":       lambda: s.api_save_notification_settings(data, sess),
            "/api/notifications/test":           lambda: s.api_send_test_notification(data, sess),
            "/api/petty-cash":           lambda: s.api_save_petty_cash(data, sess),
            "/api/recurring-commitments":lambda: s.api_save_recurring(data, sess),
            "/api/recurring-commitments/trigger": lambda: s.api_trigger_recurring(sess),
            "/api/vote-on-account":      lambda: s.api_save_vote_on_account(data, sess),
            # ── v5.5 core financial (POST) ──
            "/api/journal-vouchers":               lambda: s.api_save_journal_voucher_v557(sess, data),
            "/api/journal-vouchers/post":          lambda: s.api_post_journal_voucher(sess, data.get("jv_id")),
            "/api/journal-vouchers/approve":       lambda: s.api_approve_journal_voucher(sess, data.get("jv_id")),
            "/api/fixed-assets":                   lambda: s.api_save_fixed_asset(sess, data),
            "/api/fixed-assets/depreciate":        lambda: s.api_run_asset_depreciation(sess),
            "/api/fixed-assets/dispose":           lambda: s.api_dispose_asset(sess, data),
            "/api/bank-reconciliation/item":       lambda: s.api_save_recon_item(sess, data),
            "/api/bank-reconciliation/signoff":    lambda: s.api_signoff_recon(sess, data.get("recon_id")),
            "/api/bank-reconciliation/balances":   lambda: s.api_update_recon_balances(sess, data.get("recon_id")),
            "/api/budget-revisions":               lambda: s.api_save_budget_revision(sess, data),
            "/api/approval-rules-v55":             lambda: s.api_save_approval_rule(sess, data),
            "/api/approvals/process":              lambda: s.api_process_approval(sess, data),
            "/api/document-attachments":           lambda: s.api_save_attachment(sess, data),
            "/api/document-attachments/delete":    lambda: s.api_delete_attachment(sess, data.get("attachment_id")),
            "/api/document-attachments/get":       lambda: s.api_get_attachment(sess, data.get("attachment_id")),
            "/api/year-end-close":                 lambda: s.api_year_end_close(sess, data),
            # ── v5.5b compliance & operational (POST) ──
            "/api/vendors-v55":                    lambda: s.api_save_vendor(sess, data),
            "/api/vendors-v55/delete":             lambda: s.api_delete_vendor(sess, data.get("vendor_id")),
            "/api/compute-vat":                    lambda: s.api_compute_vat(sess, data),
            "/api/leave-requests":                 lambda: s.api_save_leave_request(sess, data),
            "/api/leave-requests/action":          lambda: s.api_action_leave_request(sess, data),
            "/api/cagd-mappings":                  lambda: s.api_save_cagd_mapping(sess, data),
            "/api/donor-reports":                  lambda: s.api_save_donor_report(sess, data),
            "/api/donor-reports/submit":           lambda: s.api_submit_donor_report(sess, data.get("report_id")),
            "/api/project-closeouts":              lambda: s.api_initiate_project_closeout(sess, data),
        }
        if path == "/api/users":
            return _j(sr, s.api_save_user_v557(sess, data))
        if path == "/api/payroll/photo":
            eid=data.get("employee_id"); ph=data.get("photo")
            if eid and ph: return _j(sr, s.api_upload_photo(eid, ph, sess))
            return _j(sr, {"ok":False,"error":"Missing fields"}, 400)
        h = routes.get(path)
        if h:
            try:
                return _j(sr, h())
            except Exception as _hexc:
                import traceback as _tb, sys as _hsys
                print('POST handler error %s: %s' % (path, _hexc), file=_hsys.stderr)
                _tb.print_exc(file=_hsys.stderr)
                return _j(sr, {"ok": False, "error": "The request could not be completed. Please check your input and try again; if the problem persists, contact the system administrator."}, 500)
        return _j(sr, {"error":"Not found"}, 404)

    # ── DELETE routes ─────────────────────────────────────────────────────────
    if method == "DELETE":
        rid = path.split("/")[-1]
        if path.startswith("/api/actuals/"):
            return _j(sr, s.api_delete_actual(rid, sess))
        if path.startswith("/api/budget-uploads/"):
            return _j(sr, s.api_delete_budget_upload(rid, sess))
        if path.startswith("/api/quarterly-budgets/"):
            return _j(sr, s.api_delete_quarterly_budget(rid, sess))
        if path.startswith("/api/budgets/"):
            return _j(sr, s.api_delete_budget(rid, sess))
        if path.startswith("/api/commitments/"):
            return _j(sr, s.api_delete_commitment(rid, sess))
        if path.startswith("/api/fuel-vehicles/"):
            return _j(sr, s.api_delete_fuel_vehicle(rid, sess))
        if path.startswith("/api/fuel-coupons/batch/"):
            return _j(sr, s.api_delete_fc_batch(rid, sess))
        if path.startswith("/api/fuel-coupons/movement/"):
            return _j(sr, s.api_delete_fc_movement(rid, sess))
        if path.startswith("/api/coa/"):
            return _j(sr, s.api_delete_coa(rid, sess))
        return _j(sr, {"error":"Not found"}, 404)

    return _j(sr, {"error":"Method not allowed"}, 405)

# ── v565: close any DB connections leaked by a handler at end of each request ──
_v565_inner_app = app
def app(environ, sr):
    try:
        return _v565_inner_app(environ, sr)
    finally:
        try:
            sv()._v565_close_all()
        except Exception:
            pass

# ── Performance middleware: gzip compressible responses + honour If-None-Match ──
#    The SPA shell is ~2.3 MB; gzip cuts it to ~300-400 KB, and ETag/304 means
#    repeat opens transfer almost nothing. Binary downloads (.db, images) are
#    left untouched. Pure-stdlib, no new dependencies.
_PERF_COMPRESSIBLE = ("text/", "application/json", "application/javascript",
                      "application/manifest+json", "application/xml",
                      "image/svg+xml", "text/csv")

def _perf_app(_inner):
    def wrapped(environ, sr):
        accept = environ.get("HTTP_ACCEPT_ENCODING", "") or ""
        want_gzip = "gzip" in accept.lower()
        inm = environ.get("HTTP_IF_NONE_MATCH", "")
        cap = {}
        written = []
        def capture(status, headers, exc_info=None):
            cap["status"] = status; cap["headers"] = headers; cap["exc"] = exc_info
            return written.append
        result = _inner(environ, capture)
        try:
            body = b"".join(written) + b"".join(result)
        finally:
            if hasattr(result, "close"):
                try: result.close()
                except Exception: pass
        status = cap.get("status", "200 OK"); headers = cap.get("headers", []); exc = cap.get("exc")
        hd = {k.lower(): v for k, v in headers}
        etag = hd.get("etag")
        # Conditional GET: unchanged shell/asset -> 304 with no body
        if etag and inm and inm == etag and status.startswith("200"):
            keep = [(k, v) for (k, v) in headers if k.lower() not in ("content-length", "content-encoding")]
            sr("304 Not Modified", keep, exc)
            return [b""]
        ctype = hd.get("content-type", "")
        compressible = any(ctype.startswith(c) for c in _PERF_COMPRESSIBLE)
        if (want_gzip and compressible and "content-encoding" not in hd and len(body) > 860):
            gz = _gzip.compress(body, 6)
            new = [(k, v) for (k, v) in headers if k.lower() not in ("content-length", "content-encoding", "vary")]
            new.append(("Content-Encoding", "gzip"))
            new.append(("Content-Length", str(len(gz))))
            vary = hd.get("vary")
            new.append(("Vary", (vary + ", Accept-Encoding") if vary and "accept-encoding" not in vary.lower() else (vary or "Accept-Encoding")))
            sr(status, new, exc)
            return [gz]
        sr(status, headers, exc)
        return [body]
    return wrapped

app = _perf_app(app)

# ── local dev ─────────────────────────────────────────────────────────────────
if __name__ == "__main__":
    from wsgiref.simple_server import make_server, WSGIServer
    from socketserver import ThreadingMixIn

    class _ThreadingWSGIServer(ThreadingMixIn, WSGIServer):
        """Concurrent local dev server — without threads every browser fetch
        queues behind the previous one and data-heavy views feel broken."""
        daemon_threads = True

    port = int(os.environ.get("PORT", 5000))
    print(f"\n✓ UCC FMS v2 → http://localhost:{port}")
    print("  admin / UCC@2024  |  finance01 / Fin@2024  |  demo / Demo@2024\n")
    with make_server("", port, app, server_class=_ThreadingWSGIServer) as httpd:
        httpd.serve_forever()
