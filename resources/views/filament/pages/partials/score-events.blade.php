<div class="space-y-3 text-sm" dir="rtl">
    @if(!empty($components))
        <div class="grid grid-cols-2 gap-2 mb-4">
            @foreach($components as $k => $v)
                <div class="border border-gray-200 p-3">
                    <div class="text-xs text-gray-500">{{ $k }}</div>
                    <div class="text-2xl font-bold">{{ $v }}</div>
                </div>
            @endforeach
        </div>
    @endif
    <ul class="divide-y divide-gray-100">
        @forelse($events as $e)
            <li class="py-2 flex justify-between gap-4">
                <span>{{ $e->type }} · Δ {{ $e->points_delta }}</span>
                <span class="text-gray-500">{{ $e->created_at }}</span>
            </li>
        @empty
            <li>لا أحداث بعد</li>
        @endforelse
    </ul>
</div>
