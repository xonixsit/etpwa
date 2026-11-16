@if ($paginator->hasPages())
  <div style="display:flex;gap:14px;align-items:center">
    @if ($paginator->onFirstPage())
      <span class="muted">Previous</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}">Previous</a>
    @endif
    <span class="muted">Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}">Next</a>
    @else
      <span class="muted">Next</span>
    @endif
  </div>
@endif
