@php
    /** @var \App\Models\PlumberWorkPhoto $record */
@endphp

<div style="direction:rtl;text-align:center">
    @if($record->is_video && $record->url)
        <video src="{{ $record->url }}" controls playsinline
               style="width:100%;max-height:70vh;border-radius:12px;background:#000"></video>
        @if($record->thumbnail_url)
            <div style="font-size:12px;color:#6b7280;margin-top:8px">الغلاف التلقائي مُنشأ من الفيديو</div>
        @endif
    @elseif($record->url)
        <img src="{{ $record->url }}" alt="عمل السباك"
             style="width:100%;max-height:70vh;object-fit:contain;border-radius:12px;background:#0f172a">
    @else
        <div style="padding:32px;color:#6b7280">لا يوجد ملف متاح لعرضه.</div>
    @endif
</div>
