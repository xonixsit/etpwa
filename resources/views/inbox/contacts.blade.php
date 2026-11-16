@extends('layout')
@section('content')
<form method="GET" action="/inbox" style="margin-bottom:14px">
  <input name="q" value="{{ $q }}" placeholder="name or number" style="width:320px">
  <button type="submit">Search</button>
</form>

<table>
  <thead><tr><th>Contact</th><th>Number</th><th>Last inbound</th><th>Msgs</th><th>State</th></tr></thead>
  <tbody>
  @forelse ($contacts as $c)
    <tr>
      <td><a href="/inbox/{{ $c->id }}">{{ $c->display_name() }}</a></td>
      <td>+{{ $c->phone }}</td>
      <td>{{ $c->last_inbound_at ? $c->last_inbound_at->diffForHumans() : 'never replied' }}</td>
      <td>{{ $c->inbound_count }} in / {{ $c->outbound_count }} out</td>
      <td>
        @if ($c->isOptedOut())
          <span class="badge">opted out</span>
        @elseif ($c->windowOpen())
          <span class="badge" style="background:#e6f0ff;color:#1830B8">window open</span>
        @else
          <span class="muted">template only</span>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted">No contacts yet. Inbound messages appear here as soon as the webhook is subscribed.</td></tr>
  @endforelse
  </tbody>
</table>
<div class="muted">{{ $contacts->links('pagination::etp') }}</div>
@endsection
