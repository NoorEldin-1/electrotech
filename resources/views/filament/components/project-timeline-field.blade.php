{{-- الخط الزمني داخل ملف العملية (Presentation.pptx سلايد 1) — a Forms View field. --}}
@php($record = $getRecord())
@if ($record)
    @php($service = app(\App\Services\OperationTimelineService::class))
    @php($timeline = $service->for($record))
    @include('filament.components.operation-timeline', [
        'timeline' => $timeline,
        'summary' => $service->summary($record, $timeline),
    ])
@endif
