<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'E Tax Planner inbox' }}</title>
<style>
:root { --ink:#111827; --grey:#566174; --line:#dfe4ec; --blue:#1830B8; --warn:#F07030; }
* { box-sizing:border-box; }
body { font:15px/1.5 -apple-system,"Segoe UI",Arial,sans-serif; color:var(--ink); margin:0; background:#f7f8fb; }
header { background:var(--blue); color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
header a { color:#fff; }
main { max-width:1080px; margin:0 auto; padding:20px; }
table { width:100%; border-collapse:collapse; background:#fff; }
th,td { text-align:left; padding:9px 12px; border-bottom:1px solid var(--line); vertical-align:top; }
th { font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:var(--grey); background:#f2f4f9; }
.bubble { max-width:70%; padding:9px 13px; border-radius:14px; margin:6px 0; }
.in { background:#fff; border:1px solid var(--line); }
.out { background:#e6f0ff; margin-left:auto; }
.auto { font-size:11px; color:var(--warn); text-transform:uppercase; letter-spacing:.06em; }
.badge { font-size:11px; padding:2px 7px; border-radius:10px; background:#ffe9dc; color:#9a4a10; }
input,textarea,select,button { font:inherit; padding:8px 10px; border:1px solid var(--line); border-radius:7px; }
textarea { width:100%; min-height:84px; }
button { background:var(--blue); color:#fff; border:0; cursor:pointer; }
.muted { color:var(--grey); font-size:13px; }
.err { color:#b00020; font-size:13px; }
.ok { color:#0a6b3b; font-size:13px; }
</style>
</head>
<body>
<header>
  <strong>E Tax Planner &mdash; WhatsApp inbox</strong>
  @auth
    <form method="POST" action="/logout" style="display:inline">
      @csrf
      <button type="submit" style="background:transparent;color:#fff;border:0;cursor:pointer">Sign out</button>
    </form>
  @endauth
</header>
<main>
@yield('content')
</main>
</body>
</html>
