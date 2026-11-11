@extends('layout')
@section('content')
<div style="max-width:380px;margin:60px auto;background:#fff;padding:24px;border:1px solid var(--line);border-radius:10px">
  <h2 style="margin:0 0 4px">E Tax Planner inbox</h2>
  <p class="muted" style="margin:0 0 18px">Staff sign in</p>
  <form method="POST" action="/login">
    @csrf
    <label class="muted">Email</label>
    <input name="email" type="email" value="{{ old('email') }}" style="width:100%;margin:4px 0 14px">
    @error('email')<div class="err">{{ $message }}</div>@enderror
    <label class="muted">Password</label>
    <input name="password" type="password" style="width:100%;margin:4px 0 14px">
    <label style="font-size:13px"><input type="checkbox" name="remember" value="1"> Stay signed in</label>
    <div style="margin-top:16px"><button type="submit">Sign in</button></div>
  </form>
</div>
@endsection
