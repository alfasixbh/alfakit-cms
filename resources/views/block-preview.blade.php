{{-- A compact summary of a block in the page editor (`CmsPlugin::blockPreviews()`). --}}
<div class="flex items-center gap-3 rounded-md border border-dashed px-3 py-2 text-sm" data-slot="cms-block-preview">
    <span class="font-medium">{{ $label }}</span>
    @if ($summary)
        <span class="text-muted-foreground truncate">{{ $summary }}</span>
    @endif
    @if ($hidden)
        <span class="text-muted-foreground ms-auto text-xs">{{ __('alfakit-cms::cms.fields.hidden_badge') }}</span>
    @endif
</div>
