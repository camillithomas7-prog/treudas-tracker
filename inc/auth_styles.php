<style>
:root{
  --bg:#0b0e18; --bg-card:#10131f; --bg-card-2:#161b2e; --border:#26304a;
  --text:#e7ecf5; --muted:#9aa4bd; --accent:#f59e0b; --accent-2:#fbbf24;
  --red:#ef4444; --green:#22c55e;
}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:
  radial-gradient(1000px 500px at 50% -10%, rgba(245,158,11,.10), transparent 60%),
  var(--bg);color:var(--text);
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,system-ui,sans-serif;
  display:flex;align-items:center;justify-content:center;padding:24px;}
.authwrap{width:100%;max-width:400px}
.authcard{background:var(--bg-card);border:1px solid var(--border);border-radius:18px;
  padding:34px 30px;box-shadow:0 24px 60px rgba(0,0,0,.5)}
.authlogo{display:flex;align-items:center;gap:8px;font-size:20px;margin-bottom:20px;opacity:.9}
.authlogo span{font-weight:800;letter-spacing:.3px}
h1{margin:0 0 6px;font-size:26px;font-weight:800}
.sub{margin:0 0 22px;color:var(--muted);font-size:14.5px;line-height:1.5}
label{display:block;font-size:13px;font-weight:700;color:var(--muted);margin-bottom:14px}
input{display:block;width:100%;margin-top:7px;padding:12px 13px;border-radius:10px;
  background:var(--bg-card-2);border:1px solid var(--border);color:var(--text);
  font-size:15px;outline:none;transition:.15s}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(245,158,11,.15)}
button{width:100%;margin-top:6px;padding:13px;border:none;border-radius:10px;cursor:pointer;
  background:linear-gradient(180deg,var(--accent-2),var(--accent));color:#1a1200;
  font-size:15.5px;font-weight:800;letter-spacing:.2px;transition:.15s}
button:hover{filter:brightness(1.05)}
.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#fca5a5;
  padding:11px 13px;border-radius:10px;font-size:14px;margin-bottom:18px}
.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.4);color:#86efac;
  padding:11px 13px;border-radius:10px;font-size:14px;margin-bottom:18px}
.alt{margin:20px 0 0;text-align:center;color:var(--muted);font-size:14px}
.alt a{color:var(--accent-2);text-decoration:none;font-weight:700}
.alt a:hover{text-decoration:underline}
.hint{color:var(--muted);font-size:12.5px;margin-top:-6px;margin-bottom:14px}
</style>
