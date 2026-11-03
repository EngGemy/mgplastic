<?php

namespace App\Services\Ocr;

use App\Jobs\ProcessOcrImageJob;
use App\Models\OcrBatch;
use App\Models\OcrImage;
use App\Models\OcrImageLine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OcrBatchService
{
    public function __construct(
        protected InvoiceImagePreprocessor $preprocessor,
    ) {}

    public function findResumableDraft(User $user): ?OcrBatch
    {
        return OcrBatch::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['draft', 'reading', 'review'])
            ->latest('id')
            ->first();
    }

    public function createOrResume(User $user, ?int $distributorId = null): OcrBatch
    {
        $existing = $this->findResumableDraft($user);
        if ($existing) {
            if ($distributorId && ! $existing->distributor_id) {
                $existing->distributor_id = $distributorId;
                $existing->save();
            }

            return $existing;
        }

        return OcrBatch::query()->create([
            'user_id' => $user->id,
            'distributor_id' => $distributorId,
            'status' => 'draft',
        ]);
    }

    /**
     * @return array{ok:bool, image:?OcrImage, duplicate:bool, error:?string}
     */
    public function addUploadedImage(OcrBatch $batch, UploadedFile|string $file, bool $forceDuplicate = false): array
    {
        $max = max(1, (int) config('ocr_resilience.max_images_per_batch', 10));
        if ($batch->images()->whereNull('replaced_by_id')->count() >= $max) {
            return ['ok' => false, 'image' => null, 'duplicate' => false, 'error' => 'الحد الأقصى '.$max.' صور لكل دفعة'];
        }

        if ($file instanceof UploadedFile) {
            $stored = $file->store('ocr-uploads/'.date('Y/m/d'), 'public');
        } else {
            $stored = $file;
        }

        $absolute = Storage::disk('public')->path($stored);
        try {
            $prepared = $this->preprocessor->prepare($absolute);
        } catch (\Throwable $e) {
            return ['ok' => false, 'image' => null, 'duplicate' => false, 'error' => $e->getMessage()];
        }

        $sha = $prepared['sha256'];
        $existing = $batch->images()->where('sha256', $sha)->whereNull('replaced_by_id')->first();
        if ($existing && ! $forceDuplicate) {
            return ['ok' => false, 'image' => $existing, 'duplicate' => true, 'error' => 'already uploaded'];
        }

        $position = (int) $batch->images()->max('position') + 1;
        $relative = $this->relativePublicPath($prepared['path'], $stored);

        $image = OcrImage::query()->create([
            'batch_id' => $batch->id,
            'position' => $position,
            'path' => $relative,
            'sha256' => $sha,
            'width' => $prepared['width'] ?? null,
            'height' => $prepared['height'] ?? null,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $batch->status = 'reading';
        $batch->save();

        $this->dispatchIfCapacity($image);

        return ['ok' => true, 'image' => $image, 'duplicate' => false, 'error' => null];
    }

    public function dispatchIfCapacity(OcrImage $image): void
    {
        $batch = $image->batch;
        $parallel = max(1, (int) config('ocr_resilience.max_parallel_per_batch', 2));
        $active = $batch->images()
            ->whereIn('status', ['reading', 'retrying'])
            ->count();

        if ($active >= $parallel) {
            return;
        }

        if (! in_array($image->status, ['queued', 'retrying'], true) && $image->status !== 'failed') {
            if ($image->status !== 'queued') {
                // allow re-dispatch from failed via retryNow
            }
        }

        ProcessOcrImageJob::dispatch($image->id);
    }

    public function retryNow(OcrImage $image): void
    {
        if ($image->status === 'canceled') {
            return;
        }
        $image->status = 'queued';
        $image->last_error_code = null;
        $image->next_retry_at = null;
        $image->save();
        $this->dispatchIfCapacity($image->fresh());
    }

    public function cancelImage(OcrImage $image): void
    {
        $image->status = 'canceled';
        $image->finished_at = now();
        $image->save();
        $image->batch?->refreshStatusFromImages();
    }

    /**
     * Replace image file; keep approved lines by default onto the new image at version 1.
     *
     * @return array{ok:bool, image:?OcrImage, error:?string}
     */
    public function replaceImage(OcrImage $old, UploadedFile $file, bool $keepApproved = true): array
    {
        $added = $this->addUploadedImage($old->batch, $file, forceDuplicate: true);
        if (! ($added['ok'] ?? false) || ! $added['image']) {
            return ['ok' => false, 'image' => null, 'error' => $added['error'] ?? 'فشل الاستبدال'];
        }

        /** @var OcrImage $new */
        $new = $added['image'];
        $old->replaced_by_id = $new->id;
        $old->status = 'canceled';
        $old->save();

        if ($keepApproved) {
            $approved = $old->lines()
                ->where('review_status', 'approved')
                ->where('version', $old->read_version)
                ->get();
            foreach ($approved as $line) {
                OcrImageLine::query()->create([
                    'image_id' => $new->id,
                    'line_no' => $line->line_no,
                    'raw_fragment' => $line->raw_fragment,
                    'resolved_product_id' => $line->resolved_product_id,
                    'qty' => $line->qty,
                    'review_status' => 'approved',
                    'version' => 0, // preserved until first read bumps; merged in draft builder
                    'payload' => array_merge((array) $line->payload, ['kept_from_replace' => true]),
                ]);
            }
        }

        return ['ok' => true, 'image' => $new, 'error' => null];
    }

    /**
     * Persist pipeline draft lines onto an image (new read version).
     *
     * @param  array<string, mixed>  $draft
     */
    public function persistDraftLines(OcrImage $image, array $draft, bool $keepApproved = true): void
    {
        $newVersion = (int) $image->read_version + 1;
        $approvedByRaw = [];
        if ($keepApproved && $image->read_version > 0) {
            foreach ($image->lines()->where('version', $image->read_version)->where('review_status', 'approved')->get() as $line) {
                $key = mb_strtolower(trim($line->raw_fragment));
                $approvedByRaw[$key] = $line;
            }
        }

        $lineNo = 0;
        foreach ((array) ($draft['lines'] ?? []) as $line) {
            $lineNo++;
            $raw = (string) ($line['raw_text'] ?? $line['name'] ?? '');
            $key = mb_strtolower(trim($raw));
            $kept = $approvedByRaw[$key] ?? null;
            $review = 'needs';
            if ($kept) {
                $review = 'approved';
                unset($approvedByRaw[$key]);
            } elseif (! empty($line['matched_product_id']) && empty($line['needs_review'])) {
                $review = 'ready';
            }

            OcrImageLine::query()->create([
                'image_id' => $image->id,
                'line_no' => $lineNo,
                'raw_fragment' => $raw,
                'resolved_product_id' => $kept?->resolved_product_id ?? ($line['matched_product_id'] ?? null),
                'qty' => $kept?->qty ?? ($line['qty'] ?? null),
                'review_status' => $review,
                'version' => $newVersion,
                'payload' => $line,
            ]);
        }

        foreach ((array) ($draft['excluded'] ?? []) as $ex) {
            $lineNo++;
            OcrImageLine::query()->create([
                'image_id' => $image->id,
                'line_no' => $lineNo,
                'raw_fragment' => (string) ($ex['raw_text'] ?? $ex['name'] ?? ''),
                'resolved_product_id' => null,
                'qty' => $ex['qty'] ?? null,
                'review_status' => 'excluded',
                'version' => $newVersion,
                'payload' => $ex,
            ]);
        }

        // Carry forward approved lines that disappeared from the new read.
        foreach ($approvedByRaw as $kept) {
            $lineNo++;
            OcrImageLine::query()->create([
                'image_id' => $image->id,
                'line_no' => $lineNo,
                'raw_fragment' => $kept->raw_fragment,
                'resolved_product_id' => $kept->resolved_product_id,
                'qty' => $kept->qty,
                'review_status' => 'approved',
                'version' => $newVersion,
                'payload' => array_merge((array) $kept->payload, ['kept_after_reread' => true]),
            ]);
        }

        $image->read_version = $newVersion;
        $image->lines_count = $image->lines()->where('version', $newVersion)->where('review_status', '!=', 'excluded')->count();
        $image->save();
    }

    /**
     * Build POS ocrDraft shape from all settled images in the batch.
     *
     * @return array{draft:?array, merge_suggestions:list<array<string,mixed>>, accounting:array<string,int>}
     */
    public function buildPosDraft(OcrBatch $batch): array
    {
        $batch->load(['images' => fn ($q) => $q->whereNull('replaced_by_id')->orderBy('position')]);
        $lines = [];
        $excluded = [];
        $mergeMap = [];
        $matched = 0;
        $needs = 0;
        $excl = 0;
        $read = 0;

        foreach ($batch->images as $image) {
            if (! in_array($image->status, ['done', 'empty'], true) && $image->read_version < 1) {
                // include approved kept-from-replace (version 0) even while reading
                $rows = $image->lines()->where('version', 0)->where('review_status', 'approved')->get();
            } else {
                $rows = $image->currentLines()->get();
            }

            foreach ($rows as $row) {
                $payload = (array) ($row->payload ?? []);
                $payload['raw_text'] = $row->raw_fragment;
                $payload['qty'] = $row->qty ?? ($payload['qty'] ?? 1);
                $payload['matched_product_id'] = $row->resolved_product_id ?? ($payload['matched_product_id'] ?? null);
                $payload['ocr_image_id'] = $image->id;
                $payload['ocr_image_position'] = $image->position;
                $payload['ocr_line_id'] = $row->id;
                $payload['review_status'] = $row->review_status;
                $payload['approved'] = $row->review_status === 'approved' || $row->review_status === 'added';
                if ($row->review_status === 'added') {
                    $payload['added'] = true;
                }

                $read++;
                if ($row->review_status === 'excluded') {
                    $excl++;
                    $excluded[] = $payload;

                    continue;
                }
                if ($row->review_status === 'needs' || ! empty($payload['needs_review'])) {
                    $needs++;
                } else {
                    $matched++;
                }

                $lines[] = $payload;

                $pid = (int) ($payload['matched_product_id'] ?? 0);
                $size = (string) data_get($payload, 'slots.size_value', data_get($payload, 'slots.size', ''));
                if ($pid > 0) {
                    $mk = $pid.'|'.$size;
                    $mergeMap[$mk]['product_id'] = $pid;
                    $mergeMap[$mk]['size'] = $size;
                    $mergeMap[$mk]['qtys'][] = (float) ($payload['qty'] ?? 0);
                    $mergeMap[$mk]['line_indexes'][] = count($lines) - 1;
                    $mergeMap[$mk]['image_ids'][] = $image->id;
                }
            }
        }

        $suggestions = [];
        foreach ($mergeMap as $group) {
            $uniqueImages = array_unique($group['image_ids'] ?? []);
            if (count($uniqueImages) < 2 && count($group['line_indexes'] ?? []) < 2) {
                continue;
            }
            if (count($group['line_indexes'] ?? []) < 2) {
                continue;
            }
            $suggestions[] = [
                'product_id' => $group['product_id'],
                'size' => $group['size'],
                'qtys' => $group['qtys'],
                'sum' => array_sum($group['qtys']),
                'line_indexes' => $group['line_indexes'],
                'message' => 'نفس المنتج في أكثر من صورة — ادمج (مجموع '.array_sum($group['qtys']).') أو أبقِ منفصلاً',
            ];
        }

        if ($lines === [] && $excluded === []) {
            return [
                'draft' => null,
                'merge_suggestions' => [],
                'accounting' => [
                    'matched' => 0,
                    'needs_review' => 0,
                    'excluded' => 0,
                    'lines_read' => 0,
                ],
            ];
        }

        return [
            'draft' => [
                'lines' => $lines,
                'excluded' => $excluded,
                'source_type' => 'batch',
                'merge_suggestions' => $suggestions,
                'ocr_batch_id' => $batch->id,
            ],
            'merge_suggestions' => $suggestions,
            'accounting' => [
                'matched' => $matched,
                'needs_review' => $needs,
                'excluded' => $excl,
                'lines_read' => $read,
            ],
        ];
    }

    /**
     * Paste-text fallback: one synthetic "image" line set via segmentation/matching.
     */
    public function addPasteText(OcrBatch $batch, string $text): OcrImage
    {
        $sha = hash('sha256', 'paste:'.Str::lower(trim($text)));
        $path = 'ocr-uploads/'.date('Y/m/d').'/paste_'.$batch->id.'_'.Str::random(8).'.txt';
        Storage::disk('public')->put($path, $text);

        $image = OcrImage::query()->create([
            'batch_id' => $batch->id,
            'position' => (int) $batch->images()->max('position') + 1,
            'path' => $path,
            'sha256' => $sha.substr((string) microtime(true), -4), // allow multiple pastes
            'status' => 'queued',
            'attempts' => 0,
        ]);

        // Process paste synchronously via matcher-only path in the job (text file).
        ProcessOcrImageJob::dispatch($image->id, isPasteText: true);

        return $image;
    }

    public function markLinesAdded(OcrBatch $batch, array $ocrLineIds): void
    {
        OcrImageLine::query()
            ->whereIn('id', $ocrLineIds)
            ->update(['review_status' => 'added']);
        $batch->status = 'added';
        $batch->cart_added_at = now();
        $batch->save();
    }

    /**
     * Accounting identity: matched + needs_review + excluded === lines_read (per image / batch).
     *
     * @return array{ok:bool, per_image:list<array<string,int|bool>>, batch:array<string,int|bool>}
     */
    public function accountingIdentity(OcrBatch $batch): array
    {
        $perImage = [];
        $totals = ['matched' => 0, 'needs_review' => 0, 'excluded' => 0, 'lines_read' => 0];

        foreach ($batch->images()->whereNull('replaced_by_id')->get() as $image) {
            if ($image->read_version < 1) {
                continue;
            }
            $rows = $image->currentLines()->get();
            $m = $n = $e = 0;
            foreach ($rows as $row) {
                if ($row->review_status === 'excluded') {
                    $e++;
                } elseif ($row->review_status === 'needs') {
                    $n++;
                } else {
                    $m++;
                }
            }
            $read = $m + $n + $e;
            $ok = $read === ($m + $n + $e);
            $perImage[] = [
                'image_id' => $image->id,
                'matched' => $m,
                'needs_review' => $n,
                'excluded' => $e,
                'lines_read' => $read,
                'ok' => $ok,
            ];
            $totals['matched'] += $m;
            $totals['needs_review'] += $n;
            $totals['excluded'] += $e;
            $totals['lines_read'] += $read;
        }

        $totals['ok'] = ($totals['matched'] + $totals['needs_review'] + $totals['excluded']) === $totals['lines_read'];

        return ['ok' => (bool) $totals['ok'], 'per_image' => $perImage, 'batch' => $totals];
    }

    protected function relativePublicPath(string $preparedAbsolute, string $fallbackRelative): string
    {
        $publicRoot = rtrim(Storage::disk('public')->path(''), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($preparedAbsolute, $publicRoot)) {
            return str_replace('\\', '/', substr($preparedAbsolute, strlen($publicRoot)));
        }

        return $fallbackRelative;
    }
}
