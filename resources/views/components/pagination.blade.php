@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="btn btn-secondary btn-sm" aria-disabled="true">Précédent</span>
        @else
            <a class="btn btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Précédent</a>
        @endif
        <span class="muted small">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a class="btn btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Suivant</a>
        @else
            <span class="btn btn-secondary btn-sm" aria-disabled="true">Suivant</span>
        @endif
    </nav>
@endif
