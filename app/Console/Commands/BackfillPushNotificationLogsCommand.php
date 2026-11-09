<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use Illuminate\Console\Command;

class BackfillPushNotificationLogsCommand extends Command
{
    protected $signature = 'notifications:backfill-push-logs
                            {--apply : Persist changes (default is dry-run)}
                            {--older-than-hours=24 : Expire orphan queued rows older than this}';

    protected $description = 'Clean orphan notification_logs queued rows left after FCM finals';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $olderHours = max(1, (int) $this->option('older-than-hours'));
        $cutoff = now()->subHours($olderHours);

        $beforeQueued = NotificationLog::query()->where('status', NotificationLog::STATUS_QUEUED)->count();
        $beforeFinal = NotificationLog::query()->whereIn('status', NotificationLog::finalStatuses())->count();
        $noTokenFailed = NotificationLog::query()
            ->where('status', NotificationLog::STATUS_FAILED)
            ->where(function ($q) {
                $q->where('error', 'like', '%No active device tokens%')
                    ->orWhere('error', 'like', '%no device tokens%');
            })
            ->count();

        $this->info(($apply ? 'APPLY' : 'DRY-RUN')." — queued={$beforeQueued}, finals={$beforeFinal}, failed_no_token={$noTokenFailed}");

        $deleteIds = [];
        $expireIds = [];

        NotificationLog::query()
            ->where('status', NotificationLog::STATUS_QUEUED)
            ->orderBy('id')
            ->each(function (NotificationLog $row) use ($cutoff, &$deleteIds, &$expireIds) {
                if ($this->findSiblingFinal($row)) {
                    $deleteIds[] = $row->id;

                    return;
                }

                if ($row->created_at && $row->created_at->lte($cutoff)) {
                    $expireIds[] = $row->id;
                }
            });

        $withSibling = count($deleteIds);
        $expired = count($expireIds);

        if ($apply) {
            if ($deleteIds !== []) {
                NotificationLog::query()->whereIn('id', $deleteIds)->delete();
            }
            if ($expireIds !== []) {
                NotificationLog::query()->whereIn('id', $expireIds)->update([
                    'status' => NotificationLog::STATUS_EXPIRED,
                    'error' => 'orphan_queued_backfill',
                    'updated_at' => now(),
                ]);
            }
            if ($noTokenFailed > 0) {
                NotificationLog::query()
                    ->where('status', NotificationLog::STATUS_FAILED)
                    ->where(function ($q) {
                        $q->where('error', 'like', '%No active device tokens%')
                            ->orWhere('error', 'like', '%no device tokens%');
                    })
                    ->update([
                        'status' => NotificationLog::STATUS_SKIPPED_NO_TOKEN,
                        'updated_at' => now(),
                    ]);
            }
        }

        $afterQueued = NotificationLog::query()->where('status', NotificationLog::STATUS_QUEUED)->count();
        $afterExpired = NotificationLog::query()->where('status', NotificationLog::STATUS_EXPIRED)->count();
        $afterSkipped = NotificationLog::query()->where('status', NotificationLog::STATUS_SKIPPED_NO_TOKEN)->count();

        $this->table(
            ['metric', 'count'],
            [
                ['queued_with_sibling_final', $withSibling],
                ['orphan_queued_to_expire', $expired],
                ['failed_to_skipped_no_token', $noTokenFailed],
                ['queued_before', $beforeQueued],
                ['queued_after'.($apply ? '' : ' (unchanged in dry-run)'), $apply ? $afterQueued : $beforeQueued],
                ['expired_total', $afterExpired],
                ['skipped_no_token_total', $afterSkipped],
            ]
        );

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to write changes.');
        } else {
            $this->info('Backfill applied.');
        }

        return self::SUCCESS;
    }

    protected function findSiblingFinal(NotificationLog $row): ?NotificationLog
    {
        $query = NotificationLog::query()
            ->where('notification_id', $row->notification_id)
            ->where('id', '!=', $row->id)
            ->whereIn('status', NotificationLog::finalStatuses());

        if ($row->device_token_id !== null) {
            $query->where('device_token_id', $row->device_token_id);
        }

        return $query->orderByDesc('id')->first();
    }
}
