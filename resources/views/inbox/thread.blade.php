@extends('layout')
@section('content')
<p class="muted"><a href="/inbox">All contacts</a></p>
<h2 style="margin:0">{{ $contact->display_name() }} <span class="muted">+{{ $contact->phone }}</span></h2>
@if (session('result'))
  <div class="ok">{{ session('result') }}</div>
@endif
<p class="muted">
  {{ $contact->isOptedOut() ? 'OPTED OUT (' . $contact->opt_out_source . ' ' . $contact->opted_out_at?->toDayDateTimeString() . ')' : ($contact->windowOpen() ? 'reply window open' : 'reply window closed - approved template required') }}
</p>

<div style="background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px;margin:14px 0">
@forelse ($messages as $m)
  <div class="bubble {{ $m->direction === 'in' ? 'in' : 'out' }}">
    @if ($m->direction === 'out')
      <div class="muted" style="font-size:12px">
        {{ $m->sent_at->toDayDateTimeString() }}
        @if ($m->type === 'template') &middot; template {{ $m->template_name }} ({{ $m->template_lang }}) @endif
        @if ($m->status) &middot; {{ $m->status }} @endif
      </div>
    @endif
    {{ $m->body ?? '(' . $m->type . ' message - text not stored here)' }}
    @if ($m->status === 'failed')
      <div class="err">FAILED {{ $m->error_code }}: {{ $m->error_title }}</div>
    @endif
    @if ($m->auto_reply)
      <div class="auto">automatic</div>
    @endif
    @if ($m->direction === 'in')
      <div class="muted" style="font-size:12px">{{ $m->sent_at->toDayDateTimeString() }}</div>
    @endif
  </div>
@empty
  <p class="muted">Nothing here yet.</p>
@endforelse
</div>

<form method="POST" action="/inbox/{{ $contact->id }}/reply">
  @csrf
  <label class="muted">Reply (free-form, needs an open window)</label>
  <textarea name="body">{{ old('body') }}</textarea>
  @error('body')<div class="err">{{ $message }}</div>@enderror
  @foreach ($errors->get('window') as $e) <div class="err">{{ $e }}</div> @endforeach
  <div style="margin-top:8px"><button type="submit">Send reply</button></div>
</form>

<form method="POST" action="/inbox/{{ $contact->id }}/template" style="margin-top:18px">
  @csrf
  <label class="muted">Approved template (works outside the window)</label>
  <div>
    <select name="template">
      @foreach ($choices as $choice) <option value="{{ $choice }}">{{ $choice }}</option> @endforeach
    </select>
    <input name="language" value="en" size="6" placeholder="lang">
    <input name="variables" value="{{ $contact->profile_name }}" size="24" placeholder="body variables, comma separated">
  </div>
  @foreach ($errors->get('template') as $e) <div class="err">{{ $e }}</div> @endforeach
  <div style="margin-top:8px"><button type="submit">Send template</button></div>
</form>

<form method="POST" action="/inbox/{{ $contact->id }}/opt-out" style="margin-top:18px">
  @csrf
  <button type="submit" style="background:#9aa4b2">Mark opted out</button>
  <span class="muted">stops every automatic message to this number</span>
</form>
@endsection
